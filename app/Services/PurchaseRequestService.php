<?php

namespace App\Services;

use App\Enums\ApprovalDecision;
use App\Enums\AppModule;
use App\Enums\Currency;
use App\Enums\DocStatus;
use App\Enums\MovementKind;
use App\Enums\MovementSource;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\BudgetLine;
use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Notifications\HrNotification;
use App\Support\Money;
use Brick\Math\BigDecimal;

/**
 * Demande d'achat (DA) : Brouillon → Soumise → Validée (pré-engagement, RG-02) ou Rejetée ; annulable.
 * Le solde de la ligne est contrôlé à la saisie, à la soumission et à la validation (RG-07).
 */
class PurchaseRequestService
{
    public function __construct(
        private readonly BudgetLedger $ledger,
        private readonly ApprovalService $approvals,
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
        private readonly CurrencyService $currency,
        private readonly NumberingService $numbering,
    ) {}

    /** @param  list<array{description:string, quantity:string|int|float, unit?:?string, unit_price:string|int|float}>  $items */
    public static function itemsTotal(array $items): BigDecimal
    {
        return Money::round2(Money::sum(array_map(
            fn ($i) => Money::of($i['quantity'])->multipliedBy(Money::of($i['unit_price'])),
            $items,
        )));
    }

    /** Pré-engagement encore porté par la DA (créé à sa validation, libéré par le BC ou l'annulation) */
    public function remainingPreEngagement(PurchaseRequest $request): BigDecimal
    {
        $sources = [[MovementSource::DEMANDE_ACHAT, $request->id]];
        foreach ($request->orders()->pluck('id') as $orderId) {
            $sources[] = [MovementSource::BON_COMMANDE, $orderId];
        }

        return $this->ledger->sum(MovementKind::PRE_ENGAGEMENT, $sources);
    }

    /** @param  list<array{description:string, quantity:string|int|float, unit?:?string, unit_price:string|int|float}>  $items */
    public function create(User $actor, Project $project, BudgetLine $line, string $purpose, Currency $currency, array $items, ?string $derogationRequest = null): PurchaseRequest
    {
        return $this->ledger->run($actor, function () use ($actor, $project, $line, $purpose, $currency, $items, $derogationRequest) {
            $line = $this->ledger->lock($line);
            if ($line->budget->project_id !== $project->id) {
                throw new BusinessRuleException("Cette ligne budgétaire n'appartient pas au projet indiqué.");
            }
            if ($items === []) {
                throw new BusinessRuleException('La demande doit comporter au moins un article.');
            }
            $this->ledger->assertOperable($line);

            $estimated = self::itemsTotal($items);
            $converted = $this->currency->toBudget($estimated, $currency, $line->budget->currency);
            $number = $this->numbering->next('DA');

            $this->ledger->check($line, $converted['amount_budget_ccy'], $actor, "Création de la demande d'achat {$number}", ['PurchaseRequest', $number], derogationRequest: $derogationRequest);

            $request = PurchaseRequest::query()->create([
                'number' => $number,
                'project_id' => $project->id,
                'budget_line_id' => $line->id,
                'requester_id' => $actor->id,
                'purpose' => $purpose,
                'currency' => $currency,
                'estimated_amount' => (string) $estimated,
                'exchange_rate' => $converted['exchange_rate'],
                'amount_budget_ccy' => $converted['amount_budget_ccy'],
                'derogation_request' => $derogationRequest,
            ]);
            foreach ($items as $i) {
                $request->items()->create([
                    'description' => $i['description'],
                    'quantity' => (string) Money::of($i['quantity']),
                    'unit' => $i['unit'] ?? null,
                    'unit_price' => Money::str($i['unit_price']),
                ]);
            }
            $this->audit->record('DA_CREEE', $request, null, $request->load('items')->toArray(), $actor->id);

            return $request;
        });
    }

    public function submit(User $actor, PurchaseRequest $request, ?string $derogationRequest = null): PurchaseRequest
    {
        return $this->ledger->run($actor, function () use ($actor, $request, $derogationRequest) {
            $request->refresh();
            if ($request->requester_id !== $actor->id) {
                throw new BusinessRuleException("Seul l'auteur de la demande peut la soumettre.");
            }
            if ($request->status !== DocStatus::BROUILLON) {
                throw new BusinessRuleException('Seule une demande en brouillon peut être soumise.');
            }
            $line = $this->ledger->lock($request->line);
            $this->ledger->assertOperable($line);
            $derogationRequest ??= $request->derogation_request;
            $this->ledger->check($line, $request->amount_budget_ccy, $actor, "Soumission de la demande d'achat {$request->number}", $request, derogationRequest: $derogationRequest);

            $request->update(['status' => DocStatus::SOUMIS, 'submitted_at' => now(), 'derogation_request' => $derogationRequest]);
            $dueAt = $this->approvals->open($request, $actor);
            $this->audit->record('DA_SOUMISE', $request, null, ['status' => 'SOUMIS'], $actor->id);
            $this->notifications->send(
                $this->notifications->validators($actor, AppModule::ACHATS),
                HrNotification::validationRequested("la demande d'achat {$request->number}", $actor->name, $request->purpose.' — '.Money::fmt($request->estimated_amount).' '.$request->currency->value, $dueAt),
            );

            return $request;
        });
    }

    /** Validation : crée le pré-engagement (RG-02). Dépassement uniquement par dérogation de la DE (RG-09). */
    public function approve(User $actor, PurchaseRequest $request, ?string $comment = null, ?string $derogation = null): PurchaseRequest
    {
        return $this->ledger->run($actor, function () use ($actor, $request, $comment, $derogation) {
            $request->refresh();
            if ($request->status !== DocStatus::SOUMIS) {
                throw new BusinessRuleException('Seule une demande soumise peut être validée.');
            }
            $via = $this->approvals->assertCanValidate($actor, $request->requester_id, AppModule::ACHATS, "cette demande d'achat");
            $line = $this->ledger->lock($request->line);
            $this->ledger->assertOperable($line);
            $check = $this->ledger->check($line, $request->amount_budget_ccy, $actor, "Validation de la demande d'achat {$request->number}", $request, derogation: $derogation);

            $this->ledger->post($actor, [[
                'line' => $line, 'kind' => MovementKind::PRE_ENGAGEMENT, 'amount' => $request->amount_budget_ccy,
                'source' => MovementSource::DEMANDE_ACHAT, 'source_id' => $request->id,
                'derogation_id' => $check['derogation_id'], 'note' => "Pré-engagement {$request->number}",
            ]]);
            $request->update(['status' => DocStatus::VALIDE, 'approved_by_id' => $actor->id, 'approved_at' => now()]);
            $this->approvals->decide($request, $actor, ApprovalDecision::APPROUVE, $comment, $via, $request->requester_id);
            $this->audit->record('DA_VALIDEE', $request, null, ['status' => 'VALIDE', 'derogation_id' => $check['derogation_id'], 'via_delegation' => $via], $actor->id);
            $this->notifications->send($request->requester, HrNotification::decision("la demande d'achat {$request->number}", 'validée', $actor->name, $comment));

            return $request;
        });
    }

    public function reject(User $actor, PurchaseRequest $request, string $comment): PurchaseRequest
    {
        return $this->ledger->run($actor, function () use ($actor, $request, $comment) {
            $request->refresh();
            if ($request->status !== DocStatus::SOUMIS) {
                throw new BusinessRuleException('Seule une demande soumise peut être rejetée.');
            }
            $via = $this->approvals->assertCanValidate($actor, $request->requester_id, AppModule::ACHATS, "cette demande d'achat");
            $request->update(['status' => DocStatus::REJETE]);
            $this->approvals->decide($request, $actor, ApprovalDecision::REJETE, $comment, $via, $request->requester_id);
            $this->audit->record('DA_REJETEE', $request, null, ['comment' => $comment], $actor->id);
            $this->notifications->send($request->requester, HrNotification::decision("la demande d'achat {$request->number}", 'rejetée', $actor->name, $comment));

            return $request;
        });
    }

    /** Annulation ; si la DA était validée et sans BC actif, son pré-engagement est libéré (RG-05) */
    public function cancel(User $actor, PurchaseRequest $request, string $reason): PurchaseRequest
    {
        return $this->ledger->run($actor, function () use ($actor, $request, $reason) {
            $request->refresh();
            if ($request->requester_id !== $actor->id && ! $actor->isDirectionExecutive()) {
                throw new BusinessRuleException("Seul l'auteur ou la Direction Exécutive peut annuler cette demande.");
            }
            if (! in_array($request->status, [DocStatus::BROUILLON, DocStatus::SOUMIS, DocStatus::VALIDE], true)) {
                throw new BusinessRuleException('Cette demande ne peut plus être annulée.');
            }
            if ($request->orders()->whereNotIn('status', [PurchaseOrderStatus::REJETE->value, PurchaseOrderStatus::ANNULE->value])->exists()) {
                throw new BusinessRuleException('Un bon de commande existe pour cette demande : annulez ou clôturez-le d\'abord.');
            }
            $released = BigDecimal::zero();
            if ($request->status === DocStatus::VALIDE) {
                $line = $this->ledger->lock($request->line);
                $released = $this->remainingPreEngagement($request);
                $this->ledger->post($actor, [[
                    'line' => $line, 'kind' => MovementKind::PRE_ENGAGEMENT, 'amount' => $released->negated(),
                    'source' => MovementSource::DEMANDE_ACHAT, 'source_id' => $request->id,
                    'note' => "Annulation {$request->number} : pré-engagement libéré",
                ]]);
            }
            $request->update(['status' => DocStatus::ANNULE]);
            $this->approvals->cancelPending($request, 'Demande annulée');
            $this->audit->record('DA_ANNULEE', $request, null, ['reason' => $reason, 'released' => (string) $released], $actor->id);

            return $request;
        });
    }
}
