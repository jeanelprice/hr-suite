<?php

namespace App\Models;

use App\Enums\LeaveLedgerKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mouvement du registre des congés : ajout seul, comme le grand livre (RG-23) */
class LeaveLedgerEntry extends Model
{
    protected $table = 'leave_ledger';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['kind' => LeaveLedgerKind::class, 'days' => 'decimal:2', 'effective_date' => 'date', 'created_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }
}
