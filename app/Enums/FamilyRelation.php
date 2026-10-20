<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Lien avec la famille immédiate (Manuel §1.5)
 */
enum FamilyRelation: string implements HasLabel
{
    case CONJOINT = 'CONJOINT';
    case ENFANT = 'ENFANT';
    case PERE = 'PERE';
    case MERE = 'MERE';

    public function getLabel(): string
    {
        return match ($this) {
            self::CONJOINT => 'Conjoint(e)',
            self::ENFANT => 'Enfant',
            self::PERE => 'Père',
            self::MERE => 'Mère',
        };
    }
}
