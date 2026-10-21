<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Statut d'une demande de congé (circuit supérieur puis RH ou DE)
 */
enum LeaveStatus: string implements HasLabel, HasColor
{
    case SOUMISE = 'SOUMISE';
    case VALIDEE_SUPERIEUR = 'VALIDEE_SUPERIEUR';
    case APPROUVEE = 'APPROUVEE';
    case REJETEE = 'REJETEE';
    case ANNULEE = 'ANNULEE';

    public function getLabel(): string
    {
        return match ($this) {
            self::SOUMISE => 'Soumise au supérieur',
            self::VALIDEE_SUPERIEUR => 'Validée par le supérieur',
            self::APPROUVEE => 'Approuvée',
            self::REJETEE => 'Rejetée',
            self::ANNULEE => 'Annulée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::SOUMISE => 'warning',
            self::VALIDEE_SUPERIEUR => 'info',
            self::APPROUVEE => 'success',
            self::REJETEE => 'danger',
            self::ANNULEE => 'gray',
        };
    }
}
