<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Ligne budgétaire : identité stable d'une version à l'autre */
class BudgetLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(BudgetMovement::class);
    }

    public function derogations(): HasMany
    {
        return $this->hasMany(Derogation::class);
    }

    public function amounts(): HasMany
    {
        return $this->hasMany(BudgetLineAmount::class);
    }

    public function label(): string
    {
        return "{$this->code} — {$this->label}";
    }
}
