<?php

namespace App\Services;

use App\Enums\Currency;
use App\Exceptions\BusinessRuleException;
use App\Models\ExchangeRate;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;

/**
 * RG-11 — Devises. Le taux est saisi par le Financier (référence BCC),
 * par exemple 1 USD = 2 850 CDF. Chaque opération enregistre le facteur appliqué :
 * un changement de taux ultérieur ne modifie jamais une opération passée.
 */
class CurrencyService
{
    /**
     * Convertit un montant saisi vers la devise du budget.
     *
     * @return array{amount_budget_ccy:string, exchange_rate:string}
     */
    public function toBudget(BigDecimal|string $amount, Currency $from, Currency $budgetCurrency, ?CarbonInterface $date = null): array
    {
        $amount = Money::of($amount);
        if ($from === $budgetCurrency) {
            return ['amount_budget_ccy' => Money::str($amount), 'exchange_rate' => '1'];
        }
        $date ??= now();

        $direct = $this->latest($from, $budgetCurrency, $date);
        if ($direct) {
            $rate = Money::of($direct->rate);

            return [
                'amount_budget_ccy' => Money::str($amount->multipliedBy($rate)),
                'exchange_rate' => (string) $rate->toScale(10, RoundingMode::HalfUp),
            ];
        }

        $inverse = $this->latest($budgetCurrency, $from, $date);
        if ($inverse) {
            $rate = Money::of($inverse->rate);

            return [
                'amount_budget_ccy' => (string) $amount->dividedBy($rate, 2, RoundingMode::HalfUp),
                'exchange_rate' => (string) BigDecimal::one()->dividedBy($rate, 10, RoundingMode::HalfUp),
            ];
        }

        throw new BusinessRuleException(
            "Aucun taux de change {$from->value}/{$budgetCurrency->value} saisi à cette date. Le Financier doit d'abord enregistrer le taux du jour.",
            'TAUX_MANQUANT',
        );
    }

    private function latest(Currency $from, Currency $to, CarbonInterface $date): ?ExchangeRate
    {
        return ExchangeRate::query()
            ->where('from_currency', $from->value)
            ->where('to_currency', $to->value)
            ->whereDate('rate_date', '<=', $date->toDateString())
            ->orderByDesc('rate_date')
            ->first();
    }
}
