<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Type de compte de trésorerie (RG-16)
 */
enum TreasuryType: string implements HasLabel
{
    case CAISSE = 'CAISSE';
    case BANQUE = 'BANQUE';

    public function getLabel(): string
    {
        return match ($this) {
            self::CAISSE => 'Caisse',
            self::BANQUE => 'Banque',
        };
    }
}
