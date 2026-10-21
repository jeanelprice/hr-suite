<?php

namespace App\Models;

use App\Enums\LeaveApprover;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;

/** Type de congé et ses règles (Manuel §5.10), modifiables par le RH */
class LeaveType extends Model
{
    use Audited;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'deducts_balance' => 'boolean', 'own_balance' => 'boolean', 'calendar_days' => 'boolean',
            'requires_confirmation' => 'boolean', 'self_service' => 'boolean', 'is_active' => 'boolean',
            'pay_rate' => 'decimal:4', 'max_days' => 'decimal:2', 'final_approver' => LeaveApprover::class,
        ];
    }

    public static function byCode(string $code): self
    {
        return static::query()->where('code', $code)->firstOrFail();
    }

    /** Solde concerné : ANNUEL, RECUPERATION ou aucun */
    public function balanceKey(): ?string
    {
        return $this->deducts_balance ? 'ANNUEL' : ($this->own_balance ? 'RECUPERATION' : null);
    }
}
