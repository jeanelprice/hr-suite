<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Cycle de vie du projet (RG-13)
 */
enum ProjectStatus: string implements HasLabel, HasColor
{
    case BROUILLON = 'BROUILLON';
    case ACTIF = 'ACTIF';
    case CLOTURE = 'CLOTURE';

    public function getLabel(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::ACTIF => 'Actif',
            self::CLOTURE => 'Clôturé',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::BROUILLON => 'gray',
            self::ACTIF => 'success',
            self::CLOTURE => 'danger',
        };
    }
}
