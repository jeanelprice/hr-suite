<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Statut de la carte de service (Manuel §3.13)
 */
enum ServiceCardStatus: string implements HasLabel, HasColor
{
    case ACTIVE = 'ACTIVE';
    case PERDUE = 'PERDUE';
    case RESTITUEE = 'RESTITUEE';

    public function getLabel(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::PERDUE => 'Perdue',
            self::RESTITUEE => 'Restituée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::ACTIVE => 'success',
            self::PERDUE => 'danger',
            self::RESTITUEE => 'gray',
        };
    }
}
