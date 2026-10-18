<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Modules métier (habilitations module par module)
 */
enum AppModule: string implements HasLabel
{
    case PROJETS = 'PROJETS';
    case BENEFICIAIRES = 'BENEFICIAIRES';
    case RH = 'RH';
    case PAIE = 'PAIE';
    case FINANCES = 'FINANCES';
    case ACHATS = 'ACHATS';
    case BAILLEURS = 'BAILLEURS';
    case GOUVERNANCE = 'GOUVERNANCE';
    case DOCUMENTS = 'DOCUMENTS';
    case TABLEAU_BORD = 'TABLEAU_BORD';
    case RECRUTEMENT = 'RECRUTEMENT';
    case EVALUATIONS = 'EVALUATIONS';
    case RAPPORTS = 'RAPPORTS';
    case ADMINISTRATION = 'ADMINISTRATION';

    public function getLabel(): string
    {
        return match ($this) {
            self::PROJETS => 'Projets & activités',
            self::BENEFICIAIRES => 'Bénéficiaires & M&E',
            self::RH => 'RH & Contrats',
            self::PAIE => 'Paie',
            self::FINANCES => 'Finances & Budget',
            self::ACHATS => 'Achats & Logistique',
            self::BAILLEURS => 'Bailleurs & Reporting',
            self::GOUVERNANCE => 'Gouvernance & Instances',
            self::DOCUMENTS => 'Documents & Archivage',
            self::TABLEAU_BORD => 'Tableau de bord exécutif',
            self::RECRUTEMENT => 'Recrutement',
            self::EVALUATIONS => 'Évaluations du personnel',
            self::RAPPORTS => 'Rapports sectoriels',
            self::ADMINISTRATION => 'Administration',
        };
    }
}
