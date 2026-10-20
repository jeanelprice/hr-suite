<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Appel d'une mesure disciplinaire
 */
enum AppealStatus: string implements HasLabel, HasColor
{
    case AUCUN = 'AUCUN';
    case EN_COURS = 'EN_COURS';
    case CONFIRMEE = 'CONFIRMEE';
    case ANNULEE = 'ANNULEE';

    public function getLabel(): string
    {
        return match ($this) {
            self::AUCUN => 'Aucun appel',
            self::EN_COURS => 'Appel en cours',
            self::CONFIRMEE => 'Mesure confirmée',
            self::ANNULEE => 'Mesure annulée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::AUCUN => 'gray',
            self::EN_COURS => 'warning',
            self::CONFIRMEE => 'danger',
            self::ANNULEE => 'success',
        };
    }
}
