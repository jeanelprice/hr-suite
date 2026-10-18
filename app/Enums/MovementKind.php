<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Nature d'un mouvement du grand livre (RG-01)
 */
enum MovementKind: string implements HasLabel, HasColor
{
    case PRE_ENGAGEMENT = 'PRE_ENGAGEMENT';
    case ENGAGEMENT = 'ENGAGEMENT';
    case DEPENSE = 'DEPENSE';

    public function getLabel(): string
    {
        return match ($this) {
            self::PRE_ENGAGEMENT => 'Pré-engagement',
            self::ENGAGEMENT => 'Engagement',
            self::DEPENSE => 'Dépense',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PRE_ENGAGEMENT => 'warning',
            self::ENGAGEMENT => 'info',
            self::DEPENSE => 'danger',
        };
    }
}
