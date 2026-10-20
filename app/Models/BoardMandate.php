<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mandat d'un membre du Conseil d'Administration (3 ans) */
class BoardMandate extends Model
{
    use Audited;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function renewedFrom(): BelongsTo
    {
        return $this->belongsTo(BoardMandate::class, 'renewed_from_id');
    }
}
