<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Rôle de l'utilisateur (Fondation C)
 */
enum Role: string implements HasLabel, HasColor
{
    case DIRECTION_EXECUTIVE = 'DIRECTION_EXECUTIVE';
    case DIRECTION_EXECUTIVE_ADJOINTE = 'DIRECTION_EXECUTIVE_ADJOINTE';
    case MEMBRE_CA = 'MEMBRE_CA';
    case COMITE_CONTROLE = 'COMITE_CONTROLE';
    case CHEF_PROJET = 'CHEF_PROJET';
    case RESPONSABLE_RH = 'RESPONSABLE_RH';
    case FINANCIER = 'FINANCIER';
    case LOGISTICIEN = 'LOGISTICIEN';
    case STAFF_TERRAIN = 'STAFF_TERRAIN';
    case ADMINISTRATEUR = 'ADMINISTRATEUR';

    public function getLabel(): string
    {
        return match ($this) {
            self::DIRECTION_EXECUTIVE => 'Direction Exécutive',
            self::DIRECTION_EXECUTIVE_ADJOINTE => 'DE adjointe',
            self::MEMBRE_CA => 'Membre du CA',
            self::COMITE_CONTROLE => 'Comité de Contrôle',
            self::CHEF_PROJET => 'Chef de projet',
            self::RESPONSABLE_RH => 'Responsable RH',
            self::FINANCIER => 'Financier',
            self::LOGISTICIEN => 'Logisticien',
            self::STAFF_TERRAIN => 'Staff terrain',
            self::ADMINISTRATEUR => 'Administrateur technique',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::DIRECTION_EXECUTIVE => 'primary',
            self::DIRECTION_EXECUTIVE_ADJOINTE => 'info',
            self::MEMBRE_CA => 'gray',
            self::COMITE_CONTROLE => 'gray',
            self::CHEF_PROJET => 'success',
            self::RESPONSABLE_RH => 'success',
            self::FINANCIER => 'warning',
            self::LOGISTICIEN => 'success',
            self::STAFF_TERRAIN => 'gray',
            self::ADMINISTRATEUR => 'danger',
        };
    }
}
