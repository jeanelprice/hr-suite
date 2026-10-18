<?php

namespace App\Services;

use App\Enums\ApprovalDecision;
use App\Enums\AppModule;
use App\Enums\DocStatus;
use App\Enums\MovementKind;
use App\Enums\MovementSource;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Notifications\HrNotification;
use App\Support\Money;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;

/**
 * Paiements (RG-04, RG-15, RG-16).
 *  - Préparés par le Financier (le paiement est directement soumis à validation),
 *    approuvés par la Direction Exécutive : deux personnes distinctes (double signature).
 *  - Saisis dans la devise du BC, depuis une caisse ou un compte de même devise.
 *  - Un BC peut être réglé en plusieurs fois ; le cumul ne dépasse jamais son montant.
 *  - À l'approbation, l'engagement est libéré au prorata et la dépense inscrite.
 */
class PaymentService
{
    public function __construct(
        private readonly BudgetLedger $ledger,
        private readonly ApprovalService $approvals,
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
        private readonly CurrencyService $currency,
        private readonly NumberingService $numbering,
        private readonly PurchaseOrderService $orders,
    ) {}

    public function prepare(User $actor, PurchaseOrder $order, TreasuryAccount $account, string|int|float $amount, ?CarbonInterface $paidAt = null, ?string $method = null, ?string $externalRef = null): Payment
    {
        return $this->ledger->run($actor, function () use ($actor, $order, $account, $amount, $paidAt, $method, $externalRef) {
            $order->refresh();
            if ($order->status !== PurchaseOrderStatus::VALIDE) {
                throw new BusinessRuleException('Seul un bon de commande validé (et non clôturé) peut être payé.');
            }
            if (! $account->is_active) {
                throw new BusinessRuleException('Ce compte de trésorerie est désactivé.');
            }
            if ($account->currency !== $order->currency) {
                throw new BusinessRuleException(
                    "Le compte « {$account->name} » est en {$account->currency->value} alors que le bon de commande est en {$order->currency->value}.",
                    'DEVISE_COMPTE',
                );
            }
            $amount = Money::round2($amount);
            if (! $amount->isPositive()) {
                throw new BusinessRuleException('Le montant du paiement doit être strictement positif.');
            }

            // RG-04 : paiements validés + en attente + celui-ci ≤ montant du BC
            $committed = $this->orders->paidTotal($order, [DocStatus::VALIDE, DocStatus::SOUMIS, DocStatus::BROUILLON]);
            if ($committed->plus($amount)->isGreaterThan(Money::of($order->amount))) {
                throw new BusinessRuleException(
                    "Le paiement dépasserait le montant du bon de commande {$order->number} (montant : ".Money::fmt($order->amount)
                    .', déjà payé ou en attente : '.Money::fmt($committed).', reste : '.Money::fmt(Money::of($order->amount)->minus($committed))." {$order->currency->value}).",
                    'PAIEMENT_SUPERIEUR_BC',
                );
            }

            $paidAt ??= now();
            $converted = $this->currency->toBudget($amount, $order->currency, $order->line->budget->currency, $paidAt);
            $payment = Payment::query()->create([
                'number' => $this->numbering->next('PAI'),
                'purchase_order_id' => $order->id,
                'treasury_account_id' => $account->id,
                'currency' => $order->currency,
                'amount' => (string) $amount,
                'exchange_rate' => $converted['exchange_rate'],
                'amount_budget_ccy' => $converted['amount_budget_ccy'],
                'method' => $method,
                'external_ref' => $externalRef,
                'paid_at' => $paidAt->toDateString(),
                'status' => DocStatus::SOUMIS,
                'prepared_by_id' => $actor->id,
            ]);
            $dueAt = $this->approvals->open($payment, $actor);
            $this->audit->record('PAIEMENT_PREPARE', $payment, null, $payment->toArray(), $actor->id);
            $this->notifications->send(
                $this->notifications->validators($actor, AppModule::FINANCES),
                HrNotification::validationRequested("le paiement {$payment->number} (BC {$order->number})", $actor->name, Money::fmt($amount)." {$order->currency->value} depuis « {$account->name} »", $dueAt),
            );

            return $payment;
        });
    }

    public function approve(User $actor, Payment $payment, ?string $comment = null, ?string $derogation = null): Payment
    {
        return $this->ledger->run($actor, function () use ($actor, $payment, $comment, $derogation) {
            $payment->refresh();
            if ($payment->status !== DocStatus::SOUMIS) {
                throw new BusinessRuleException('Seul un paiement soumis peut être approuvé.');
            }
            $order = $payment->purchaseOrder;
            if ($order->status !== PurchaseOrderStatus::VALIDE) {
                throw new BusinessRuleException("Le bon de commande n'est plus ouvert au paiement.");
            }
            $via = $this->approvals->assertCanValidate($actor, $payment->prepared_by_id, AppModule::FINANCES, 'ce paiement');
            $line = $this->ledger->lock($order->line);

            $alreadyPaid = $this->orders->paidTotal($order);
            $orderAmount = Money::of($order->amount);
            if ($alreadyPaid->plus(Money::of($payment->amount))->isGreaterThan($orderAmount)) {
                throw new BusinessRuleException('Le cumul des paiements dépasserait le montant du bon de commande.', 'PAIEMENT_SUPERIEUR_BC');
            }

            // Engagement libéré : au prorata du BC, ou tout le reste si ce paiement solde le BC
            $engagementLeft = $this->orders->remainingEngagement($order);
            $settles = $alreadyPaid->plus(Money::of($payment->amount))->isEqualTo($orderAmount);
            $release = $settles
                ? $engagementLeft
                : Money::min(
                    Money::of($order->amount_budget_ccy)->multipliedBy(Money::of($payment->amount))->dividedBy($orderAmount, 2, RoundingMode::HalfUp),
                    $engagementLeft,
                );

            // Écart de change : la part de dépense au-delà de l'engagement libéré est contrôlée (RG-07)
            $expense = Money::of($payment->amount_budget_ccy);
            $fxDelta = $expense->minus($release);
            $derogationId = null;
            if ($fxDelta->isPositive()) {
                $derogationId = $this->ledger->check($line, $fxDelta, $actor, "Approbation du paiement {$payment->number} (écart de change)", $payment, $derogation)['derogation_id'];
            }

            $this->ledger->post($actor, [
                [
                    'line' => $line, 'kind' => MovementKind::ENGAGEMENT, 'amount' => $release->negated(),
                    'source' => MovementSource::PAIEMENT, 'source_id' => $payment->id,
                    'note' => "{$payment->number} : engagement {$order->number} libéré",
                ],
                [
                    'line' => $line, 'kind' => MovementKind::DEPENSE, 'amount' => $expense,
                    'source' => MovementSource::PAIEMENT, 'source_id' => $payment->id, 'derogation_id' => $derogationId,
                    'note' => "Dépense {$payment->number}".($fxDelta->isZero() ? '' : ' (écart de change : '.Money::fmt($fxDelta).')'),
                ],
            ]);
            $payment->update(['status' => DocStatus::VALIDE, 'approved_by_id' => $actor->id]);
            $this->approvals->decide($payment, $actor, ApprovalDecision::APPROUVE, $comment, $via, $payment->prepared_by_id);
            $this->audit->record('PAIEMENT_APPROUVE', $payment, null, [
                'status' => 'VALIDE', 'engagement_released' => (string) $release, 'fx_delta' => (string) $fxDelta, 'derogation_id' => $derogationId, 'via_delegation' => $via,
            ], $actor->id);
            $this->notifications->send($payment->preparedBy, HrNotification::decision("le paiement {$payment->number}", 'approuvé', $actor->name, $comment));

            return $payment;
        });
    }

    public function reject(User $actor, Payment $payment, string $comment): Payment
    {
        return $this->ledger->run($actor, function () use ($actor, $payment, $comment) {
            $payment->refresh();
            if ($payment->status !== DocStatus::SOUMIS) {
                throw new BusinessRuleException('Seul un paiement soumis peut être rejeté.');
            }
            $via = $this->approvals->assertCanValidate($actor, $payment->prepared_by_id, AppModule::FINANCES, 'ce paiement');
            $payment->update(['status' => DocStatus::REJETE]);
            $this->approvals->decide($payment, $actor, ApprovalDecision::REJETE, $comment, $via, $payment->prepared_by_id);
            $this->audit->record('PAIEMENT_REJETE', $payment, null, ['comment' => $comment], $actor->id);
            $this->notifications->send($payment->preparedBy, HrNotification::decision("le paiement {$payment->number}", 'rejeté', $actor->name, $comment));

            return $payment;
        });
    }
}
