<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Mesure disciplinaire (Manuel §4.5)
 */
enum DisciplinaryMeasure: string implements HasLabel
{
    case AVERTISSEMENT_VERBAL = 'AVERTISSEMENT_VERBAL';
    case MISE_EN_DEMEURE = 'MISE_EN_DEMEURE';
    case SUSPENSION = 'SUSPENSION';
    case LICENCIEMENT = 'LICENCIEMENT';
    case RENVOI_SOMMAIRE = 'RENVOI_SOMMAIRE';

    public function getLabel(): string
    {
        return match ($this) {
            self::AVERTISSEMENT_VERBAL => 'Avertissement verbal',
            self::MISE_EN_DEMEURE => 'Mise en demeure',
            self::SUSPENSION => 'Suspension sans solde',
            self::LICENCIEMENT => 'Licenciement',
            self::RENVOI_SOMMAIRE => 'Renvoi sommaire',
        };
    }
}
