<?php

namespace App\Models;

use App\Enums\ExitReason;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sortie d'un employé : compte rendu et certificat d'autorisation */
class EmployeeExit extends Model
{
    use Audited;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reason' => ExitReason::class,
            'exit_date' => 'date',
            'card_returned' => 'boolean',
            'equipment_returned' => 'boolean',
            'clearance_issued_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function clearanceBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'clearance_by_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }
}
