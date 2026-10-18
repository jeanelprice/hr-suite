<?php

namespace App\Services;

use App\Enums\MovementKind;
use App\Enums\MovementSource;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Exceptions\BudgetExceededException;
use App\Exceptions\BusinessRuleException;
use App\Models\BudgetLine;
use App\Models\BudgetLineAmount;
use App\Models\BudgetMovement;
use App\Models\Deadline;
use App\Models\Derogation;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\HrNotification;
use App\Support\LineBalance;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Grand livre budgétaire (RG-01 à RG-09).
 *
 * Chaque pré-engagement, engagement ou dépense est une écriture ajoutée à la ligne
 * (table budget_movements, en ajout seul). Le solde se recalcule toujours :
 *   Solde disponible = Budgétisé (version en vigueur) − Pré-engagé − Engagé − Dépensé
 */
class BudgetLedger
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
    ) {}

    /** Verrouille la ligne jusqu'à la fin de la transaction : deux opérations simultanées ne passent pas ensemble */
    public function lock(BudgetLine $line): BudgetLine
    {
        return BudgetLine::query()->lockForUpdate()->with('budget.project', 'budget.fiscalYear')->findOrFail($line->id);
    }

    /** Une opération financière n'est possible que sur un projet actif, un budget validé et un exercice ouvert */
    public function assertOperable(BudgetLine $line): void
    {
        $budget = $line->budget;
        if ($budget->project->status !== ProjectStatus::ACTIF) {
            throw new BusinessRuleException("Le projet {$budget->project->code} n'est pas actif : aucune opération financière n'est possible (RG-13).", 'PROJET_NON_ACTIF');
        }
        if (! $budget->current_version_id) {
            throw new BusinessRuleException("Ce budget n'a pas encore de version validée.", 'BUDGET_NON_VALIDE');
        }
        if ($budget->fiscalYear->is_closed) {
            throw new BusinessRuleException("L'exercice {$budget->fiscalYear->year} est clôturé.", 'EXERCICE_CLOTURE');
        }
        if (! $line->is_active) {
            throw new BusinessRuleException("La ligne {$line->code} n'est pas encore active (révision en attente de validation).", 'LIGNE_INACTIVE');
        }
    }

    public function balance(BudgetLine $line): LineBalance
    {
        $line->loadMissing('budget');
        $versionId = $line->budget->current_version_id;
        $budgeted = $versionId
            ? Money::of(BudgetLineAmount::query()->where('budget_version_id', $versionId)->where('budget_line_id', $line->id)->value('amount'))
            : BigDecimal::zero();

        // Sommes calculées en PHP, au centime près (SQLite additionne en virgule flottante)
        $totals = [MovementKind::PRE_ENGAGEMENT->value => [], MovementKind::ENGAGEMENT->value => [], MovementKind::DEPENSE->value => []];
        foreach (BudgetMovement::query()->where('budget_line_id', $line->id)->get(['kind', 'amount']) as $m) {
            $totals[$m->kind->value][] = $m->amount;
        }

        return new LineBalance(
            line: $line,
            budgeted: $budgeted,
            preEngaged: Money::sum($totals[MovementKind::PRE_ENGAGEMENT->value]),
            engaged: Money::sum($totals[MovementKind::ENGAGEMENT->value]),
            spent: Money::sum($totals[MovementKind::DEPENSE->value]),
            alertThreshold: Setting::number('SEUIL_ALERTE_LIGNE_PCT'),
        );
    }

    /**
     * Contrôle bloquant (RG-07) avant toute réservation. La ligne doit être verrouillée.
     *  - montant ≤ solde : l'opération continue ;
     *  - montant > solde, sans dérogation : BudgetExceededException, rien n'est enregistré ;
     *  - avec dérogation : seule la DE l'accorde, tracée et datée pour régularisation (RG-09) ;
     *  - avec demande de dérogation (saisie/soumission, sans réservation) : acceptée pour être
     *    présentée à la DE ; l'alerte RG-08 part quand même.
     *
     * @return array{derogation_id:?int, balance:LineBalance, derogation_requested:bool}
     */
    public function check(
        BudgetLine $line,
        BigDecimal|string $amount,
        User $actor,
        string $operation,
        Model|array $source,
        ?string $derogation = null,
        ?string $derogationRequest = null,
    ): array {
        $amount = Money::of($amount);
        $balance = $this->balance($line);

        if ($amount->isLessThanOrEqualTo($balance->available())) {
            return ['derogation_id' => null, 'balance' => $balance, 'derogation_requested' => false];
        }

        $info = [
            'line_id' => $line->id,
            'line_code' => $line->code,
            'line_label' => $line->label,
            'project_code' => $line->budget->project->code,
            'currency' => $line->budget->currency->value,
            'requested' => Money::fmt($amount),
            'available' => Money::fmt($balance->available()),
            'operation' => $operation,
        ];

        if ($derogation === null && $derogationRequest !== null) {
            $this->audit->record('DEROGATION_DEMANDEE', $source instanceof Model ? $source : $source, null, $info + ['justification' => $derogationRequest], $actor->id);
            $this->notifications->send($this->notifications->budgetAlertRecipients($actor), HrNotification::budgetExceeded($info, $actor->name, $derogationRequest));

            return ['derogation_id' => null, 'balance' => $balance, 'derogation_requested' => true];
        }

        if ($derogation === null) {
            throw new BudgetExceededException($info);
        }

        if ($actor->role !== Role::DIRECTION_EXECUTIVE) {
            throw new BusinessRuleException('Seule la Direction Exécutive peut accorder une dérogation de dépassement budgétaire (RG-09).', 'DEROGATION_RESERVEE_DE');
        }

        $amountOver = Money::round2($amount->minus(Money::max($balance->available(), BigDecimal::zero())));
        $derogationModel = Derogation::query()->create([
            'budget_line_id' => $line->id,
            'amount_over' => (string) $amountOver,
            'justification' => $derogation,
            'source_type' => $source instanceof Model ? $source->getMorphClass() : $source[0],
            'source_id' => $source instanceof Model ? $source->getKey() : $source[1],
            'granted_by_id' => $actor->id,
            'granted_at' => now(),
            'regularize_by' => now()->addDays(Setting::number('DELAI_REGULARISATION_JOURS')),
        ]);
        $this->audit->record('DEROGATION_ACCORDEE', $derogationModel, null, $derogationModel->toArray() + ['operation' => $operation, 'available_before' => (string) $balance->available()], $actor->id);
        Deadline::query()->create([
            'kind' => 'DEROGATION_REGULARISATION',
            'subject_type' => $derogationModel->getMorphClass(),
            'subject_id' => $derogationModel->id,
            'assignee_id' => $actor->id,
            'due_at' => $derogationModel->regularize_by,
        ]);
        $this->notifications->send(
            $this->notifications->budgetAlertRecipients($actor),
            HrNotification::derogationGranted(Money::fmt($amountOver), $info['currency'], "{$line->code} {$line->label}", $info['project_code'], $derogation, $derogationModel->regularize_by),
        );

        return ['derogation_id' => $derogationModel->id, 'balance' => $balance, 'derogation_requested' => false];
    }

    /**
     * Écrit des mouvements signés (montant négatif = libération).
     *
     * @param  list<array{line:BudgetLine, kind:MovementKind, amount:BigDecimal|string, source:MovementSource, source_id:int, derogation_id?:?int, note?:string}>  $movements
     */
    public function post(User $actor, array $movements): void
    {
        foreach ($movements as $m) {
            $amount = Money::round2($m['amount']);
            if ($amount->isZero()) {
                continue;
            }
            BudgetMovement::query()->create([
                'budget_line_id' => $m['line']->id,
                'kind' => $m['kind'],
                'amount' => (string) $amount,
                'source' => $m['source'],
                'source_id' => $m['source_id'],
                'derogation_id' => $m['derogation_id'] ?? null,
                'note' => $m['note'] ?? null,
                'created_by_id' => $actor->id,
            ]);
        }
    }

    /**
     * Somme des écritures d'un type rattachées à des sources.
     *
     * @param  list<array{0:MovementSource, 1:int}>  $sources
     */
    public function sum(MovementKind $kind, array $sources): BigDecimal
    {
        if ($sources === []) {
            return BigDecimal::zero();
        }
        $amounts = BudgetMovement::query()
            ->where('kind', $kind->value)
            ->where(function ($q) use ($sources) {
                foreach ($sources as [$source, $id]) {
                    $q->orWhere(fn ($qq) => $qq->where('source', $source->value)->where('source_id', $id));
                }
            })
            ->pluck('amount');

        return Money::sum($amounts);
    }

    /**
     * Exécute une opération financière dans une transaction.
     * Si elle est bloquée pour dépassement, l'alerte RG-08 et la trace d'audit sont
     * enregistrées séparément (la transaction de l'opération ayant été annulée).
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(User $actor, Closure $operation): mixed
    {
        try {
            return DB::transaction($operation);
        } catch (BudgetExceededException $e) {
            DB::transaction(function () use ($actor, $e) {
                $this->audit->record('OPERATION_BLOQUEE_BUDGET', ['BudgetLine', $e->info['line_id']], null, $e->info, $actor->id);
                $this->notifications->send($this->notifications->budgetAlertRecipients($actor), HrNotification::budgetExceeded($e->info, $actor->name));
            });
            throw $e;
        }
    }
}
