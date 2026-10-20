<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Statut du contrat
 */
enum ContractStatus: string implements HasLabel, HasColor
{
    case BROUILLON = 'BROUILLON';
    case ACTIF = 'ACTIF';
    case TERMINE = 'TERMINE';
    case RESILIE = 'RESILIE';

    public function getLabel(): string
    {
        return match ($this) {
            self::BROUILLON => 'Brouillon',
            self::ACTIF => 'Actif',
            self::TERMINE => 'Terminé',
            self::RESILIE => 'Résilié',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::BROUILLON => 'gray',
            self::ACTIF => 'success',
            self::TERMINE => 'gray',
            self::RESILIE => 'danger',
        };
    }
}
