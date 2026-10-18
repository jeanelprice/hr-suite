<?php

namespace App\Services;

use App\Enums\ProjectStatus;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Projets (version minimale de la Phase 1, nécessaire aux budgets).
 * RG-13 : un projet naît en Brouillon, avec au moins un objectif statutaire ;
 * il devient Actif à la validation de son premier budget.
 */
class ProjectService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @param  list<int>  $objectiveIds */
    public function create(User $actor, array $data, array $objectiveIds): Project
    {
        if ($objectiveIds === []) {
            throw new BusinessRuleException('Au moins un objectif statutaire est obligatoire (RG-13).');
        }

        return DB::transaction(function () use ($data, $objectiveIds) {
            $project = Project::query()->create($data + ['status' => ProjectStatus::BROUILLON]);
            $project->objectives()->sync($objectiveIds);

            return $project;
        });
    }

    public function close(User $actor, Project $project): Project
    {
        return DB::transaction(function () use ($actor, $project) {
            if ($project->status === ProjectStatus::CLOTURE) {
                throw new BusinessRuleException('Projet déjà clôturé.');
            }
            $open = PurchaseOrder::query()
                ->whereIn('status', [PurchaseOrderStatus::BROUILLON->value, PurchaseOrderStatus::SOUMIS->value, PurchaseOrderStatus::VALIDE->value])
                ->whereHas('request', fn ($q) => $q->where('project_id', $project->id))
                ->count();
            if ($open > 0) {
                throw new BusinessRuleException("Impossible de clôturer : {$open} bon(s) de commande encore ouvert(s).", 'COMMANDES_OUVERTES');
            }
            $project->update(['status' => ProjectStatus::CLOTURE, 'closed_at' => now()]);

            return $project;
        });
    }
}
