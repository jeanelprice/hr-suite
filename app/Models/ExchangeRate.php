<?php

namespace App\Models;

use App\Enums\Currency;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Taux de change saisi par le Financier, ex. 1 USD = 2 850 CDF (RG-11) */
class ExchangeRate extends Model
{
    use Audited;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rate_date' => 'date',
            'from_currency' => Currency::class,
            'to_currency' => Currency::class,
            'rate' => 'decimal:6',
        ];
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by_id');
    }
}
