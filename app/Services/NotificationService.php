<?php

namespace App\Services;

use App\Enums\AccessLevel;
use App\Enums\AppModule;
use App\Enums\Role;
use App\Models\Delegation;
use App\Models\ModuleAccess;
use App\Models\User;
use App\Notifications\HrNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Fondation A — Destinataires et envoi des notifications.
 * L'envoi a lieu après l'enregistrement définitif (fin de transaction) ;
 * un email qui échoue est journalisé sans annuler l'opération.
 */
class NotificationService
{
    /** @param  Collection<int, User>|User  $users */
    public function send(Collection|User $users, HrNotification $notification): void
    {
        $users = ($users instanceof User ? collect([$users]) : $users)
            ->filter(fn (?User $u) => $u && $u->is_active)
            ->unique('id')
            ->values();
        if ($users->isEmpty()) {
            return;
        }

        DB::afterCommit(function () use ($users, $notification) {
            try {
                Notification::send($users, $notification);
            } catch (Throwable $e) {
                Log::error('Envoi de notification impossible : '.$e->getMessage());
            }
        });
    }

    /**
     * RG-08 : alerte « ligne soldée » envoyée simultanément à l'auteur, son supérieur
     * hiérarchique direct, les intervenants Finances (saisie ou validation) et la DE.
     *
     * @return Collection<int, User>
     */
    public function budgetAlertRecipients(User $author): Collection
    {
        $finance = ModuleAccess::query()
            ->where('module', AppModule::FINANCES->value)
            ->whereIn('level', [AccessLevel::CREATION->value, AccessLevel::VALIDATION->value])
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->with('user')
            ->get()
            ->pluck('user');

        return collect([$author, $author->supervisor])
            ->merge($finance)
            ->merge(User::query()->where('role', Role::DIRECTION_EXECUTIVE->value)->get())
            ->filter()
            ->unique('id')
            ->values();
    }

    /**
     * Validateurs à prévenir : la DE, ou la DE adjointe quand l'auteur est la DE (RG-14),
     * plus les délégués en cours sur le module.
     *
     * @return Collection<int, User>
     */
    public function validators(User $author, AppModule $module): Collection
    {
        $base = User::query()
            ->where('role', ($author->role === Role::DIRECTION_EXECUTIVE ? Role::DIRECTION_EXECUTIVE_ADJOINTE : Role::DIRECTION_EXECUTIVE)->value)
            ->get();

        $delegates = Delegation::query()
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->whereJsonContains('modules', $module->value)
            ->with('delegate')
            ->get()
            ->pluck('delegate');

        return $base->merge($delegates)->filter(fn (?User $u) => $u && $u->id !== $author->id)->unique('id')->values();
    }
}
