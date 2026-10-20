<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Issue de la période d'essai (Manuel §2.4.8)
 */
enum ProbationOutcome: string implements HasLabel, HasColor
{
    case CONFIRME = 'CONFIRME';
    case PROLONGE = 'PROLONGE';
    case RESILIE = 'RESILIE';

    public function getLabel(): string
    {
        return match ($this) {
            self::CONFIRME => 'Confirmation',
            self::PROLONGE => 'Prolongation',
            self::RESILIE => 'Fin du contrat',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::CONFIRME => 'success',
            self::PROLONGE => 'warning',
            self::RESILIE => 'danger',
        };
    }
}
