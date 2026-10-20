<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Nature de l'infraction (Manuel §4.4)
 */
enum OffenceLevel: string implements HasLabel, HasColor
{
    case MINEURE = 'MINEURE';
    case MAJEURE = 'MAJEURE';
    case FAUTE_GRAVE = 'FAUTE_GRAVE';

    public function getLabel(): string
    {
        return match ($this) {
            self::MINEURE => 'Mineure',
            self::MAJEURE => 'Majeure',
            self::FAUTE_GRAVE => 'Faute grave',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::MINEURE => 'warning',
            self::MAJEURE => 'danger',
            self::FAUTE_GRAVE => 'danger',
        };
    }
}
