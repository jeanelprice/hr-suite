<?php

namespace App\Models;

use App\Enums\AccessLevel;
use App\Enums\AppModule;
use App\Enums\Role;
use App\Models\Concerns\Audited;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use Audited, HasFactory, Notifiable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
        ];
    }

    /** Seuls les comptes actifs entrent dans l'application */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function moduleAccesses(): HasMany
    {
        return $this->hasMany(ModuleAccess::class);
    }

    public function isDirectionExecutive(): bool
    {
        return $this->role === Role::DIRECTION_EXECUTIVE;
    }

    /** Niveau d'accès en cours sur un module (null = aucun accès) */
    public function accessLevel(AppModule $module): ?AccessLevel
    {
        $access = $this->moduleAccesses
            ->first(fn (ModuleAccess $a) => $a->module === $module && $a->isActive());

        return $access?->level;
    }

    /**
     * Fondation C : le module est-il accessible avec au moins ce niveau ?
     * Une délégation en cours donne le niveau VALIDATION sur les modules délégués.
     */
    public function canInModule(AppModule $module, AccessLevel $minimum = AccessLevel::LECTURE): bool
    {
        $level = $this->accessLevel($module);
        if ($level && $level->rank() >= $minimum->rank()) {
            return true;
        }

        return $minimum === AccessLevel::VALIDATION
            && $level !== null
            && Delegation::activeFor($this, $module)->exists();
    }
}
