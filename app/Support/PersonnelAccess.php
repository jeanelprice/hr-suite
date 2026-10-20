<?php

namespace App\Support;

use App\Enums\AccessLevel;
use App\Enums\AppModule;
use App\Models\Employee;
use App\Models\User;

/**
 * Qui voit quoi dans le dossier du personnel (RG-26).
 * - Module RH en lecture : liste, organigramme, situation et photo.
 * - Module RH en création : tenue des dossiers et contrats.
 * - Rubriques sensibles (pièce d'identité, informations médicales, banque, salaire,
 *   discipline) : RH en création, Direction Exécutive et l'intéressé lui-même.
 */
final class PersonnelAccess
{
    public static function canView(?User $user): bool
    {
        return (bool) $user?->canInModule(AppModule::RH, AccessLevel::LECTURE);
    }

    public static function canManage(?User $user): bool
    {
        return (bool) $user?->canInModule(AppModule::RH, AccessLevel::CREATION);
    }

    public static function canSeeSensitive(?User $user, ?Employee $employee = null): bool
    {
        if (! $user) {
            return false;
        }

        return static::canManage($user)
            || $user->isDirectionExecutive()
            || ($employee && $employee->user_id === $user->id);
    }

    /** La photo suit la visibilité du dossier : module RH ou l'intéressé */
    public static function canSeePhoto(?User $user, Employee $employee): bool
    {
        return static::canView($user) || ($user && $employee->user_id === $user->id);
    }
}
