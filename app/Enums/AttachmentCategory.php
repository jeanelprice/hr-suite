<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Catégorie de pièce jointe (Fondation B)
 */
enum AttachmentCategory: string implements HasLabel
{
    case CV = 'CV';
    case DIPLOME = 'DIPLOME';
    case CERTIFICAT_MEDICAL = 'CERTIFICAT_MEDICAL';
    case PIECE_IDENTITE = 'PIECE_IDENTITE';
    case PHOTO_IDENTITE = 'PHOTO_IDENTITE';
    case CONTRAT_SIGNE = 'CONTRAT_SIGNE';
    case AVENANT = 'AVENANT';
    case ACTE_NAISSANCE = 'ACTE_NAISSANCE';
    case ACTE_MARIAGE = 'ACTE_MARIAGE';
    case LETTRE = 'LETTRE';
    case DISCIPLINE = 'DISCIPLINE';
    case AUTRE = 'AUTRE';

    /** Pièces exigées à l'embauche (Manuel §2.4.4) */
    public static function requiredAtHiring(): array
    {
        return [self::DIPLOME, self::CERTIFICAT_MEDICAL, self::PIECE_IDENTITE, self::PHOTO_IDENTITE, self::CONTRAT_SIGNE];
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::CV => 'CV',
            self::DIPLOME => 'Diplôme (copie certifiée)',
            self::CERTIFICAT_MEDICAL => 'Certificat médical',
            self::PIECE_IDENTITE => 'Pièce d\'identité',
            self::PHOTO_IDENTITE => 'Photo d\'identité',
            self::CONTRAT_SIGNE => 'Contrat signé',
            self::AVENANT => 'Avenant signé',
            self::ACTE_NAISSANCE => 'Acte de naissance ou d\'adoption',
            self::ACTE_MARIAGE => 'Acte de mariage',
            self::LETTRE => 'Lettre (nomination, confirmation, transfert)',
            self::DISCIPLINE => 'Pièce disciplinaire',
            self::AUTRE => 'Autre',
        };
    }
}
