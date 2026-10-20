<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Sexe
 */
enum Gender: string implements HasLabel
{
    case M = 'M';
    case F = 'F';

    public function getLabel(): string
    {
        return match ($this) {
            self::M => 'Masculin',
            self::F => 'Féminin',
        };
    }
}
