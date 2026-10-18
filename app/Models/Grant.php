<?php

namespace App\Models;

use App\Enums\Currency;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Subvention d'un bailleur (RG-12 : peut couvrir plusieurs exercices) */
class Grant extends Model
{
    use Audited;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'currency' => Currency::class, 'start_date' => 'date', 'end_date' => 'date'];
    }

    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class);
    }
}
