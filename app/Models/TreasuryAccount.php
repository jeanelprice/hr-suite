<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\TreasuryType;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Caisse ou compte bancaire (RG-16) */
class TreasuryAccount extends Model
{
    use Audited;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['type' => TreasuryType::class, 'currency' => Currency::class, 'is_active' => 'boolean'];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
