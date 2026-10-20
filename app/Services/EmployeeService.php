<?php

namespace App\Services;

use App\Enums\ContractStatus;
use App\Enums\DisciplinaryMeasure;
use App\Enums\EmployeeStatus;
use App\Enums\ServiceCardStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Contract;
use App\Models\DisciplinaryAction;
use App\Models\Employee;
use App\Models\EmployeeExit;
use App\Models\EmployeeHistory;
use App\Models\EmployeeRelation;
use App\Models\ServiceCard;
use App\Models\User;
use App\Support\PersonnelAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Dossier du personnel : création, changements de situation tracés, ligne
 * hiérarchique (recopiée sur les comptes utilisateurs), liens de parenté (RG-27),
 * carte de service, discipline et sortie.
 */
class EmployeeService
{
    /** Champs dont chaque changement est daté dans l'historique */
    private const TRACKED = [
        'position_id' => 'Poste',
        'department_id' => 'Département',
        'duty_station_id' => "Lieu d'affectation",
        'supervisor_id' => 'Supérieur hiérarchique',
        'status' => 'Statut',
        'employment_category' => "Catégorie d'emploi",
    ];

    public function __construct(private AuditService $audit) {}

    public function create(User $actor, array $data): Employee
    {
        $this->assertCanManage($actor);

        return DB::transaction(function () use ($actor, $data) {
            $data['matricule'] = $this->nextMatricule();
            $data['status'] ??= EmployeeStatus::EN_ESSAI;
            $employee = new Employee($data);
            $this->assertSupervisorAllowed($employee, $data['supervisor_id'] ?? null);
            $employee->save();

            $this->history($employee, 'Entrée en fonction', null, $employee->hired_at->format('d/m/Y'), $employee->hired_at, $actor);
            $this->syncUserHierarchy($employee);

            return $employee;
        });
    }

    public function update(User $actor, Employee $employee, array $data, ?string $effectiveDate = null): Employee
    {
        $this->assertCanManage($actor);
        if ($employee->status === EmployeeStatus::SORTI) {
            throw new BusinessRuleException("Le dossier d'un employé sorti n'est plus modifiable.", 'EMPLOYE_SORTI');
        }

        return DB::transaction(function () use ($actor, $employee, $data, $effectiveDate) {
            if (array_key_exists('supervisor_id', $data)) {
                $this->assertSupervisorAllowed($employee, $data['supervisor_id']);
            }
            $before = $employee->only(array_keys(self::TRACKED));
            $employee->fill($data);
            $dirty = array_intersect_key($employee->getDirty(), self::TRACKED);
            $userChanged = $employee->isDirty('user_id') || $employee->isDirty('supervisor_id');
            $employee->save();

            foreach (array_keys($dirty) as $field) {
                $this->history($employee, self::TRACKED[$field], $this->describe($field, $before[$field]), $this->describe($field, $employee->{$field}), Carbon::parse($effectiveDate ?? now()), $actor);
            }
            if ($userChanged) {
                $this->syncUserHierarchy($employee);
            }

            return $employee;
        });
    }

    /** Consultation d'un dossier : tracée dans le journal d'audit (RG-26) */
    public function logConsultation(User $actor, Employee $employee): void
    {
        $this->audit->record('EMPLOYE_CONSULTE', $employee, null, ['consulte_par' => $actor->name]);
    }

    /** RG-27 et ligne hiérarchique cohérente */
    public function assertSupervisorAllowed(Employee $employee, mixed $supervisorId): void
    {
        if (! $supervisorId) {
            return;
        }
        $supervisor = Employee::query()->findOrFail($supervisorId);
        if ($employee->exists && $supervisor->id === $employee->id) {
            throw new BusinessRuleException('Un employé ne peut pas être son propre supérieur.', 'HIERARCHIE');
        }
        if ($employee->exists && $employee->isRelatedTo($supervisor)) {
            throw new BusinessRuleException("{$supervisor->full_name} a un lien de parenté déclaré avec cet employé : il ne peut pas être son supérieur (RG-27).", 'RG-27');
        }
        // Pas de boucle : le supérieur ne doit pas dépendre, même indirectement, de l'employé
        $cursor = $supervisor;
        $guard = 0;
        while ($employee->exists && $cursor->supervisor_id && $guard++ < 50) {
            if ($cursor->supervisor_id === $employee->id) {
                throw new BusinessRuleException("{$supervisor->full_name} dépend déjà de cet employé dans l'organigramme.", 'HIERARCHIE');
            }
            $cursor = $cursor->supervisor;
        }
    }

    /**
     * Le supérieur saisi dans le dossier pilote les validations et les alertes :
     * il est recopié sur le compte utilisateur de l'employé et de ses subordonnés.
     */
    public function syncUserHierarchy(Employee $employee): void
    {
        $employee->loadMissing('supervisor', 'user');
        if ($employee->user) {
            $employee->user->update(['supervisor_id' => $employee->supervisor?->user_id]);
        }
        foreach ($employee->subordinates()->whereNotNull('user_id')->get() as $sub) {
            User::query()->whereKey($sub->user_id)->update(['supervisor_id' => $employee->user_id]);
        }
    }

    public function declareRelation(User $actor, Employee $employee, array $data): EmployeeRelation
    {
        $this->assertCanManage($actor);
        if (! empty($data['related_employee_id'])) {
            $other = Employee::query()->findOrFail($data['related_employee_id']);
            if ($other->id === $employee->id) {
                throw new BusinessRuleException('Choisissez un autre employé.', 'RG-27');
            }
            if ($employee->supervisor_id === $other->id || $other->supervisor_id === $employee->id) {
                throw new BusinessRuleException("Ces deux personnes sont supérieur et subordonné. Changez d'abord le supérieur hiérarchique, puis déclarez le lien (RG-27).", 'RG-27');
            }
        }

        return $employee->declaredRelations()->create($data + ['declared_at' => now()->toDateString()]);
    }

    public function issueCard(User $actor, Employee $employee, ?string $expiresAt = null): ServiceCard
    {
        $this->assertCanManage($actor);
        if ($employee->serviceCards()->where('status', ServiceCardStatus::ACTIVE->value)->exists()) {
            throw new BusinessRuleException("Cet employé a déjà une carte active. Déclarez-la perdue ou restituée avant d'en délivrer une nouvelle.", 'CARTE');
        }
        $n = $employee->serviceCards()->count() + 1;

        return $employee->serviceCards()->create([
            'number' => $employee->matricule.'-C'.$n,
            'issued_at' => now()->toDateString(),
            'expires_at' => $expiresAt ?? $employee->activeContract?->end_date?->toDateString(),
            'status' => ServiceCardStatus::ACTIVE,
        ]);
    }

    /** Carte perdue : remplacement facturé à l'employé (Manuel §3.13) ; ou carte rendue */
    public function closeCard(User $actor, ServiceCard $card, ServiceCardStatus $status, ?string $fee = null): ServiceCard
    {
        $this->assertCanManage($actor);
        if ($card->status !== ServiceCardStatus::ACTIVE) {
            throw new BusinessRuleException('Cette carte n\'est plus active.', 'CARTE');
        }
        $card->update(['status' => $status, 'closed_at' => now()->toDateString(), 'replacement_fee' => $status === ServiceCardStatus::PERDUE ? $fee : null]);

        return $card;
    }

    public function recordDisciplinary(User $actor, Employee $employee, array $data): DisciplinaryAction
    {
        $this->assertCanManage($actor);

        return DB::transaction(function () use ($actor, $employee, $data) {
            $measure = $data['measure'] instanceof DisciplinaryMeasure ? $data['measure'] : DisciplinaryMeasure::from($data['measure']);
            $decided = Carbon::parse($data['decided_at']);
            $action = $employee->disciplinaryActions()->create($data + [
                'decided_by_id' => $actor->id,
                'counts_until' => $measure === DisciplinaryMeasure::MISE_EN_DEMEURE ? $decided->copy()->addMonths(12)->toDateString() : null,
            ]);
            if ($measure === DisciplinaryMeasure::SUSPENSION) {
                $this->update($actor, $employee, ['status' => EmployeeStatus::SUSPENDU], $decided->toDateString());
            }

            return $action;
        });
    }

    /**
     * Sortie : le contrat actif prend fin, le compte de connexion est désactivé.
     * Le dernier salaire attend le certificat d'autorisation (Manuel §5.2).
     */
    public function recordExit(User $actor, Employee $employee, array $data): EmployeeExit
    {
        $this->assertCanManage($actor);
        if ($employee->exitRecord) {
            throw new BusinessRuleException('La sortie de cet employé est déjà enregistrée.', 'SORTIE');
        }

        return DB::transaction(function () use ($actor, $employee, $data) {
            $exit = $employee->exitRecord()->create($data + ['recorded_by_id' => $actor->id]);

            $contract = $employee->activeContract;
            if ($contract) {
                $natural = $contract->end_date && $contract->end_date->toDateString() <= $exit->exit_date->toDateString();
                $contract->update([
                    'status' => $natural ? ContractStatus::TERMINE : ContractStatus::RESILIE,
                    'terminated_at' => $exit->exit_date->toDateString(),
                    'termination_reason' => $exit->reason->getLabel(),
                ]);
            }
            if ($exit->card_returned) {
                $employee->serviceCards()->where('status', ServiceCardStatus::ACTIVE->value)
                    ->update(['status' => ServiceCardStatus::RESTITUEE->value, 'closed_at' => $exit->exit_date->toDateString()]);
            }
            $this->update($actor, $employee, ['status' => EmployeeStatus::SORTI], $exit->exit_date->toDateString());
            $employee->user?->update(['is_active' => false]);

            return $exit;
        });
    }

    public function updateExit(User $actor, EmployeeExit $exit, array $data): EmployeeExit
    {
        $this->assertCanManage($actor);
        if ($exit->clearance_issued_at) {
            throw new BusinessRuleException("Le certificat d'autorisation est délivré : la sortie n'est plus modifiable.", 'SORTIE');
        }
        $exit->update($data);
        if ($exit->card_returned) {
            $exit->employee->serviceCards()->where('status', ServiceCardStatus::ACTIVE->value)
                ->update(['status' => ServiceCardStatus::RESTITUEE->value, 'closed_at' => now()->toDateString()]);
        }

        return $exit;
    }

    public function issueClearance(User $actor, EmployeeExit $exit): EmployeeExit
    {
        $this->assertCanManage($actor);
        if (! $exit->card_returned || ! $exit->equipment_returned) {
            throw new BusinessRuleException("La carte de service et le matériel doivent être restitués avant le certificat d'autorisation.", 'SORTIE');
        }
        if (blank($exit->debrief_notes)) {
            throw new BusinessRuleException('Le compte rendu de fin de contrat doit être rempli (Manuel §2.7).', 'SORTIE');
        }
        $exit->update(['clearance_issued_at' => now(), 'clearance_by_id' => $actor->id]);

        return $exit;
    }

    public function history(Employee $employee, string $field, ?string $old, ?string $new, \DateTimeInterface $effective, ?User $actor): void
    {
        EmployeeHistory::query()->create([
            'employee_id' => $employee->id,
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new,
            'effective_date' => Carbon::parse($effective)->toDateString(),
            'recorded_by_id' => $actor?->id,
        ]);
    }

    public function nextMatricule(): string
    {
        $last = Employee::query()->where('matricule', 'like', 'HR-%')->lockForUpdate()->orderByDesc('id')->value('matricule');
        $n = $last ? ((int) substr($last, 3)) + 1 : 1;

        return 'HR-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    private function describe(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \BackedEnum && method_exists($value, 'getLabel')) {
            return $value->getLabel();
        }

        return match ($field) {
            'position_id' => \App\Models\Position::query()->find($value)?->title,
            'department_id' => \App\Models\Department::query()->find($value)?->name,
            'duty_station_id' => \App\Models\DutyStation::query()->find($value)?->name,
            'supervisor_id' => Employee::query()->find($value)?->full_name,
            'status' => EmployeeStatus::tryFrom((string) $value)?->getLabel(),
            'employment_category' => \App\Enums\EmploymentCategory::tryFrom((string) $value)?->getLabel(),
            default => (string) $value,
        };
    }

    private function assertCanManage(User $actor): void
    {
        if (! PersonnelAccess::canManage($actor)) {
            throw new BusinessRuleException("Vous n'avez pas l'habilitation RH & Contrats en création.", 'HABILITATION');
        }
    }
}
