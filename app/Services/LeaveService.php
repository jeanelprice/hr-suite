<?php

namespace App\Services;

use App\Enums\ContractStatus;
use App\Enums\EmployeeStatus;
use App\Enums\LeaveApprover;
use App\Enums\LeaveLedgerKind;
use App\Enums\LeaveStatus;
use App\Enums\ProbationOutcome;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\Employee;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\HrNotification;
use App\Support\PersonnelAccess;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Congés et absences (RG-23 ; Manuel §3.2 et §5.10 ; Politique RH §5.3).
 *
 * - Le congé annuel s'acquiert à 1,5 jour ouvrable par mois de service, plus 1 jour par
 *   tranche de 5 ans d'ancienneté, dans la limite de 24 jours par année contractuelle.
 * - L'année de congé est l'année contractuelle (date anniversaire d'entrée en fonction) ;
 *   le solde non pris expire à sa fin.
 * - Le solde se calcule à partir d'un registre en ajout seul (comme le grand livre).
 * - Circuit : l'employé soumet, le supérieur valide, puis le RH (ou la DE selon le type) approuve.
 */
class LeaveService
{
    public function __construct(
        private NotificationService $notifications,
        private NumberingService $numbering,
    ) {}

    // ------------------------------------------------------------------ calendrier

    /** Jours fériés entre deux dates, indexés par date (Y-m-d) */
    public function holidays(string $from, string $to): array
    {
        return PublicHoliday::query()->whereBetween('date', [$from, $to])->get()
            ->mapWithKeys(fn (PublicHoliday $h) => [$h->date->toDateString() => $h->label])->all();
    }

    /** Jours décomptés : jours ouvrables (hors samedi, dimanche et fériés) ou jours calendaires */
    public function countDays(string $start, string $end, bool $startHalf = false, bool $endHalf = false, bool $calendar = false): float
    {
        $from = Carbon::parse($start)->startOfDay();
        $to = Carbon::parse($end)->startOfDay();
        if ($to->lt($from)) {
            return 0;
        }
        $holidays = $calendar ? [] : $this->holidays($from->toDateString(), $to->toDateString());
        $counted = fn (Carbon $d) => $calendar || (! $d->isWeekend() && ! isset($holidays[$d->toDateString()]));

        $days = 0.0;
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $days += $counted($d) ? 1 : 0;
        }
        if ($startHalf && $counted($from)) {
            $days -= 0.5;
        }
        if ($endHalf && $counted($to) && ! ($from->equalTo($to) && $startHalf)) {
            $days -= 0.5;
        }

        return max(0, $days);
    }

    // ------------------------------------------------------------------ année contractuelle

    public function yearIndex(Employee $e, CarbonInterface|string|null $date = null): int
    {
        $date = Carbon::parse($date ?? today());

        return $date->lt($e->hired_at) ? 0 : (int) $e->hired_at->diffInYears($date);
    }

    /** @return array{0: Carbon, 1: Carbon} début et fin (incluse) de l'année contractuelle */
    public function yearRange(Employee $e, int $year): array
    {
        $start = $e->hired_at->copy()->addYears($year);

        return [$start, $start->copy()->addYear()->subDay()];
    }

    /** Droit annuel complet d'une année : 12 × 1,5 jour + ancienneté, plafonné */
    public function entitlement(int $year): float
    {
        return 12 * Setting::decimal('CONGE_JOURS_PAR_MOIS') + $this->seniorityBonus($year);
    }

    public function seniorityBonus(int $year): float
    {
        $base = 12 * Setting::decimal('CONGE_JOURS_PAR_MOIS');
        $bonus = intdiv($year, max(1, Setting::number('CONGE_ANCIENNETE_TRANCHE_ANS'))) * Setting::decimal('CONGE_ANCIENNETE_JOURS');

        return max(0, min($bonus, Setting::decimal('CONGE_PLAFOND_ANNUEL') - $base));
    }

    // ------------------------------------------------------------------ acquisition

    /**
     * Inscrit au registre les mois de service accomplis, les jours d'ancienneté et
     * l'expiration des années passées. Sans effet si déjà fait (références uniques).
     */
    public function accrue(Employee $e, CarbonInterface|string|null $asOf = null): void
    {
        $asOf = Carbon::parse($asOf ?? today())->startOfDay();
        if ($e->exitRecord) {
            $asOf = $asOf->min($e->exitRecord->exit_date);
        }
        if ($e->hired_at->gt($asOf)) {
            return;
        }
        $rate = Setting::decimal('CONGE_JOURS_PAR_MOIS');
        $existing = LeaveLedgerEntry::query()->where('employee_id', $e->id)->where('balance', 'ANNUEL')
            ->whereNotNull('reference')->pluck('reference')->flip();
        $rows = [];
        $months = (int) $e->hired_at->diffInMonths($asOf);
        for ($m = 1; $m <= $months; $m++) {
            if (! isset($existing["M{$m}"])) {
                $rows[] = ['kind' => LeaveLedgerKind::ACQUISITION, 'days' => $rate, 'leave_year' => intdiv($m - 1, 12), 'reference' => "M{$m}",
                    'effective_date' => $e->hired_at->copy()->addMonths($m)->toDateString(), 'note' => "{$m}e mois de service"];
            }
        }
        $current = $this->yearIndex($e, $asOf);
        for ($y = 1; $y <= $current; $y++) {
            $bonus = $this->seniorityBonus($y);
            if ($bonus > 0 && ! isset($existing["A{$y}"])) {
                $rows[] = ['kind' => LeaveLedgerKind::ANCIENNETE, 'days' => $bonus, 'leave_year' => $y, 'reference' => "A{$y}",
                    'effective_date' => $this->yearRange($e, $y)[0]->toDateString(), 'note' => "{$y} an(s) d'ancienneté"];
            }
        }
        foreach ($rows as $row) {
            LeaveLedgerEntry::query()->create($row + ['employee_id' => $e->id, 'balance' => 'ANNUEL']);
        }
        // Expiration des années contractuelles terminées (Manuel §5.10.1)
        for ($y = 0; $y < $current; $y++) {
            if (isset($existing["E{$y}"])) {
                continue;
            }
            $left = (float) LeaveLedgerEntry::query()->where('employee_id', $e->id)->where('balance', 'ANNUEL')->where('leave_year', $y)->sum('days');
            if ($left > 0) {
                LeaveLedgerEntry::query()->create([
                    'employee_id' => $e->id, 'balance' => 'ANNUEL', 'leave_year' => $y, 'kind' => LeaveLedgerKind::EXPIRATION,
                    'days' => -$left, 'reference' => "E{$y}", 'effective_date' => $this->yearRange($e, $y)[1]->copy()->addDay()->toDateString(),
                    'note' => 'Solde non pris en fin d\'année contractuelle',
                ]);
            }
        }
    }

    // ------------------------------------------------------------------ soldes

    /**
     * Solde d'une année contractuelle (congé annuel) ou solde de récupération.
     *
     * @return array{year:?int, from:?Carbon, to:?Carbon, acquired:float, taken:float, adjusted:float, expired:float, available:float, pending:float, entitlement:?float, advance_limit:float}
     */
    public function balance(Employee $e, string $key = 'ANNUEL', ?int $year = null): array
    {
        $this->accrue($e);
        $q = LeaveLedgerEntry::query()->where('employee_id', $e->id)->where('balance', $key);
        $range = [null, null];
        if ($key === 'ANNUEL') {
            $year ??= $this->yearIndex($e);
            $q->where('leave_year', $year);
            $range = $this->yearRange($e, $year);
        }
        $entries = $q->get();
        $sum = fn (array $kinds) => (float) $entries->filter(fn ($l) => in_array($l->kind, $kinds, true))->sum('days');

        $pending = (float) LeaveRequest::query()->where('employee_id', $e->id)
            ->whereIn('status', [LeaveStatus::SOUMISE->value, LeaveStatus::VALIDEE_SUPERIEUR->value])
            ->whereHas('type', fn ($t) => $key === 'ANNUEL' ? $t->where('deducts_balance', true) : $t->where('own_balance', true))
            ->when($key === 'ANNUEL', fn ($r) => $r->where('leave_year', $year))
            ->sum('days');
        $entitlement = $key === 'ANNUEL' ? $this->entitlement($year) : null;

        return [
            'year' => $key === 'ANNUEL' ? $year : null,
            'from' => $range[0],
            'to' => $range[1],
            'acquired' => $sum([LeaveLedgerKind::ACQUISITION, LeaveLedgerKind::ANCIENNETE, LeaveLedgerKind::RECUPERATION]),
            'taken' => -$sum([LeaveLedgerKind::PRISE, LeaveLedgerKind::ANNULATION]),
            'adjusted' => $sum([LeaveLedgerKind::AJUSTEMENT]),
            'expired' => -$sum([LeaveLedgerKind::EXPIRATION]),
            'available' => (float) $entries->sum('days'),
            'pending' => $pending,
            'entitlement' => $entitlement,
            'advance_limit' => $entitlement ? $entitlement * Setting::decimal('CONGE_AVANCE_PCT') / 100 : 0,
        ];
    }

    // ------------------------------------------------------------------ circuit

    /** Supérieur qui valide : le supérieur direct ; à défaut, le RH ; pour la DE, la DE adjointe */
    public function supervisorApprovers(LeaveRequest $r): Collection
    {
        $e = $r->employee;
        $sup = $e->supervisor?->user;
        if ($sup && $sup->is_active && $sup->id !== $e->user_id) {
            return collect([$sup]);
        }
        if (! $e->supervisor_id && $e->user?->role === Role::DIRECTION_EXECUTIVE) {
            return User::query()->where('role', Role::DIRECTION_EXECUTIVE_ADJOINTE->value)->where('is_active', true)->get();
        }

        return $this->hrApprovers($e);
    }

    /** Approbateurs finaux : RH (module RH en création) ou Direction Exécutive selon le type */
    public function finalApprovers(LeaveRequest $r): Collection
    {
        $e = $r->employee;
        $list = $r->type->final_approver === LeaveApprover::DE
            ? User::query()->where('role', Role::DIRECTION_EXECUTIVE->value)->where('is_active', true)->get()
            : $this->hrApprovers($e);
        $list = $list->reject(fn (User $u) => $u->id === $e->user_id)->values();
        if ($list->isEmpty()) { // la DE elle-même : la DE adjointe approuve
            $list = User::query()->whereIn('role', [Role::DIRECTION_EXECUTIVE->value, Role::DIRECTION_EXECUTIVE_ADJOINTE->value])
                ->where('is_active', true)->get()->reject(fn (User $u) => $u->id === $e->user_id)->values();
        }

        return $list;
    }

    private function hrApprovers(Employee $e): Collection
    {
        return User::query()->where('is_active', true)->get()
            ->filter(fn (User $u) => PersonnelAccess::canManage($u) && $u->id !== $e->user_id)->values();
    }

    public function canSupervise(?User $u, LeaveRequest $r): bool
    {
        return $u && $r->status === LeaveStatus::SOUMISE && $this->supervisorApprovers($r)->contains('id', $u->id);
    }

    public function canApprove(?User $u, LeaveRequest $r): bool
    {
        return $u && $r->status === LeaveStatus::VALIDEE_SUPERIEUR && $this->finalApprovers($r)->contains('id', $u->id);
    }

    public function canCancel(?User $u, LeaveRequest $r): bool
    {
        if (! $u || ! in_array($r->status, [LeaveStatus::SOUMISE, LeaveStatus::VALIDEE_SUPERIEUR, LeaveStatus::APPROUVEE], true)) {
            return false;
        }
        if (PersonnelAccess::canManage($u)) {
            return true;
        }

        return $r->employee->user_id === $u->id && ($r->status !== LeaveStatus::APPROUVEE || $r->start_date->isFuture());
    }

    /** Demandes qui attendent une décision de cet utilisateur */
    public function awaiting(User $u): Collection
    {
        return LeaveRequest::query()->with('employee.supervisor.user', 'employee.user', 'type')
            ->whereIn('status', [LeaveStatus::SOUMISE->value, LeaveStatus::VALIDEE_SUPERIEUR->value])
            ->orderBy('start_date')->get()
            ->filter(fn (LeaveRequest $r) => $this->canSupervise($u, $r) || $this->canApprove($u, $r))->values();
    }

    // ------------------------------------------------------------------ demande

    /**
     * Demande de congé : par l'employé lui-même (types en libre-service) ou par le RH.
     *
     * @param  array{start_date?:string, end_date?:string, start_half?:bool, end_half?:bool, expected_birth_date?:string, interim_employee_id?:int, reason?:string}  $data
     */
    public function submit(User $actor, Employee $e, LeaveType $type, array $data): LeaveRequest
    {
        $self = $e->user_id === $actor->id;
        if (! ($self && $type->self_service) && ! PersonnelAccess::canManage($actor)) {
            throw new BusinessRuleException("Vous ne pouvez pas enregistrer ce congé pour cet employé.", 'HABILITATION');
        }
        if ($e->status === EmployeeStatus::SORTI) {
            throw new BusinessRuleException('Cet employé a quitté l\'organisation.', 'RG-23');
        }
        if (! $type->is_active) {
            throw new BusinessRuleException('Ce type de congé n\'est plus proposé.', 'RG-23');
        }
        if ($type->gender && $e->gender?->value !== $type->gender) {
            throw new BusinessRuleException("Le {$type->label} ne concerne pas cet employé.", 'RG-23');
        }

        // Maternité : 14 semaines, dont 6 avant la date prévue de l'accouchement
        if ($type->code === 'MATERNITE') {
            if (empty($data['expected_birth_date'])) {
                throw new BusinessRuleException("Indiquez la date prévue de l'accouchement.", 'RG-23');
            }
            $start = Carbon::parse($data['expected_birth_date'])->subWeeks(6);
            $data['start_date'] = $start->toDateString();
            $data['end_date'] = $start->copy()->addDays((int) $type->max_days - 1)->toDateString();
            $data['start_half'] = $data['end_half'] = false;
        }
        if (empty($data['start_date']) || empty($data['end_date'])) {
            throw new BusinessRuleException('Indiquez les dates du congé.', 'RG-23');
        }
        $start = Carbon::parse($data['start_date'])->toDateString();
        $end = Carbon::parse($data['end_date'])->toDateString();
        if ($end < $start) {
            throw new BusinessRuleException('La date de fin précède la date de début.', 'RG-23');
        }
        $days = $this->countDays($start, $end, (bool) ($data['start_half'] ?? false), (bool) ($data['end_half'] ?? false), $type->calendar_days);
        if ($days <= 0) {
            throw new BusinessRuleException('La période ne contient aucun jour ouvrable (week-end ou jour férié).', 'RG-23');
        }
        if ($type->max_days && $days > (float) $type->max_days) {
            throw new BusinessRuleException("Le {$type->label} est limité à ".rtrim(rtrim(number_format((float) $type->max_days, 2, ',', ''), '0'), ',').' jour(s).', 'RG-23');
        }
        if ($type->requires_confirmation && $e->status === EmployeeStatus::EN_ESSAI) {
            throw new BusinessRuleException("Pas de {$type->label} pendant la période d'essai : les jours acquis seront pris après la confirmation (Manuel §2.4.8).", 'RG-23');
        }
        if ($type->min_seniority_months && $e->hired_at->diffInMonths(Carbon::parse($start)) < $type->min_seniority_months) {
            throw new BusinessRuleException("Le {$type->label} demande au moins {$type->min_seniority_months} mois d'ancienneté.", 'RG-23');
        }
        $overlap = LeaveRequest::query()->where('employee_id', $e->id)->active()->overlapping($start, $end)->first();
        if ($overlap) {
            throw new BusinessRuleException("Cette période chevauche la demande {$overlap->number} ({$overlap->periodLabel()}).", 'RG-23');
        }
        if (! empty($data['interim_employee_id'])) {
            if ((int) $data['interim_employee_id'] === $e->id) {
                throw new BusinessRuleException("L'intérimaire doit être une autre personne.", 'RG-23');
            }
            $busy = LeaveRequest::query()->where('employee_id', $data['interim_employee_id'])->active()->overlapping($start, $end)->exists();
            if ($busy) {
                throw new BusinessRuleException("L'intérimaire proposé est lui-même absent sur cette période.", 'RG-23');
            }
        }

        $year = $this->yearIndex($e, $start);
        $advance = false;
        $key = $type->balanceKey();
        if ($key) {
            $b = $this->balance($e, $key, $key === 'ANNUEL' ? $year : null);
            $free = $b['available'] - $b['pending'];
            if ($days > $free + 1e-9) {
                $canAdvance = $key === 'ANNUEL' && $e->status === EmployeeStatus::CONFIRME && $days <= $free + $b['advance_limit'] + 1e-9;
                if (! $canAdvance) {
                    throw new BusinessRuleException('Solde insuffisant : '.$this->fmt($free).' jour(s) disponible(s) pour '.$this->fmt($days).' demandé(s).'
                        .($key === 'ANNUEL' ? ' Une avance est possible jusqu\'à '.$this->fmt($b['advance_limit']).' jour(s) pour un employé confirmé (Manuel §5.10.1).' : ''), 'RG-23');
                }
                $advance = true;
            }
        }
        $late = $type->notice_days && Carbon::parse($start)->lt(today()->addDays($type->notice_days));

        return DB::transaction(function () use ($actor, $e, $type, $data, $start, $end, $days, $year, $advance, $late) {
            $r = LeaveRequest::query()->create([
                'number' => $this->numbering->next('CG'),
                'employee_id' => $e->id,
                'leave_type_id' => $type->id,
                'start_date' => $start,
                'end_date' => $end,
                'start_half' => (bool) ($data['start_half'] ?? false),
                'end_half' => (bool) ($data['end_half'] ?? false),
                'days' => $days,
                'leave_year' => $year,
                'expected_birth_date' => $data['expected_birth_date'] ?? null,
                'interim_employee_id' => $data['interim_employee_id'] ?? null,
                'reason' => $data['reason'] ?? null,
                'is_advance' => $advance,
                'late_notice' => $late,
                'status' => LeaveStatus::SOUMISE,
                'requested_by_id' => $actor->id,
            ]);
            $this->notifications->send($this->supervisorApprovers($r), new HrNotification(
                "Congé à valider : {$e->full_name}",
                ["{$type->label}, {$r->periodLabel()} ({$this->fmt($days)} jour(s)).", $advance ? 'Congé par anticipation.' : null, $late ? 'Préavis non respecté.' : null, $r->reason ? 'Motif : '.$r->reason : null],
                'warning',
                \App\Filament\Pages\LeaveApprovals::getUrl(),
            ));

            return $r;
        });
    }

    public function supervisorDecision(User $actor, LeaveRequest $r, bool $approve, ?string $comment = null): LeaveRequest
    {
        if (! $this->canSupervise($actor, $r)) {
            throw new BusinessRuleException("Cette demande n'attend pas votre validation.", 'RG-23');
        }
        if (! $approve && blank($comment)) {
            throw new BusinessRuleException('Indiquez le motif du refus.', 'RG-23');
        }

        return DB::transaction(function () use ($actor, $r, $approve, $comment) {
            $r->update([
                'status' => $approve ? LeaveStatus::VALIDEE_SUPERIEUR : LeaveStatus::REJETEE,
                'supervisor_decided_by_id' => $actor->id,
                'supervisor_decided_at' => now(),
                'decision_comment' => $comment,
            ]);
            if ($approve) {
                $this->notifications->send($this->finalApprovers($r), new HrNotification(
                    "Congé à approuver : {$r->employee->full_name}",
                    ["{$r->type->label}, {$r->periodLabel()}, validé par {$actor->name}."],
                    'warning',
                    \App\Filament\Pages\LeaveApprovals::getUrl(),
                ));
            } else {
                $this->notifyEmployee($r, 'refusée', $actor, $comment);
            }

            return $r;
        });
    }

    public function finalDecision(User $actor, LeaveRequest $r, bool $approve, ?string $comment = null): LeaveRequest
    {
        if (! $this->canApprove($actor, $r)) {
            throw new BusinessRuleException("Cette demande n'attend pas votre approbation.", 'RG-23');
        }
        if (! $approve) {
            if (blank($comment)) {
                throw new BusinessRuleException('Indiquez le motif du refus.', 'RG-23');
            }
            $r->update(['status' => LeaveStatus::REJETEE, 'approved_by_id' => $actor->id, 'approved_at' => now(), 'decision_comment' => $comment]);
            $this->notifyEmployee($r, 'refusée', $actor, $comment);

            return $r;
        }

        $type = $r->type;
        if ($type->document_after_days && (float) $r->days >= $type->document_after_days && ! $r->attachments()->exists()) {
            throw new BusinessRuleException('Joignez d\'abord le justificatif (certificat médical dès '.$type->document_after_days.' jours, Manuel §5.10.2).', 'RG-23');
        }

        return DB::transaction(function () use ($actor, $r, $type, $comment) {
            $key = $type->balanceKey();
            if ($key) {
                $b = $this->balance($r->employee, $key, $key === 'ANNUEL' ? $r->leave_year : null);
                $free = $b['available'] + ($r->is_advance ? $b['advance_limit'] : 0);
                if ((float) $r->days > $free + 1e-9) {
                    throw new BusinessRuleException('Le solde ne couvre plus cette demande ('.$this->fmt($b['available']).' jour(s) disponible(s)).', 'RG-23');
                }
                LeaveLedgerEntry::query()->create([
                    'employee_id' => $r->employee_id, 'balance' => $key, 'leave_year' => $key === 'ANNUEL' ? $r->leave_year : null,
                    'kind' => LeaveLedgerKind::PRISE, 'days' => -(float) $r->days, 'reference' => "R{$r->id}",
                    'leave_request_id' => $r->id, 'recorded_by_id' => $actor->id, 'effective_date' => $r->start_date->toDateString(),
                    'note' => $r->number.($r->is_advance ? ' (par anticipation)' : ''),
                ]);
            }
            $r->update(['status' => LeaveStatus::APPROUVEE, 'approved_by_id' => $actor->id, 'approved_at' => now(), 'decision_comment' => $comment ?? $r->decision_comment]);

            if ($type->code === 'MATERNITE') {
                $this->extendProbation($actor, $r);
            }
            if ($type->code === 'MALADIE' && (float) $r->days > Setting::number('MALADIE_ALERTE_DE_JOURS')) {
                $this->notifications->send(User::query()->where('role', Role::DIRECTION_EXECUTIVE->value)->get(), new HrNotification(
                    "Maladie prolongée : {$r->employee->full_name}",
                    ["Congé de maladie de {$this->fmt((float) $r->days)} jours ouvrables, {$r->periodLabel()} (Manuel §5.10.2)."],
                    'warning',
                ));
            }
            $this->notifyEmployee($r, 'approuvée', $actor, $comment);

            return $r;
        });
    }

    public function cancel(User $actor, LeaveRequest $r, string $reason): LeaveRequest
    {
        if (! $this->canCancel($actor, $r)) {
            throw new BusinessRuleException('Vous ne pouvez pas annuler cette demande.', 'RG-23');
        }

        return DB::transaction(function () use ($actor, $r, $reason) {
            $key = $r->type->balanceKey();
            if ($r->status === LeaveStatus::APPROUVEE && $key) {
                LeaveLedgerEntry::query()->create([
                    'employee_id' => $r->employee_id, 'balance' => $key, 'leave_year' => $key === 'ANNUEL' ? $r->leave_year : null,
                    'kind' => LeaveLedgerKind::ANNULATION, 'days' => (float) $r->days, 'reference' => "X{$r->id}",
                    'leave_request_id' => $r->id, 'recorded_by_id' => $actor->id, 'effective_date' => today()->toDateString(), 'note' => $reason,
                ]);
            }
            $r->update(['status' => LeaveStatus::ANNULEE, 'cancelled_at' => now(), 'cancel_reason' => $reason]);

            return $r;
        });
    }

    /** Absence non autorisée, saisie par le RH (Manuel §3.2) */
    public function recordAbsence(User $actor, Employee $e, string $start, string $end, string $reason): LeaveRequest
    {
        if (! PersonnelAccess::canManage($actor)) {
            throw new BusinessRuleException("Seul le RH enregistre une absence.", 'HABILITATION');
        }
        $type = LeaveType::byCode('ABSENCE');
        $days = $this->countDays($start, $end);
        if ($days <= 0) {
            throw new BusinessRuleException('La période ne contient aucun jour ouvrable.', 'RG-23');
        }
        if (LeaveRequest::query()->where('employee_id', $e->id)->active()->overlapping($start, $end)->exists()) {
            throw new BusinessRuleException("L'employé a déjà un congé enregistré sur cette période.", 'RG-23');
        }

        return DB::transaction(function () use ($actor, $e, $type, $start, $end, $days, $reason) {
            $r = LeaveRequest::query()->create([
                'number' => $this->numbering->next('CG'), 'employee_id' => $e->id, 'leave_type_id' => $type->id,
                'start_date' => $start, 'end_date' => $end, 'days' => $days, 'leave_year' => $this->yearIndex($e, $start),
                'reason' => $reason, 'status' => LeaveStatus::APPROUVEE, 'requested_by_id' => $actor->id,
                'approved_by_id' => $actor->id, 'approved_at' => now(),
            ]);
            if ($days >= Setting::number('ABSENCE_ALERTE_JOURS')) {
                $this->notifications->send($this->notifications->hrManagers()->push($e->supervisor?->user)->filter(), new HrNotification(
                    "Absence non autorisée de {$e->full_name} : {$this->fmt($days)} jours",
                    ['Période : '.$r->periodLabel().'.', "Le Manuel (§3.2) prévoit la fin du contrat après deux jours consécutifs d'absence non autorisée. La décision appartient à la Direction : elle est à enregistrer dans le dossier."],
                    'danger',
                ));
            }

            return $r;
        });
    }

    /** Jours de récupération accordés par le RH ou par le supérieur direct */
    public function grantRecovery(User $actor, Employee $e, float $days, string $reason): LeaveLedgerEntry
    {
        $isSupervisor = $e->supervisor?->user_id === $actor->id;
        if (! PersonnelAccess::canManage($actor) && ! $isSupervisor) {
            throw new BusinessRuleException('Seuls le RH et le supérieur direct accordent des jours de récupération.', 'HABILITATION');
        }
        if ($days <= 0 || $days > 10) {
            throw new BusinessRuleException('Indiquez un nombre de jours entre 0,5 et 10.', 'RG-23');
        }

        return LeaveLedgerEntry::query()->create([
            'employee_id' => $e->id, 'balance' => 'RECUPERATION', 'kind' => LeaveLedgerKind::RECUPERATION, 'days' => $days,
            'recorded_by_id' => $actor->id, 'effective_date' => today()->toDateString(), 'note' => $reason,
        ]);
    }

    /** Ajustement du solde annuel par le RH (reprise d'un solde antérieur, correction) */
    public function adjust(User $actor, Employee $e, float $days, string $reason, ?int $year = null): LeaveLedgerEntry
    {
        if (! PersonnelAccess::canManage($actor)) {
            throw new BusinessRuleException('Seul le RH ajuste un solde de congé.', 'HABILITATION');
        }
        if ($days == 0) {
            throw new BusinessRuleException('Indiquez un nombre de jours différent de zéro.', 'RG-23');
        }

        return LeaveLedgerEntry::query()->create([
            'employee_id' => $e->id, 'balance' => 'ANNUEL', 'leave_year' => $year ?? $this->yearIndex($e), 'kind' => LeaveLedgerKind::AJUSTEMENT,
            'days' => $days, 'recorded_by_id' => $actor->id, 'effective_date' => today()->toDateString(), 'note' => $reason,
        ]);
    }

    /**
     * Effet d'un mois sur la paie (étape 5) : jours ouvrables par taux de salaire maintenu.
     *
     * @return list<array{type:string, days:float, pay_rate:float}>
     */
    public function payImpact(Employee $e, CarbonInterface|string $month): array
    {
        $from = Carbon::parse($month)->startOfMonth();
        $to = $from->copy()->endOfMonth();
        $out = [];
        $requests = LeaveRequest::query()->with('type')->where('employee_id', $e->id)->where('status', LeaveStatus::APPROUVEE->value)
            ->overlapping($from->toDateString(), $to->toDateString())->get();
        foreach ($requests as $r) {
            if ((float) $r->type->pay_rate >= 1) {
                continue;
            }
            $s = $r->start_date->max($from)->toDateString();
            $t = $r->end_date->min($to)->toDateString();
            $days = $this->countDays($s, $t, $r->start_half && $s === $r->start_date->toDateString(), $r->end_half && $t === $r->end_date->toDateString());
            $out[] = ['type' => $r->type->code, 'days' => $days, 'pay_rate' => (float) $r->type->pay_rate];
        }

        return $out;
    }

    /** Congés approuvés qui couvrent une période (planning des absences) */
    public function absences(string $from, string $to): Collection
    {
        return LeaveRequest::query()->with('employee', 'type', 'interim')->where('status', LeaveStatus::APPROUVEE->value)
            ->overlapping($from, $to)->orderBy('start_date')->get();
    }

    // ------------------------------------------------------------------ outils

    /** Maternité pendant l'essai : la période d'essai est prolongée d'autant (Manuel §5.10.3) */
    private function extendProbation(User $actor, LeaveRequest $r): void
    {
        $contract = $r->employee->activeContract;
        if (! $contract || $contract->status !== ContractStatus::ACTIF) {
            return;
        }
        $probation = $contract->termsAt()['probation_end_date'];
        if (! $probation || in_array($contract->probation_outcome, [ProbationOutcome::CONFIRME, ProbationOutcome::RESILIE], true) || $probation < $r->start_date->toDateString()) {
            return;
        }
        $newEnd = Carbon::parse($probation)->addDays((int) $r->days)->toDateString();
        app(ContractService::class)->extendProbationForLeave($actor, $contract, $newEnd, "Congé de maternité {$r->number} (Manuel §5.10.3)");
    }

    private function notifyEmployee(LeaveRequest $r, string $verdict, User $actor, ?string $comment): void
    {
        $user = $r->employee->user;
        if (! $user) {
            return;
        }
        $this->notifications->send($user, new HrNotification(
            "Votre demande {$r->number} est {$verdict}",
            ["{$r->type->label}, {$r->periodLabel()}.", "Décision de {$actor->name}.", $comment ? 'Commentaire : '.$comment : null],
            $verdict === 'approuvée' ? 'success' : 'danger',
            \App\Filament\Pages\MySpace::getUrl(),
        ));
    }

    public function fmt(float $days): string
    {
        return rtrim(rtrim(number_format($days, 2, ',', ' '), '0'), ',');
    }
}
