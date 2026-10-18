<?php

namespace App\Models;

use App\Enums\AppModule;
use App\Enums\Role;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Délégation temporaire de validation accordée par la Direction Exécutive (RG-14) */
class Delegation extends Model
{
    use Audited;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'modules' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }

    /** Délégations en cours pour un utilisateur et un module, accordées par une DE active */
    public static function activeFor(User $user, AppModule $module): Builder
    {
        return static::query()
            ->where('delegate_id', $user->id)
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->whereJsonContains('modules', $module->value)
            ->whereHas('delegator', fn (Builder $q) => $q->where('role', Role::DIRECTION_EXECUTIVE->value)->where('is_active', true));
    }
}
