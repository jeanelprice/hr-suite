<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Statut des bons de commande
 */
enum PurchaseOrderStatus: string implements HasLabel, HasColor
{
    case BROUILLON = 'BROUILLON';
    case SOUMIS = 'SOUMIS';
    case VALIDE = 'VALIDE';
    case REJETE = 'REJETE';
    case CLOTURE = 'CLOTURE';
    case ANNULE = 'ANNULE';

    public function getLabel(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::SOUMIS => 'Soumis',
            self::VALIDE => 'Validé',
            self::REJETE => 'Rejeté',
            self::CLOTURE => 'Clôturé',
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
            self::CLOTURE => 'info',
            self::ANNULE => 'gray',
        };
    }
}
