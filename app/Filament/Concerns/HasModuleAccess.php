<?php

namespace App\Filament\Concerns;

use App\Enums\AccessLevel;
use App\Enums\AppModule;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Fondation C appliquée aux écrans : une ressource n'apparaît et ne s'ouvre que
 * si l'utilisateur a reçu l'habilitation du module concerné (lecture, création…).
 * La ressource déclare son module dans static::$module.
 *
 * Les écrans de workflow interdisent la modification et la suppression directes :
 * tout passe par les actions (soumettre, valider, annuler…), qui appliquent les règles.
 */
trait HasModuleAccess
{
    protected static function user(): ?User
    {
        return Auth::user();
    }

    public static function canViewAny(): bool
    {
        return (bool) static::user()?->canInModule(static::$module, AccessLevel::LECTURE);
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return (bool) static::user()?->canInModule(static::$module, static::$createLevel ?? AccessLevel::CREATION);
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function module(): AppModule
    {
        return static::$module;
    }
}
