<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Statut des demandes et paiements
 */
enum DocStatus: string implements HasLabel, HasColor
{
    case BROUILLON = 'BROUILLON';
    case SOUMIS = 'SOUMIS';
    case VALIDE = 'VALIDE';
    case REJETE = 'REJETE';
    case ANNULE = 'ANNULE';

    public function getLabel(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::SOUMIS => 'Soumis',
            self::VALIDE => 'Validé',
            self::REJETE => 'Rejeté',
            self::ANNULE => 'Annulé',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::BROUILLON => 'gray',
            self::SOUMIS => 'warning',
            self::VALIDE => 'success',
            self::REJETE => 'danger',
            self::ANNULE => 'gray',
        };
    }
}
