<?php

namespace App\Filament\Support;

use App\Enums\ProjectStatus;
use App\Models\BudgetLine;
use App\Models\Project;
use App\Services\BudgetLedger;
use App\Support\Money;

/**
 * Listes de choix partagées par les formulaires d'achat.
 */
final class BudgetLineOptions
{
    /** Projets actifs (seuls autorisés pour une opération financière, RG-13) */
    public static function activeProjects(): array
    {
        return Project::query()->where('status', ProjectStatus::ACTIF->value)->orderBy('code')
            ->get()->mapWithKeys(fn (Project $p) => [$p->id => "{$p->code} — {$p->title}"])->all();
    }

    /** Lignes actives des budgets validés du projet, avec leur solde disponible affiché (RG-07) */
    public static function forProject(?int $projectId): array
    {
        if (! $projectId) {
            return [];
        }
        $ledger = app(BudgetLedger::class);

        return BudgetLine::query()
            ->where('is_active', true)
            ->whereHas('budget', fn ($q) => $q->where('project_id', $projectId)->whereNotNull('current_version_id'))
            ->with('budget.fiscalYear')
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (BudgetLine $l) => [
                $l->id => "{$l->budget->fiscalYear->year} · {$l->code} — {$l->label} (disponible : "
                    .Money::fmt($ledger->balance($l)->available()).' '.$l->budget->currency->value.')',
            ])->all();
    }

    /** Toutes les lignes actives des budgets validés, tous projets confondus (ventilation des contrats) */
    public static function allApproved(): array
    {
        return BudgetLine::query()
            ->where('is_active', true)
            ->whereHas('budget', fn ($q) => $q->whereNotNull('current_version_id'))
            ->with('budget.fiscalYear', 'budget.project')
            ->get()
            ->sortBy(fn (BudgetLine $l) => $l->budget->project->code.$l->budget->fiscalYear->year.$l->code)
            ->mapWithKeys(fn (BudgetLine $l) => [
                $l->id => "{$l->budget->project->code} · {$l->budget->fiscalYear->year} · {$l->code} — {$l->label}",
            ])->all();
    }
}
