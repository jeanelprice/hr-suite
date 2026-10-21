<?php

namespace App\Models;

use App\Enums\LeaveStatus;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Demande de congé ou absence enregistrée */
class LeaveRequest extends Model
{
    use Audited;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => LeaveStatus::class,
            'start_date' => 'date', 'end_date' => 'date', 'expected_birth_date' => 'date',
            'start_half' => 'boolean', 'end_half' => 'boolean', 'is_advance' => 'boolean', 'late_notice' => 'boolean',
            'days' => 'decimal:2',
            'supervisor_decided_at' => 'datetime', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function interim(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'interim_employee_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function supervisorDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_decided_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /** Demandes en cours ou acceptées (bloquent un chevauchement) */
    public function scopeActive(Builder $q): Builder
    {
        return $q->whereIn('status', [LeaveStatus::SOUMISE->value, LeaveStatus::VALIDEE_SUPERIEUR->value, LeaveStatus::APPROUVEE->value]);
    }

    public function scopeOverlapping(Builder $q, string $start, string $end): Builder
    {
        return $q->where('start_date', '<=', $end)->where('end_date', '>=', $start);
    }

    public function periodLabel(): string
    {
        $s = $this->start_date->format('d/m/Y').($this->start_half ? ' (après-midi)' : '');
        $e = $this->end_date->format('d/m/Y').($this->end_half ? ' (matin)' : '');

        return $this->start_date->equalTo($this->end_date) && ! $this->start_half && ! $this->end_half ? $s : "du {$s} au {$e}";
    }
}
