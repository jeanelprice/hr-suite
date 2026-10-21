<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Approbateur final d'un type de congé
 */
enum LeaveApprover: string implements HasLabel
{
    case RH = 'RH';
    case DE = 'DE';

    public function getLabel(): string
    {
        return match ($this) {
            self::RH => 'Responsable RH',
            self::DE => 'Direction Exécutive',
        };
    }
}
