<?php

namespace App\Models;

use App\Enums\AppealStatus;
use App\Enums\DisciplinaryMeasure;
use App\Enums\OffenceLevel;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Mesure disciplinaire (Manuel §4) */
class DisciplinaryAction extends Model
{
    use Audited;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'offence_level' => OffenceLevel::class,
            'measure' => DisciplinaryMeasure::class,
            'appeal_status' => AppealStatus::class,
            'decided_at' => 'date',
            'counts_until' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
