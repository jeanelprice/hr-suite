<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Décision de validation
 */
enum ApprovalDecision: string implements HasLabel, HasColor
{
    case EN_ATTENTE = 'EN_ATTENTE';
    case APPROUVE = 'APPROUVE';
    case REJETE = 'REJETE';

    public function getLabel(): string
    {
        return match ($this) {
            self::EN_ATTENTE => 'En attente',
            self::APPROUVE => 'Approuvé',
            self::REJETE => 'Rejeté',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::EN_ATTENTE => 'warning',
            self::APPROUVE => 'success',
            self::REJETE => 'danger',
        };
    }
}
