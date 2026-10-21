<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Paramètres modifiables sans développement (cahier des charges §8).
 * Les valeurs par défaut s'appliquent tant qu'aucune valeur n'est enregistrée.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public const DEFAULTS = [
        'SEUIL_ALERTE_LIGNE_PCT' => 80, // alerte visuelle de consommation d'une ligne
        'DELAI_REGULARISATION_JOURS' => 30, // RG-09
        'DELAI_VALIDATION_HEURES' => 72, // RG-19
        'ALERTE_CONTRAT_JOURS' => 30, // RG-19
        'CONGE_JOURS_PAR_MOIS' => 1.5, // RG-23
        'CONGE_ANCIENNETE_TRANCHE_ANS' => 5, // +1 jour par tranche de 5 ans (Code du travail, art. 141)
        'CONGE_ANCIENNETE_JOURS' => 1,
        'CONGE_PLAFOND_ANNUEL' => 24, // haut de la fourchette de la Politique RH §5.3
        'CONGE_AVANCE_PCT' => 50, // avance possible jusqu'à la moitié du droit annuel (Manuel §5.10.1)
        'MALADIE_ALERTE_DE_JOURS' => 14, // Manuel §5.10.2
        'ABSENCE_ALERTE_JOURS' => 2, // Manuel §3.2
    ];

    public static function decimal(string $key): float
    {
        $value = static::query()->find($key)?->value;

        return is_numeric($value) ? (float) $value : (float) (self::DEFAULTS[$key] ?? 0);
    }

    public static function number(string $key): int
    {
        $value = static::query()->find($key)?->value;

        return is_numeric($value) ? (int) $value : (int) (self::DEFAULTS[$key] ?? 0);
    }
}
