<?php

namespace App\Models;

use App\Enums\ServiceCardStatus;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Carte de service (Manuel §3.13) */
class ServiceCard extends Model
{
    use Audited;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ServiceCardStatus::class,
            'issued_at' => 'date',
            'expires_at' => 'date',
            'closed_at' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
