<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Statut d'une version budgétaire (RG-10)
 */
enum BudgetVersionStatus: string implements HasLabel, HasColor
{
    case BROUILLON = 'BROUILLON';
    case SOUMISE = 'SOUMISE';
    case EN_VIGUEUR = 'EN_VIGUEUR';
    case ARCHIVEE = 'ARCHIVEE';
    case REJETEE = 'REJETEE';

    public function getLabel(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::SOUMISE => 'Soumise',
            self::EN_VIGUEUR => 'En vigueur',
            self::ARCHIVEE => 'Archivée',
            self::REJETEE => 'Rejetée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::BROUILLON => 'gray',
            self::SOUMISE => 'warning',
            self::EN_VIGUEUR => 'success',
            self::ARCHIVEE => 'info',
            self::REJETEE => 'danger',
        };
    }
}
