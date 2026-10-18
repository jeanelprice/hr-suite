<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Devises (RG-11)
 */
enum Currency: string implements HasLabel
{
    case USD = 'USD';
    case CDF = 'CDF';

    public function getLabel(): string
    {
        return match ($this) {
            self::USD => 'USD — Dollar américain',
            self::CDF => 'CDF — Franc congolais',
        };
    }
}
