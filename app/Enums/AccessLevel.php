<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Niveau d'action par module
 */
enum AccessLevel: string implements HasLabel, HasColor
{
    case LECTURE = 'LECTURE';
    case CREATION = 'CREATION';
    case VALIDATION = 'VALIDATION';

    public function getLabel(): string
    {
        return match ($this) {
            self::LECTURE => 'Lecture seule',
            self::CREATION => 'Création / saisie',
            self::VALIDATION => 'Validation',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::LECTURE => 'gray',
            self::CREATION => 'info',
            self::VALIDATION => 'success',
        };
    }

    /** Rang du niveau : LECTURE < CREATION < VALIDATION */
    public function rank(): int
    {
        return match ($this) {
            self::LECTURE => 1,
            self::CREATION => 2,
            self::VALIDATION => 3,
        };
    }
}
