<?php

namespace App\Models;

use App\Enums\MovementKind;
use App\Enums\MovementSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Écriture du grand livre budgétaire (RG-01), en ajout seul.
 * Montant signé, toujours dans la devise du budget : une conversion
 * pré-engagement → engagement s'écrit (−x PRE_ENGAGEMENT) + (+x ENGAGEMENT).
 */
class BudgetMovement extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => MovementKind::class,
            'source' => MovementSource::class,
            'amount' => 'decimal:2',
        ];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'budget_line_id');
    }

    public function derogation(): BelongsTo
    {
        return $this->belongsTo(Derogation::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
