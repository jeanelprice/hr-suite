<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Motif de sortie
 */
enum ExitReason: string implements HasLabel
{
    case FIN_CONTRAT = 'FIN_CONTRAT';
    case DEMISSION = 'DEMISSION';
    case LICENCIEMENT = 'LICENCIEMENT';
    case RESTRUCTURATION = 'RESTRUCTURATION';
    case DECES = 'DECES';
    case AUTRE = 'AUTRE';

    public function getLabel(): string
    {
        return match ($this) {
            self::FIN_CONTRAT => 'Fin de contrat',
            self::DEMISSION => 'Démission',
            self::LICENCIEMENT => 'Licenciement',
            self::RESTRUCTURATION => 'Restructuration',
            self::DECES => 'Décès',
            self::AUTRE => 'Autre',
        };
    }
}
