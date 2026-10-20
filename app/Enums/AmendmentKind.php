<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Objet d'un avenant
 */
enum AmendmentKind: string implements HasLabel
{
    case SALAIRE = 'SALAIRE';
    case POSTE = 'POSTE';
    case LIEU = 'LIEU';
    case PROLONGATION_CONTRAT = 'PROLONGATION_CONTRAT';
    case PROLONGATION_ESSAI = 'PROLONGATION_ESSAI';

    public function getLabel(): string
    {
        return match ($this) {
            self::SALAIRE => 'Salaire',
            self::POSTE => 'Poste',
            self::LIEU => 'Lieu d\'affectation',
            self::PROLONGATION_CONTRAT => 'Prolongation du contrat',
            self::PROLONGATION_ESSAI => 'Prolongation de l\'essai',
        };
    }
}
