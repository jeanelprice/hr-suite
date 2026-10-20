<?php

namespace App\Models\Concerns;

use App\Services\AuditService;
use Illuminate\Support\Arr;

/**
 * Trace automatiquement dans le journal d'audit (RG-20) la création,
 * la modification et la suppression des données de référence.
 * Les opérations de workflow (validation, paiement…) sont tracées par les services.
 */
trait Audited
{
    public static function bootAudited(): void
    {
        $base = ['password', 'remember_token', 'updated_at', 'created_at'];
        $hiddenFor = fn ($model) => array_merge($base, property_exists($model, 'auditExclude') ? $model->auditExclude : []);

        static::created(function ($model) use ($hiddenFor) {
            $hidden = $hiddenFor($model);
            app(AuditService::class)->record(class_basename($model).'_CREE', $model, null, Arr::except($model->getAttributes(), $hidden));
        });

        static::updated(function ($model) use ($hiddenFor) {
            $hidden = $hiddenFor($model);
            $changes = Arr::except($model->getChanges(), $hidden);
            if ($changes === []) {
                return;
            }
            $before = Arr::only($model->getOriginal(), array_keys($changes));
            app(AuditService::class)->record(class_basename($model).'_MODIFIE', $model, $before, $changes);
        });

        static::deleted(function ($model) use ($hiddenFor) {
            $hidden = $hiddenFor($model);
            app(AuditService::class)->record(class_basename($model).'_SUPPRIME', $model, Arr::except($model->getAttributes(), $hidden), null);
        });
    }
}
