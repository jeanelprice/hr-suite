<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * État civil
 */
enum MaritalStatus: string implements HasLabel
{
    case CELIBATAIRE = 'CELIBATAIRE';
    case MARIE = 'MARIE';
    case DIVORCE = 'DIVORCE';
    case VEUF = 'VEUF';

    public function getLabel(): string
    {
        return match ($this) {
            self::CELIBATAIRE => 'Célibataire',
            self::MARIE => 'Marié(e)',
            self::DIVORCE => 'Divorcé(e)',
            self::VEUF => 'Veuf / veuve',
        };
    }
}
