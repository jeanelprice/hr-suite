<?php

namespace App\Support;

use App\Models\BudgetLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Situation d'une ligne budgétaire (RG-01) :
 * Solde disponible = Budgétisé − Pré-engagé − Engagé − Dépensé.
 */
final class LineBalance
{
    public function __construct(
        public readonly BudgetLine $line,
        public readonly BigDecimal $budgeted,
        public readonly BigDecimal $preEngaged,
        public readonly BigDecimal $engaged,
        public readonly BigDecimal $spent,
        public readonly int $alertThreshold = 80,
    ) {}

    public function consumed(): BigDecimal
    {
        return $this->preEngaged->plus($this->engaged)->plus($this->spent);
    }

    public function available(): BigDecimal
    {
        return $this->budgeted->minus($this->consumed());
    }

    /** Taux de consommation en %, arrondi à 0,1 */
    public function consumptionPct(): ?float
    {
        if (! $this->budgeted->isPositive()) {
            return null;
        }

        return $this->consumed()->multipliedBy(100)->dividedBy($this->budgeted, 1, RoundingMode::HalfUp)->toFloat();
    }

    /** Seuil d'alerte atteint (paramètre SEUIL_ALERTE_LIGNE_PCT) */
    public function alert(): bool
    {
        $pct = $this->consumptionPct();

        return $pct !== null && $pct >= $this->alertThreshold;
    }

    /** Solde négatif suite à une dérogation (RG-09) */
    public function overrun(): bool
    {
        return $this->available()->isNegative();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'line_id' => $this->line->id,
            'code' => $this->line->code,
            'label' => $this->line->label,
            'budgeted' => Money::str($this->budgeted),
            'pre_engaged' => Money::str($this->preEngaged),
            'engaged' => Money::str($this->engaged),
            'spent' => Money::str($this->spent),
            'consumed' => Money::str($this->consumed()),
            'available' => Money::str($this->available()),
            'consumption_pct' => $this->consumptionPct(),
            'alert' => $this->alert(),
            'overrun' => $this->overrun(),
        ];
    }
}
