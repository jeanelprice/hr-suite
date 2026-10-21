<?php

namespace App\Services;

use App\Enums\AmendmentKind;
use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\EmployeeStatus;
use App\Enums\ProbationOutcome;
use App\Exceptions\BusinessRuleException;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Deadline;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Support\Money;
use App\Support\PersonnelAccess;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Contrats de travail (RG-22) :
 * - au plus un contrat actif par employé ;
 * - un contrat à durée déterminée ne dépasse pas la fin du projet qui le finance (Manuel §2.4.4) ;
 * - la ventilation budgétaire totalise 100 % sur chaque période ;
 * - un avenant s'applique à sa date d'effet sans modifier le contrat d'origine ;
 * - la Direction Exécutive décide de l'issue de la période d'essai (Manuel §2.4.8).
 * Aucun engagement budgétaire n'est créé à la signature (RG-06).
 */
class ContractService
{
    public const MAX_PROBATION_MONTHS = 6;

    public function __construct(
        private NumberingService $numbering,
        private EmployeeService $employees,
        private NotificationService $notifications,
    ) {}

    /** @param  list<array{budget_line_id:int, percentage:mixed, valid_from?:?string, valid_to?:?string}>  $allocations */
    public function create(User $actor, Employee $employee, array $data, array $allocations): Contract
    {
        $this->assertCanManage($actor);
        if ($employee->status === EmployeeStatus::SORTI) {
            throw new BusinessRuleException("Impossible d'établir un contrat pour un employé sorti.", 'RG-22');
        }

        return DB::transaction(function () use ($actor, $employee, $data, $allocations) {
            $contract = new Contract($data + [
                'employee_id' => $employee->id,
                'status' => ContractStatus::BROUILLON,
                'created_by_id' => $actor->id,
            ]);
            $this->assertTerms($contract);
            $allocations = $this->normalizeAllocations($contract, $allocations);
            $contract->number = $this->numbering->next('CT');
            $contract->save();
            $contract->allocations()->createMany($allocations);

            return $contract->load('allocations');
        });
    }

    /** Un brouillon reste modifiable tant qu'il n'est pas signé */
    public function updateDraft(User $actor, Contract $contract, array $data, array $allocations): Contract
    {
        $this->assertCanManage($actor);
        if ($contract->status !== ContractStatus::BROUILLON) {
            throw new BusinessRuleException('Seul un contrat en brouillon peut être modifié ; utilisez un avenant.', 'RG-22');
        }

        return DB::transaction(function () use ($contract, $data, $allocations) {
            $contract->fill($data);
            $this->assertTerms($contract);
            $allocations = $this->normalizeAllocations($contract, $allocations);
            $contract->save();
            $contract->allocations()->delete();
            $contract->allocations()->createMany($allocations);

            return $contract->load('allocations');
        });
    }

    /** Signature : le contrat devient actif ; la situation de l'employé suit le contrat */
    public function activate(User $actor, Contract $contract, string $signedAt): Contract
    {
        $this->assertCanManage($actor);
        if ($contract->status !== ContractStatus::BROUILLON) {
            throw new BusinessRuleException('Ce contrat est déjà signé.', 'RG-22');
        }
        $other = Contract::query()->where('employee_id', $contract->employee_id)
            ->where('status', ContractStatus::ACTIF->value)->whereKeyNot($contract->id)->first();
        if ($other) {
            throw new BusinessRuleException("L'employé a déjà le contrat actif {$other->number}. Terminez-le avant d'activer celui-ci (RG-22).", 'RG-22');
        }
        $this->assertAllocationsComplete($contract);

        return DB::transaction(function () use ($actor, $contract, $signedAt) {
            $contract->update(['status' => ContractStatus::ACTIF, 'signed_at' => $signedAt]);

            $employee = $contract->employee;
            $changes = array_filter([
                'position_id' => $contract->position_id,
                'duty_station_id' => $contract->duty_station_id,
                'department_id' => $contract->position?->department_id ?? $employee->department_id,
            ]);
            if ($contract->probation_end_date && $contract->probation_end_date->isFuture()) {
                $changes['status'] = EmployeeStatus::EN_ESSAI;
            } elseif ($employee->status === EmployeeStatus::EN_ESSAI && ! $contract->probation_end_date) {
                $changes['status'] = EmployeeStatus::CONFIRME;
            }
            $this->employees->update($actor, $employee, $changes, $contract->start_date->toDateString());
            $this->scheduleDeadlines($contract);

            return $contract;
        });
    }

    /** Avenant ou lettre de transfert (RG-22) */
    public function amend(User $actor, Contract $contract, AmendmentKind $kind, mixed $value, string $effectiveDate, string $reason): ContractAmendment
    {
        $this->assertCanManage($actor);

        return $this->recordAmendment($actor, $contract, $kind, $value, $effectiveDate, $reason);
    }

    private function recordAmendment(User $actor, Contract $contract, AmendmentKind $kind, mixed $value, string $effectiveDate, string $reason): ContractAmendment
    {
        if ($contract->status !== ContractStatus::ACTIF) {
            throw new BusinessRuleException('Un avenant ne porte que sur un contrat actif.', 'RG-22');
        }
        $field = Contract::AMENDED_FIELD[$kind->value];
        $current = $contract->termsAt($effectiveDate)[$field];
        $effective = Carbon::parse($effectiveDate);
        if ($effective->lt($contract->start_date)) {
            throw new BusinessRuleException("La date d'effet précède le début du contrat.", 'RG-22');
        }

        switch ($kind) {
            case AmendmentKind::SALAIRE:
                if (BigDecimal::of((string) $value)->isLessThanOrEqualTo(0)) {
                    throw new BusinessRuleException('Le nouveau salaire doit être positif.', 'RG-22');
                }
                $value = (string) Money::round2(Money::of($value));
                break;
            case AmendmentKind::PROLONGATION_CONTRAT:
                $value = Carbon::parse($value)->toDateString();
                if (! $contract->isFixedTerm()) {
                    throw new BusinessRuleException("Un CDI n'a pas de date de fin à prolonger.", 'RG-22');
                }
                if ($current && $value <= $current) {
                    throw new BusinessRuleException('La nouvelle date de fin doit être postérieure à la date actuelle ('.Carbon::parse($current)->format('d/m/Y').').', 'RG-22');
                }
                $this->assertWithinProject($contract->project, $value);
                break;
            case AmendmentKind::PROLONGATION_ESSAI:
                $value = Carbon::parse($value)->toDateString();
                if (! $current || $value <= $current) {
                    throw new BusinessRuleException("La nouvelle fin d'essai doit être postérieure à l'actuelle.", 'RG-22');
                }
                $this->assertProbationLength($contract->start_date, $value, $contract->termsAt($effectiveDate)['end_date']);
                break;
            default:
                if (! $value) {
                    throw new BusinessRuleException('Indiquez la nouvelle valeur.', 'RG-22');
                }
                $value = (int) $value;
        }

        return DB::transaction(function () use ($actor, $contract, $kind, $field, $current, $value, $effective, $reason) {
            $amendment = $contract->amendments()->create([
                'kind' => $kind,
                'old_value' => [$field => $current],
                'new_value' => [$field => $value],
                'effective_date' => $effective->toDateString(),
                'reason' => $reason,
                'created_by_id' => $actor->id,
            ]);
            if ($effective->lte(today())) {
                $this->applyToEmployee($actor, $amendment);
            }
            if (in_array($kind, [AmendmentKind::PROLONGATION_CONTRAT, AmendmentKind::PROLONGATION_ESSAI], true)) {
                $contract->unsetRelation('amendments');
                $this->scheduleDeadlines($contract);
            }

            return $amendment;
        });
    }

    /**
     * Prolongation de l'essai imposée par un congé de maternité (Manuel §5.10.3) :
     * elle peut dépasser la durée maximale de 6 mois.
     */
    public function extendProbationForLeave(User $actor, Contract $contract, string $newEnd, string $reason): ContractAmendment
    {
        $current = $contract->termsAt()['probation_end_date'];
        $amendment = $contract->amendments()->create([
            'kind' => AmendmentKind::PROLONGATION_ESSAI,
            'old_value' => ['probation_end_date' => $current],
            'new_value' => ['probation_end_date' => $newEnd],
            'effective_date' => today()->toDateString(),
            'reason' => $reason,
            'created_by_id' => $actor->id,
        ]);
        $contract->unsetRelation('amendments');
        $this->scheduleDeadlines($contract);

        return $amendment;
    }

    /** Poste et lieu d'un avenant arrivé à sa date d'effet : reportés dans le dossier */
    public function applyToEmployee(User $actor, ContractAmendment $amendment): void
    {
        $value = $amendment->new_value;
        $changes = match ($amendment->kind) {
            AmendmentKind::POSTE => ['position_id' => $value['position_id']],
            AmendmentKind::LIEU => ['duty_station_id' => $value['duty_station_id']],
            default => [],
        };
        if ($changes) {
            $this->employees->update($actor, $amendment->contract->employee, $changes, $amendment->effective_date->toDateString());
        }
    }

    /** Décision de fin d'essai : réservée à la Direction Exécutive (Manuel §2.4.8) */
    public function decideProbation(User $actor, Contract $contract, ProbationOutcome $outcome, string $comment, ?string $newEnd = null): Contract
    {
        if (! $actor->isDirectionExecutive()) {
            throw new BusinessRuleException("La décision de fin de période d'essai revient à la Direction Exécutive.", 'RG-22');
        }
        if ($contract->employee->user_id === $actor->id) {
            throw new BusinessRuleException('Vous ne pouvez pas décider de votre propre période d\'essai (RG-14).', 'RG-14');
        }
        if ($contract->status !== ContractStatus::ACTIF || ! $contract->probation_end_date) {
            throw new BusinessRuleException("Ce contrat n'a pas de période d'essai en cours.", 'RG-22');
        }
        if ($contract->probation_outcome && $contract->probation_outcome !== ProbationOutcome::PROLONGE) {
            throw new BusinessRuleException("L'issue de la période d'essai est déjà décidée.", 'RG-22');
        }

        return DB::transaction(function () use ($actor, $contract, $outcome, $comment, $newEnd) {
            if ($outcome === ProbationOutcome::PROLONGE) {
                if (! $newEnd) {
                    throw new BusinessRuleException("Indiquez la nouvelle fin de période d'essai.", 'RG-22');
                }
                $this->recordAmendment($actor, $contract, AmendmentKind::PROLONGATION_ESSAI, $newEnd, today()->toDateString(), 'Prolongation décidée par la DE : '.$comment);
            }
            $contract->update([
                'probation_outcome' => $outcome,
                'probation_decided_at' => today()->toDateString(),
                'probation_decided_by_id' => $actor->id,
            ]);
            $employee = $contract->employee;
            if ($outcome === ProbationOutcome::CONFIRME) {
                $employee->update(['status' => EmployeeStatus::CONFIRME]);
                $this->employees->history($employee, 'Statut', EmployeeStatus::EN_ESSAI->getLabel(), EmployeeStatus::CONFIRME->getLabel().' — '.$comment, today(), $actor);
            }
            if ($outcome === ProbationOutcome::RESILIE) {
                $contract->update(['status' => ContractStatus::RESILIE, 'terminated_at' => today()->toDateString(), 'termination_reason' => "Fin pendant la période d'essai : ".$comment]);
                $this->employees->history($employee, 'Contrat', $contract->number, "Résilié pendant l'essai — ".$comment, today(), $actor);
            }
            $this->notifications->send($this->notifications->hrManagers(), new \App\Notifications\HrNotification(
                "Période d'essai de {$employee->full_name} : {$outcome->getLabel()}",
                ["Décision de {$actor->name} sur le contrat {$contract->number}.", 'Motif : '.$comment],
                $outcome === ProbationOutcome::RESILIE ? 'danger' : 'success',
            ));

            return $contract;
        });
    }

    public function terminate(User $actor, Contract $contract, string $date, string $reason): Contract
    {
        $this->assertCanManage($actor);
        if ($contract->status !== ContractStatus::ACTIF) {
            throw new BusinessRuleException("Ce contrat n'est pas actif.", 'RG-22');
        }
        $end = $contract->termsAt($date)['end_date'];
        $contract->update([
            'status' => $end && $date >= $end ? ContractStatus::TERMINE : ContractStatus::RESILIE,
            'terminated_at' => $date,
            'termination_reason' => $reason,
        ]);
        Deadline::query()->where('subject_type', 'contract')->where('subject_id', $contract->id)->where('status', 'OUVERTE')->update(['status' => 'ANNULEE']);

        return $contract;
    }

    /** Contrats à durée déterminée qui finissent bientôt, essais qui s'achèvent (RG-19) */
    public function upcoming(): array
    {
        $contractDays = Setting::number('ALERTE_CONTRAT_JOURS');
        $limit = today()->addDays($contractDays);
        $active = Contract::query()->with(['employee', 'amendments'])->where('status', ContractStatus::ACTIF->value)->get();

        $ending = $active->filter(function (Contract $c) use ($limit) {
            $end = $c->termsAt()['end_date'];

            return $end && $end <= $limit->toDateString();
        })->sortBy(fn (Contract $c) => $c->termsAt()['end_date'])->values();

        $probation = $active->filter(function (Contract $c) {
            $end = $c->termsAt()['probation_end_date'];

            return $end && $c->probation_outcome !== ProbationOutcome::CONFIRME && $c->probation_outcome !== ProbationOutcome::RESILIE
                && $end <= today()->addDays(15)->toDateString();
        })->sortBy(fn (Contract $c) => $c->termsAt()['probation_end_date'])->values();

        return ['ending' => $ending, 'probation' => $probation];
    }

    // ------------------------------------------------------------------ règles

    private function assertTerms(Contract $c): void
    {
        $type = $c->type instanceof ContractType ? $c->type : ContractType::from($c->type);
        $c->type = $type;
        $start = Carbon::parse($c->start_date);
        if ($type === ContractType::CDI) {
            $c->end_date = null;
        } elseif (! $c->end_date) {
            throw new BusinessRuleException("La date de fin est obligatoire pour un contrat {$type->getLabel()}.", 'RG-22');
        }
        if ($c->end_date && Carbon::parse($c->end_date)->lt($start)) {
            throw new BusinessRuleException('La date de fin précède la date de début.', 'RG-22');
        }
        if (BigDecimal::of((string) $c->base_salary)->isLessThanOrEqualTo(0)) {
            throw new BusinessRuleException('Le salaire de base doit être positif.', 'RG-22');
        }
        if ($c->end_date) {
            $this->assertWithinProject($c->project_id ? Project::query()->find($c->project_id) : null, Carbon::parse($c->end_date)->toDateString());
        }
        if ($c->probation_end_date) {
            $this->assertProbationLength($start, Carbon::parse($c->probation_end_date)->toDateString(), $c->end_date ? Carbon::parse($c->end_date)->toDateString() : null);
        }
    }

    private function assertWithinProject(?Project $project, string $end): void
    {
        if ($project && $end > $project->end_date->toDateString()) {
            throw new BusinessRuleException("Le contrat ne peut pas dépasser la fin du projet {$project->code} ({$project->end_date->format('d/m/Y')}) qui le finance (Manuel §2.4.4).", 'RG-22');
        }
    }

    private function assertProbationLength(\DateTimeInterface $start, string $probationEnd, ?string $contractEnd): void
    {
        $start = Carbon::parse($start);
        if ($probationEnd <= $start->toDateString()) {
            throw new BusinessRuleException("La fin d'essai doit suivre le début du contrat.", 'RG-22');
        }
        if ($probationEnd > $start->copy()->addMonths(self::MAX_PROBATION_MONTHS)->toDateString()) {
            throw new BusinessRuleException("La période d'essai ne peut pas dépasser 6 mois (Manuel §2.4.8).", 'RG-22');
        }
        if ($contractEnd && $probationEnd > $contractEnd) {
            throw new BusinessRuleException("La période d'essai dépasse la fin du contrat.", 'RG-22');
        }
    }

    /**
     * Ventilation : pourcentages positifs, lignes distinctes par période,
     * et 100 % exactement à chaque date couverte.
     */
    private function normalizeAllocations(Contract $c, array $allocations): array
    {
        $start = Carbon::parse($c->start_date)->toDateString();
        $rows = [];
        foreach ($allocations as $a) {
            if (empty($a['budget_line_id'])) {
                continue;
            }
            $pct = BigDecimal::of((string) ($a['percentage'] ?? 0));
            if ($pct->isLessThanOrEqualTo(0) || $pct->isGreaterThan(100)) {
                throw new BusinessRuleException('Chaque part de la ventilation doit être comprise entre 0 et 100 %.', 'RG-22');
            }
            $from = ! empty($a['valid_from']) ? Carbon::parse($a['valid_from'])->toDateString() : $start;
            $to = ! empty($a['valid_to']) ? Carbon::parse($a['valid_to'])->toDateString() : null;
            if ($to && $to < $from) {
                throw new BusinessRuleException('Une période de ventilation se termine avant de commencer.', 'RG-22');
            }
            $rows[] = ['budget_line_id' => (int) $a['budget_line_id'], 'percentage' => (string) $pct->toScale(2), 'valid_from' => $from, 'valid_to' => $to];
        }
        if ($rows === []) {
            throw new BusinessRuleException('Indiquez la ventilation du coût sur au moins une ligne budgétaire (100 %).', 'RG-22');
        }
        $this->assertHundred($rows, $start);

        return $rows;
    }

    private function assertAllocationsComplete(Contract $c): void
    {
        $rows = $c->allocations->map(fn ($a) => [
            'budget_line_id' => $a->budget_line_id, 'percentage' => (string) $a->percentage,
            'valid_from' => $a->valid_from->toDateString(), 'valid_to' => $a->valid_to?->toDateString(),
        ])->all();
        if ($rows === []) {
            throw new BusinessRuleException('La ventilation budgétaire du contrat est vide.', 'RG-22');
        }
        $this->assertHundred($rows, $c->start_date->toDateString());
    }

    /** Vérifie 100 % au début du contrat et à chaque changement de période */
    private function assertHundred(array $rows, string $start): void
    {
        $dates = collect($rows)->flatMap(fn ($r) => array_filter([$r['valid_from'], $r['valid_to'] ? Carbon::parse($r['valid_to'])->addDay()->toDateString() : null]))
            ->push($start)->filter(fn ($d) => $d >= $start)->unique()->sort()->values();
        $lastCovered = collect($rows)->contains(fn ($r) => $r['valid_to'] === null) ? null : collect($rows)->max('valid_to');

        foreach ($dates as $date) {
            if ($lastCovered && $date > $lastCovered) {
                continue;
            }
            $active = array_filter($rows, fn ($r) => $r['valid_from'] <= $date && ($r['valid_to'] === null || $r['valid_to'] >= $date));
            $lines = array_column($active, 'budget_line_id');
            if (count($lines) !== count(array_unique($lines))) {
                throw new BusinessRuleException('Une même ligne budgétaire apparaît deux fois sur la même période.', 'RG-22');
            }
            $sum = Money::sum(array_column($active, 'percentage'));
            if (! $sum->isEqualTo(100)) {
                throw new BusinessRuleException('La ventilation totalise '.Money::fmt((string) $sum).' % au '.Carbon::parse($date)->format('d/m/Y').' : elle doit faire exactement 100 % (RG-22).', 'RG-22');
            }
        }
    }

    /** Échéances surveillées (Fondation D) : fin de contrat à J-30, fin d'essai à J-15 */
    private function scheduleDeadlines(Contract $contract): void
    {
        Deadline::query()->where('subject_type', 'contract')->where('subject_id', $contract->id)->where('status', 'OUVERTE')->update(['status' => 'ANNULEE']);
        $terms = $contract->termsAt();
        if ($terms['end_date']) {
            Deadline::query()->create([
                'kind' => 'CONTRAT_FIN', 'subject_type' => 'contract', 'subject_id' => $contract->id,
                'due_at' => Carbon::parse($terms['end_date'])->subDays(Setting::number('ALERTE_CONTRAT_JOURS')), 'status' => 'OUVERTE',
            ]);
        }
        if ($terms['probation_end_date'] && ! in_array($contract->probation_outcome, [ProbationOutcome::CONFIRME, ProbationOutcome::RESILIE], true)) {
            Deadline::query()->create([
                'kind' => 'FIN_ESSAI', 'subject_type' => 'contract', 'subject_id' => $contract->id,
                'due_at' => Carbon::parse($terms['probation_end_date'])->subDays(15), 'status' => 'OUVERTE',
            ]);
        }
    }

    private function assertCanManage(User $actor): void
    {
        if (! PersonnelAccess::canManage($actor)) {
            throw new BusinessRuleException("Vous n'avez pas l'habilitation RH & Contrats en création.", 'HABILITATION');
        }
    }
}
