<?php

namespace App\Models;

use App\Enums\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Budget annuel d'un projet, dans une devise de référence (RG-11, RG-12) */
class Budget extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['currency' => Currency::class];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(Grant::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(BudgetVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(BudgetVersion::class)->orderByDesc('version_no');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class)->orderBy('code');
    }

    /** Lignes en vigueur (une ligne ajoutée par une révision est inactive tant qu'elle n'est pas validée) */
    public function activeLines(): HasMany
    {
        return $this->hasMany(BudgetLine::class)->where('is_active', true)->orderBy('code');
    }

    public function label(): string
    {
        return "{$this->project->code} — {$this->fiscalYear->year}";
    }
}
