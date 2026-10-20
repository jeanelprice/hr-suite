<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Catégorie d'emploi (Manuel §2.3)
 */
enum EmploymentCategory: string implements HasLabel
{
    case TEMPS_PLEIN = 'TEMPS_PLEIN';
    case TEMPS_PARTIEL = 'TEMPS_PARTIEL';
    case OCCASIONNEL = 'OCCASIONNEL';

    public function getLabel(): string
    {
        return match ($this) {
            self::TEMPS_PLEIN => 'Temps plein',
            self::TEMPS_PARTIEL => 'Temps partiel',
            self::OCCASIONNEL => 'Occasionnel',
        };
    }
}
