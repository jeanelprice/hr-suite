<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Origine d'un mouvement
 */
enum MovementSource: string implements HasLabel
{
    case DEMANDE_ACHAT = 'DEMANDE_ACHAT';
    case BON_COMMANDE = 'BON_COMMANDE';
    case PAIEMENT = 'PAIEMENT';
    case PAIE = 'PAIE';
    case AJUSTEMENT = 'AJUSTEMENT';

    public function getLabel(): string
    {
        return match ($this) {
            self::DEMANDE_ACHAT => 'Demande d\'achat',
            self::BON_COMMANDE => 'Bon de commande',
            self::PAIEMENT => 'Paiement',
            self::PAIE => 'Paie',
            self::AJUSTEMENT => 'Ajustement',
        };
    }
}
