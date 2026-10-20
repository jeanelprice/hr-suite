<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Statut d'un employé
 */
enum EmployeeStatus: string implements HasLabel, HasColor
{
    case EN_ESSAI = 'EN_ESSAI';
    case CONFIRME = 'CONFIRME';
    case SUSPENDU = 'SUSPENDU';
    case SORTI = 'SORTI';

    public function getLabel(): string
    {
        return match ($this) {
            self::EN_ESSAI => 'En période d\'essai',
            self::CONFIRME => 'Confirmé',
            self::SUSPENDU => 'Suspendu',
            self::SORTI => 'Sorti',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::EN_ESSAI => 'warning',
            self::CONFIRME => 'success',
            self::SUSPENDU => 'danger',
            self::SORTI => 'gray',
        };
    }
}
