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
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\HrNotification;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;

/**
 * Bon de commande (BC), établi sur une demande validée (un BC actif par DA).
 * Validation → le pré-engagement de la DA devient engagement du montant du BC (RG-03).
 * Clôture → le reliquat d'engagement non payé est libéré (RG-05).
 */
class PurchaseOrderService
{
    public function __construct(
        private readonly BudgetLedger $ledger,
        private readonly ApprovalService $approvals,
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
        private readonly CurrencyService $currency,
        private readonly NumberingService $numbering,
        private readonly PurchaseRequestService $requests,
    ) {}

    /** Engagement restant = engagé à la validation − libéré par les paiements et la clôture */
    public function remainingEngagement(PurchaseOrder $order): BigDecimal
    {
        $sources = [[MovementSource::BON_COMMANDE, $order->id]];
        foreach ($order->payments()->pluck('id') as $paymentId) {
            $sources[] = [MovementSource::PAIEMENT, $paymentId];
        }

        return $this->ledger->sum(MovementKind::ENGAGEMENT, $sources);
    }

    /** @param  list<DocStatus>  $statuses */
    public function paidTotal(PurchaseOrder $order, array $statuses = [DocStatus::VALIDE]): BigDecimal
    {
        return Money::sum($order->payments()->whereIn('status', array_map(fn ($s) => $s->value, $statuses))->pluck('amount'));
    }

    /**
     * Contrôle du solde sur l'écart éventuel entre le BC et le pré-engagement restant de la DA.
     *
     * @return array{line:BudgetLine, pre_remaining:BigDecimal, derogation_id:?int}
     */
    private function checkDelta(User $actor, PurchaseRequest $request, string $amountBudgetCcy, string $operation, Model|array $source, ?string $derogation = null, ?string $derogationRequest = null): array
    {
        $line = $this->ledger->lock($request->line);
        $this->ledger->assertOperable($line);
        $preRemaining = $this->requests->remainingPreEngagement($request);
        $delta = Money::of($amountBudgetCcy)->minus($preRemaining);
        $derogationId = null;
        if ($delta->isPositive()) {
            $derogationId = $this->ledger->check($line, $delta, $actor, $operation, $source, $derogation, $derogationRequest)['derogation_id'];
        }

        return ['line' => $line, 'pre_remaining' => $preRemaining, 'derogation_id' => $derogationId];
    }

    /** @param  list<array{description:string, quantity:string|int|float, unit?:?string, unit_price:string|int|float}>  $items */
    public function create(User $actor, PurchaseRequest $request, Supplier $supplier, Currency $currency, array $items, ?string $derogationRequest = null): PurchaseOrder
    {
        return $this->ledger->run($actor, function () use ($actor, $request, $supplier, $currency, $items, $derogationRequest) {
            $request->refresh();
            if ($request->status !== DocStatus::VALIDE) {
                throw new BusinessRuleException('Un bon de commande ne peut être établi que sur une demande validée.');
            }
            if ($request->orders()->whereNotIn('status', [PurchaseOrderStatus::REJETE->value, PurchaseOrderStatus::ANNULE->value])->exists()) {
                throw new BusinessRuleException('Cette demande a déjà un bon de commande actif.', 'BC_EXISTANT');
            }
            if (! $supplier->is_active) {
                throw new BusinessRuleException('Ce fournisseur est désactivé.');
            }
            if ($items === []) {
                throw new BusinessRuleException('Le bon de commande doit comporter au moins un article.');
            }
            $amount = PurchaseRequestService::itemsTotal($items);
            $converted = $this->currency->toBudget($amount, $currency, $request->line->budget->currency);
            $number = $this->numbering->next('BC');
            $this->checkDelta($actor, $request, $converted['amount_budget_ccy'], "Création du bon de commande {$number} (DA {$request->number})", ['PurchaseOrder', $number], derogationRequest: $derogationRequest);

            $order = PurchaseOrder::query()->create([
                'number' => $number,
                'purchase_request_id' => $request->id,
                'budget_line_id' => $request->budget_line_id,
                'supplier_id' => $supplier->id,
                'currency' => $currency,
                'amount' => (string) $amount,
                'exchange_rate' => $converted['exchange_rate'],
                'amount_budget_ccy' => $converted['amount_budget_ccy'],
                'derogation_request' => $derogationRequest,
                'created_by_id' => $actor->id,
            ]);
            foreach ($items as $i) {
                $order->items()->create([
                    'description' => $i['description'],
                    'quantity' => (string) Money::of($i['quantity']),
                    'unit' => $i['unit'] ?? null,
                    'unit_price' => Money::str($i['unit_price']),
                ]);
            }
            $this->audit->record('BC_CREE', $order, null, $order->load('items')->toArray(), $actor->id);

            return $order;
        });
    }

    public function submit(User $actor, PurchaseOrder $order, ?string $derogationRequest = null): PurchaseOrder
    {
        return $this->ledger->run($actor, function () use ($actor, $order, $derogationRequest) {
            $order->refresh();
            if ($order->created_by_id !== $actor->id) {
                throw new BusinessRuleException("Seul l'auteur du bon de commande peut le soumettre.");
            }
            if ($order->status !== PurchaseOrderStatus::BROUILLON) {
                throw new BusinessRuleException('Seul un bon de commande en brouillon peut être soumis.');
            }
            $derogationRequest ??= $order->derogation_request;
            $this->checkDelta($actor, $order->request, $order->amount_budget_ccy, "Soumission du bon de commande {$order->number}", $order, derogationRequest: $derogationRequest);
            $order->update(['status' => PurchaseOrderStatus::SOUMIS, 'derogation_request' => $derogationRequest]);
            $dueAt = $this->approvals->open($order, $actor);
            $this->audit->record('BC_SOUMIS', $order, null, ['status' => 'SOUMIS'], $actor->id);
            $this->notifications->send(
                $this->notifications->validators($actor, AppModule::ACHATS),
                HrNotification::validationRequested("le bon de commande {$order->number}", $actor->name, $order->supplier->name.' — '.Money::fmt($order->amount).' '.$order->currency->value, $dueAt),
            );

            return $order;
        });
    }

    /** Validation : pré-engagement de la DA → engagement du montant du BC (RG-03) */
    public function approve(User $actor, PurchaseOrder $order, ?string $comment = null, ?string $derogation = null): PurchaseOrder
    {
        return $this->ledger->run($actor, function () use ($actor, $order, $comment, $derogation) {
            $order->refresh();
            if ($order->status !== PurchaseOrderStatus::SOUMIS) {
                throw new BusinessRuleException('Seul un bon de commande soumis peut être validé.');
            }
            $via = $this->approvals->assertCanValidate($actor, $order->created_by_id, AppModule::ACHATS, 'ce bon de commande');
            $delta = $this->checkDelta($actor, $order->request, $order->amount_budget_ccy, "Validation du bon de commande {$order->number}", $order, $derogation);

            $this->ledger->post($actor, [
                [
                    'line' => $delta['line'], 'kind' => MovementKind::PRE_ENGAGEMENT, 'amount' => $delta['pre_remaining']->negated(),
                    'source' => MovementSource::BON_COMMANDE, 'source_id' => $order->id,
                    'note' => "{$order->number} : pré-engagement {$order->request->number} converti",
                ],
                [
                    'line' => $delta['line'], 'kind' => MovementKind::ENGAGEMENT, 'amount' => $order->amount_budget_ccy,
                    'source' => MovementSource::BON_COMMANDE, 'source_id' => $order->id,
                    'derogation_id' => $delta['derogation_id'], 'note' => "Engagement {$order->number}",
                ],
            ]);
            $order->update(['status' => PurchaseOrderStatus::VALIDE, 'approved_by_id' => $actor->id, 'approved_at' => now()]);
            $this->approvals->decide($order, $actor, ApprovalDecision::APPROUVE, $comment, $via, $order->created_by_id);
            $this->audit->record('BC_VALIDE', $order, null, [
                'status' => 'VALIDE', 'pre_engagement_converted' => (string) $delta['pre_remaining'], 'derogation_id' => $delta['derogation_id'], 'via_delegation' => $via,
            ], $actor->id);
            $this->notifications->send($order->createdBy, HrNotification::decision("le bon de commande {$order->number}", 'validé', $actor->name, $comment));

            return $order;
        });
    }

    public function reject(User $actor, PurchaseOrder $order, string $comment): PurchaseOrder
    {
        return $this->ledger->run($actor, function () use ($actor, $order, $comment) {
            $order->refresh();
            if ($order->status !== PurchaseOrderStatus::SOUMIS) {
                throw new BusinessRuleException('Seul un bon de commande soumis peut être rejeté.');
            }
            $via = $this->approvals->assertCanValidate($actor, $order->created_by_id, AppModule::ACHATS, 'ce bon de commande');
            $order->update(['status' => PurchaseOrderStatus::REJETE]);
            $this->approvals->decide($order, $actor, ApprovalDecision::REJETE, $comment, $via, $order->created_by_id);
            $this->audit->record('BC_REJETE', $order, null, ['comment' => $comment], $actor->id);
            $this->notifications->send($order->createdBy, HrNotification::decision("le bon de commande {$order->number}", 'rejeté', $actor->name, $comment));

            return $order;
        });
    }

    /** Annulation d'un BC non validé (sans effet budgétaire : la DA garde son pré-engagement) */
    public function cancel(User $actor, PurchaseOrder $order, string $reason): PurchaseOrder
    {
        return $this->ledger->run($actor, function () use ($actor, $order, $reason) {
            $order->refresh();
            if (! in_array($order->status, [PurchaseOrderStatus::BROUILLON, PurchaseOrderStatus::SOUMIS], true)) {
                throw new BusinessRuleException('Un bon de commande validé ne s\'annule pas : il se clôture (le reliquat est alors libéré).');
            }
            if ($order->created_by_id !== $actor->id && ! $actor->isDirectionExecutive()) {
                throw new BusinessRuleException("Seul l'auteur ou la Direction Exécutive peut annuler ce bon de commande.");
            }
            $order->update(['status' => PurchaseOrderStatus::ANNULE, 'close_reason' => $reason]);
            $this->approvals->cancelPending($order, 'Bon de commande annulé');
            $this->audit->record('BC_ANNULE', $order, null, ['reason' => $reason], $actor->id);

            return $order;
        });
    }

    /** Clôture : le reliquat d'engagement non payé retourne au solde (RG-05) */
    public function close(User $actor, PurchaseOrder $order, string $reason): BigDecimal
    {
        return $this->ledger->run($actor, function () use ($actor, $order, $reason) {
            $order->refresh();
            if ($order->status !== PurchaseOrderStatus::VALIDE) {
                throw new BusinessRuleException('Seul un bon de commande validé peut être clôturé.');
            }
            $pending = $order->payments()->whereIn('status', [DocStatus::BROUILLON->value, DocStatus::SOUMIS->value])->count();
            if ($pending > 0) {
                throw new BusinessRuleException("{$pending} paiement(s) en attente de validation sur ce bon de commande.", 'PAIEMENTS_EN_ATTENTE');
            }
            $line = $this->ledger->lock($order->line);
            $remaining = $this->remainingEngagement($order);
            $this->ledger->post($actor, [[
                'line' => $line, 'kind' => MovementKind::ENGAGEMENT, 'amount' => $remaining->negated(),
                'source' => MovementSource::BON_COMMANDE, 'source_id' => $order->id,
                'note' => "Clôture {$order->number} : reliquat libéré",
            ]]);
            $order->update(['status' => PurchaseOrderStatus::CLOTURE, 'closed_at' => now(), 'close_reason' => $reason]);
            $this->audit->record('BC_CLOTURE', $order, null, ['reason' => $reason, 'released_engagement' => (string) $remaining], $actor->id);

            return $remaining;
        });
    }
}
