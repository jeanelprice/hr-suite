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
    ];

    public static function number(string $key): int
    {
        $value = static::query()->find($key)?->value;

        return is_numeric($value) ? (int) $value : (int) (self::DEFAULTS[$key] ?? 0);
    }
}
