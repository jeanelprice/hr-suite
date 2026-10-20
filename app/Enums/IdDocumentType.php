<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Type de pièce d'identité
 */
enum IdDocumentType: string implements HasLabel
{
    case CARTE_ELECTEUR = 'CARTE_ELECTEUR';
    case PASSEPORT = 'PASSEPORT';
    case PERMIS = 'PERMIS';
    case AUTRE = 'AUTRE';

    public function getLabel(): string
    {
        return match ($this) {
            self::CARTE_ELECTEUR => 'Carte d\'électeur',
            self::PASSEPORT => 'Passeport',
            self::PERMIS => 'Permis de conduire',
            self::AUTRE => 'Autre',
        };
    }
}
