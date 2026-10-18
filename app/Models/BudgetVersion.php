<?php

namespace App\Models;

use App\Enums\BudgetVersionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Version d'un budget : initiale (n°1) puis révisions (RG-10) */
class BudgetVersion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => BudgetVersionStatus::class,
            'approved_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function amounts(): HasMany
    {
        return $this->hasMany(BudgetLineAmount::class);
    }

    public function derivedFrom(): BelongsTo
    {
        return $this->belongsTo(BudgetVersion::class, 'derived_from_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }

    public function label(): string
    {
        return "version {$this->version_no} du budget {$this->budget->label()}";
    }
}
