<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Mouvement du registre des congés (ajout seul)
 */
enum LeaveLedgerKind: string implements HasLabel, HasColor
{
    case ACQUISITION = 'ACQUISITION';
    case ANCIENNETE = 'ANCIENNETE';
    case RECUPERATION = 'RECUPERATION';
    case PRISE = 'PRISE';
    case ANNULATION = 'ANNULATION';
    case AJUSTEMENT = 'AJUSTEMENT';
    case EXPIRATION = 'EXPIRATION';

    public function getLabel(): string
    {
        return match ($this) {
            self::ACQUISITION => 'Acquisition mensuelle',
            self::ANCIENNETE => 'Jours d\'ancienneté',
            self::RECUPERATION => 'Jours de récupération accordés',
            self::PRISE => 'Congé pris',
            self::ANNULATION => 'Annulation d\'un congé',
            self::AJUSTEMENT => 'Ajustement',
            self::EXPIRATION => 'Expiration en fin d\'année',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::ACQUISITION => 'success',
            self::ANCIENNETE => 'success',
            self::RECUPERATION => 'success',
            self::PRISE => 'warning',
            self::ANNULATION => 'info',
            self::AJUSTEMENT => 'gray',
            self::EXPIRATION => 'danger',
        };
    }
}
