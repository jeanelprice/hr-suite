<?php

namespace App\Services;

use App\Enums\ApprovalDecision;
use App\Enums\AppModule;
use App\Enums\BudgetVersionStatus;
use App\Enums\Currency;
use App\Enums\ProjectStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\BudgetLineAmount;
use App\Models\BudgetVersion;
use App\Models\Deadline;
use App\Models\Derogation;
use App\Models\FiscalYear;
use App\Models\Project;
use App\Models\User;
use App\Notifications\HrNotification;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Budgets annuels par projet (RG-12), versions et révisions (RG-10).
 * Une ligne garde la même identité d'une version à l'autre ; seuls ses montants
 * sont rattachés à chaque version.
 */
class BudgetService
{
    public function __construct(
        private readonly BudgetLedger $ledger,
        private readonly ApprovalService $approvals,
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Saisie du budget initial d'un projet pour un exercice (version 1, en brouillon).
     *
     * @param  list<array{code:string, label:string, amount:string|int|float, category?:?string}>  $lines
     */
    public function create(User $actor, Project $project, int $year, Currency $currency, array $lines, ?int $grantId = null): Budget
    {
        return DB::transaction(function () use ($actor, $project, $year, $currency, $lines, $grantId) {
            if ($project->status === ProjectStatus::CLOTURE) {
                throw new BusinessRuleException('Ce projet est clôturé.', 'PROJET_CLOTURE');
            }
            $codes = array_column($lines, 'code');
            if (count($codes) !== count(array_unique($codes))) {
                throw new BusinessRuleException('Deux lignes budgétaires ont le même code.');
            }
            if ($lines === []) {
                throw new BusinessRuleException('Le budget doit comporter au moins une ligne.');
            }
            $fiscalYear = FiscalYear::forYear($year);
            if (Budget::query()->where('project_id', $project->id)->where('fiscal_year_id', $fiscalYear->id)->exists()) {
                throw new BusinessRuleException("Le projet {$project->code} a déjà un budget pour {$year}.", 'BUDGET_EXISTANT');
            }

            $budget = Budget::query()->create([
                'project_id' => $project->id,
                'fiscal_year_id' => $fiscalYear->id,
                'currency' => $currency,
                'grant_id' => $grantId,
            ]);
            $version = $budget->versions()->create(['version_no' => 1, 'reason' => 'Budget initial', 'created_by_id' => $actor->id]);
            foreach ($lines as $l) {
                $line = $budget->lines()->create(['code' => $l['code'], 'label' => $l['label'], 'category' => $l['category'] ?? null]);
                $version->amounts()->create(['budget_line_id' => $line->id, 'amount' => Money::str($l['amount'])]);
            }
            $this->audit->record('BUDGET_CREE', $budget, null, ['budget' => $budget->toArray(), 'version_id' => $version->id, 'lines' => $lines], $actor->id);

            return $budget->fresh();
        });
    }

    /**
     * Révision en cours d'exercice : dérivée de la version en vigueur.
     * Seules les lignes modifiées ou ajoutées sont fournies ; les autres gardent leur montant.
     *
     * @param  list<array{code:string, label:string, amount:string|int|float, category?:?string}>  $changes
     */
    public function revise(User $actor, Budget $budget, string $reason, array $changes): BudgetVersion
    {
        return DB::transaction(function () use ($actor, $budget, $reason, $changes) {
            $budget->refresh();
            $current = $budget->currentVersion;
            if (! $current) {
                throw new BusinessRuleException("Le budget initial n'est pas encore validé : il n'y a rien à réviser.");
            }
            $open = $budget->versions()->whereIn('status', [BudgetVersionStatus::BROUILLON->value, BudgetVersionStatus::SOUMISE->value])->first();
            if ($open) {
                throw new BusinessRuleException("La version {$open->version_no} est déjà en cours de traitement.", 'REVISION_EN_COURS');
            }

            $version = $budget->versions()->create([
                'version_no' => (int) $budget->versions()->max('version_no') + 1,
                'reason' => $reason,
                'derived_from_id' => $current->id,
                'created_by_id' => $actor->id,
            ]);

            // Montants de départ = version en vigueur
            $amounts = $current->amounts()->pluck('amount', 'budget_line_id')->all();

            foreach ($changes as $c) {
                $line = $budget->lines()->where('code', $c['code'])->first()
                    // Nouvelle ligne : inactive tant que la révision n'est pas validée
                    ?? $budget->lines()->create(['code' => $c['code'], 'label' => $c['label'], 'category' => $c['category'] ?? null, 'is_active' => false]);
                $consumed = $this->ledger->balance($line)->consumed();
                if (Money::round2($c['amount'])->isLessThan($consumed)) {
                    throw new BusinessRuleException(
                        "Ligne {$c['code']} : le montant révisé (".Money::fmt($c['amount']).') est inférieur au montant déjà consommé ('.Money::fmt($consumed).') — RG-10.',
                        'REVISION_SOUS_CONSOMME',
                    );
                }
                $amounts[$line->id] = Money::str($c['amount']);
            }
            foreach ($amounts as $lineId => $amount) {
                $version->amounts()->create(['budget_line_id' => $lineId, 'amount' => Money::str($amount)]);
            }
            $this->audit->record('REVISION_CREEE', $version, null, ['version' => $version->toArray(), 'changes' => $changes], $actor->id);

            return $version->fresh();
        });
    }

    public function submit(User $actor, BudgetVersion $version): BudgetVersion
    {
        return DB::transaction(function () use ($actor, $version) {
            $version->refresh();
            if ($version->status !== BudgetVersionStatus::BROUILLON) {
                throw new BusinessRuleException('Seule une version en brouillon peut être soumise.');
            }
            if ($version->created_by_id !== $actor->id) {
                throw new BusinessRuleException("Seul l'auteur de la version peut la soumettre.");
            }
            $version->update(['status' => BudgetVersionStatus::SOUMISE]);
            $dueAt = $this->approvals->open($version, $actor);
            $this->audit->record('VERSION_SOUMISE', $version, null, ['status' => 'SOUMISE'], $actor->id);
            $this->notifications->send(
                $this->notifications->validators($actor, AppModule::FINANCES),
                HrNotification::validationRequested('la '.$version->label(), $actor->name, (string) $version->reason, $dueAt),
            );

            return $version;
        });
    }

    /**
     * Validation par la Direction Exécutive. RG-10 : la version en vigueur est archivée
     * (horodatée, consultable) puis remplacée ; aucune ligne ne descend sous son consommé.
     */
    public function approve(User $actor, BudgetVersion $version, ?string $comment = null): BudgetVersion
    {
        return DB::transaction(function () use ($actor, $version, $comment) {
            $version->refresh()->load('amounts', 'budget.lines', 'budget.project');
            if ($version->status !== BudgetVersionStatus::SOUMISE) {
                throw new BusinessRuleException('Seule une version soumise peut être validée.');
            }
            $via = $this->approvals->assertCanValidate($actor, $version->created_by_id, AppModule::FINANCES, 'cette version budgétaire');
            $budget = $version->budget;

            $amountOf = fn (int $lineId) => $version->amounts->firstWhere('budget_line_id', $lineId)?->amount ?? '0';
            foreach ($budget->lines as $line) {
                $this->ledger->lock($line);
                $consumed = $this->ledger->balance($line)->consumed();
                if (Money::of($amountOf($line->id))->isLessThan($consumed)) {
                    throw new BusinessRuleException(
                        "Ligne {$line->code} : le montant de la version (".Money::fmt($amountOf($line->id)).') est inférieur au consommé ('.Money::fmt($consumed).') — RG-10.',
                        'REVISION_SOUS_CONSOMME',
                    );
                }
            }

            $previousId = $budget->current_version_id;
            if ($previousId) {
                BudgetVersion::query()->whereKey($previousId)->update(['status' => BudgetVersionStatus::ARCHIVEE->value, 'archived_at' => now()]);
            }
            $version->update(['status' => BudgetVersionStatus::EN_VIGUEUR, 'approved_by_id' => $actor->id, 'approved_at' => now()]);
            $budget->update(['current_version_id' => $version->id]);
            BudgetLine::query()->whereIn('id', $version->amounts->pluck('budget_line_id'))->where('is_active', false)->update(['is_active' => true]);

            // RG-13 : le projet devient actif à la validation de son premier budget
            if ($budget->project->status === ProjectStatus::BROUILLON) {
                $budget->project->update(['status' => ProjectStatus::ACTIF, 'activated_at' => now()]);
            }

            // RG-09 : dérogations régularisées dès que la ligne revient à un solde positif
            $regularized = [];
            $budget->unsetRelation('currentVersion');
            foreach (Derogation::query()->whereNull('regularized_at')->whereIn('budget_line_id', $budget->lines->pluck('id'))->get() as $d) {
                $line = $d->line()->with('budget')->first();
                if (! $this->ledger->balance($line)->available()->isNegative()) {
                    $d->update(['regularized_at' => now()]);
                    Deadline::query()->where('subject_type', $d->getMorphClass())->where('subject_id', $d->id)->update(['status' => 'TRAITEE']);
                    $regularized[] = $d->id;
                }
            }

            $this->approvals->decide($version, $actor, ApprovalDecision::APPROUVE, $comment, $via, $version->created_by_id);
            $this->audit->record('VERSION_VALIDEE', $version, ['previous_version_id' => $previousId], [
                'status' => 'EN_VIGUEUR', 'archived_version_id' => $previousId, 'regularized_derogations' => $regularized, 'via_delegation' => $via,
            ], $actor->id);
            $this->notifications->send($version->createdBy, HrNotification::decision('la '.$version->label(), 'validée', $actor->name, $comment));

            return $version->fresh();
        });
    }

    public function reject(User $actor, BudgetVersion $version, string $comment): BudgetVersion
    {
        return DB::transaction(function () use ($actor, $version, $comment) {
            $version->refresh();
            if ($version->status !== BudgetVersionStatus::SOUMISE) {
                throw new BusinessRuleException('Seule une version soumise peut être rejetée.');
            }
            $via = $this->approvals->assertCanValidate($actor, $version->created_by_id, AppModule::FINANCES, 'cette version budgétaire');
            $version->update(['status' => BudgetVersionStatus::REJETEE]);
            $this->approvals->decide($version, $actor, ApprovalDecision::REJETE, $comment, $via, $version->created_by_id);
            $this->audit->record('VERSION_REJETEE', $version, null, ['comment' => $comment], $actor->id);
            $this->notifications->send($version->createdBy, HrNotification::decision('la '.$version->label(), 'rejetée', $actor->name, $comment));

            return $version;
        });
    }

    /** Tableau de bord par ligne : rapport financier principal (cahier des charges §4.5) */
    public function dashboard(Budget $budget): array
    {
        $balances = $budget->lines()->where('is_active', true)->get()->map(fn (BudgetLine $l) => $this->ledger->balance($l));
        $sum = fn (string $m) => Money::sum($balances->map(fn ($b) => $m === 'available' ? $b->available() : $b->{$m}));
        $budgeted = $sum('budgeted');
        $consumed = Money::sum($balances->map(fn ($b) => $b->consumed()));

        return [
            'lines' => $balances->map->toArray()->all(),
            'totals' => [
                'budgeted' => Money::str($budgeted),
                'pre_engaged' => Money::str($sum('preEngaged')),
                'engaged' => Money::str($sum('engaged')),
                'spent' => Money::str($sum('spent')),
                'available' => Money::str($sum('available')),
                'consumption_pct' => $budgeted->isPositive()
                    ? $consumed->multipliedBy(100)->dividedBy($budgeted, 1, \Brick\Math\RoundingMode::HalfUp)->toFloat()
                    : null,
            ],
            'open_derogations' => Derogation::query()->whereNull('regularized_at')->whereIn('budget_line_id', $budget->lines()->pluck('id'))->count(),
        ];
    }

    /** Montant d'une ligne dans une version donnée (consultation des versions archivées) */
    public static function amountIn(BudgetVersion $version, BudgetLine $line): string
    {
        return (string) (BudgetLineAmount::query()->where('budget_version_id', $version->id)->where('budget_line_id', $line->id)->value('amount') ?? '0.00');
    }
}
