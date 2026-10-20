<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Type de contrat
 */
enum ContractType: string implements HasLabel
{
    case CDD = 'CDD';
    case CDI = 'CDI';
    case CONSULTANT = 'CONSULTANT';
    case STAGE = 'STAGE';
    case VOLONTARIAT = 'VOLONTARIAT';

    public function getLabel(): string
    {
        return match ($this) {
            self::CDD => 'CDD',
            self::CDI => 'CDI',
            self::CONSULTANT => 'Consultant',
            self::STAGE => 'Stage',
            self::VOLONTARIAT => 'Volontariat',
        };
    }
}
