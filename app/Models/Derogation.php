<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Dérogation exceptionnelle de dépassement, réservée à la Direction Exécutive (RG-09) */
class Derogation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount_over' => 'decimal:2',
            'granted_at' => 'datetime',
            'regularize_by' => 'datetime',
            'regularized_at' => 'datetime',
        ];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'budget_line_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
