<?php

namespace App\Services;

use App\Enums\AppModule;
use App\Enums\ApprovalDecision;
use App\Enums\Role;
use App\Exceptions\BusinessRuleException;
use App\Models\Approval;
use App\Models\Delegation;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Fondation C — Circuit de validation et ségrégation des tâches (RG-14).
 *
 * L'auteur d'une opération ne la valide jamais.
 *  - La Direction Exécutive valide.
 *  - La DE adjointe valide en suppléance : quand l'auteur est la Direction Exécutive,
 *    ou quand une délégation en cours le prévoit (absence déclarée).
 *  - Toute autre personne ne valide qu'avec une délégation en cours de la DE.
 */
class ApprovalService
{
    /** @return bool true si la validation se fait par suppléance ou délégation */
    public function assertCanValidate(User $validator, int $creatorId, AppModule $module, string $label): bool
    {
        if ($validator->id === $creatorId) {
            throw new BusinessRuleException(
                "Ségrégation des tâches : l'auteur de {$label} ne peut pas la valider lui-même.",
                'SEGREGATION_DES_TACHES',
            );
        }

        if ($validator->role === Role::DIRECTION_EXECUTIVE) {
            return false;
        }

        $delegated = Delegation::activeFor($validator, $module)->exists();

        if ($validator->role === Role::DIRECTION_EXECUTIVE_ADJOINTE) {
            $creator = User::query()->find($creatorId);
            if ($creator?->role === Role::DIRECTION_EXECUTIVE || $delegated) {
                return true;
            }
            throw new BusinessRuleException(
                'La DE adjointe valide uniquement en suppléance : opération saisie par la Direction Exécutive, ou délégation en cours.',
                'SUPPLEANCE_NON_APPLICABLE',
            );
        }

        if ($delegated) {
            return true;
        }

        throw new BusinessRuleException('Validation réservée à la Direction Exécutive (ou à sa suppléante).', 'VALIDATION_RESERVEE_DE');
    }

    /** Sans lever d'erreur : pour afficher ou masquer les boutons de validation */
    public function canValidate(User $validator, int $creatorId, AppModule $module): bool
    {
        try {
            $this->assertCanValidate($validator, $creatorId, $module, '');

            return true;
        } catch (BusinessRuleException) {
            return false;
        }
    }

    /** Ouvre une demande de validation (une seule en attente par élément) et renvoie l'échéance */
    public function open(Model $approvable, User $requester): \Illuminate\Support\Carbon
    {
        $approvable->approvals()->where('decision', ApprovalDecision::EN_ATTENTE->value)->update([
            'decision' => ApprovalDecision::REJETE->value,
            'comment' => 'Remplacée par une nouvelle soumission',
            'decided_at' => now(),
        ]);
        $dueAt = now()->addHours(Setting::number('DELAI_VALIDATION_HEURES'));
        $approvable->approvals()->create(['requested_by_id' => $requester->id, 'due_at' => $dueAt]);

        return $dueAt;
    }

    public function decide(Model $approvable, User $decider, ApprovalDecision $decision, ?string $comment, bool $viaDelegation, int $requesterId): void
    {
        $data = [
            'decision' => $decision,
            'decided_by_id' => $decider->id,
            'comment' => $comment,
            'via_delegation' => $viaDelegation,
            'decided_at' => now(),
        ];
        $pending = $approvable->approvals()->where('decision', ApprovalDecision::EN_ATTENTE->value)->latest('id')->first();
        $pending
            ? $pending->update($data)
            : $approvable->approvals()->create($data + ['requested_by_id' => $requesterId]);
    }

    /** Clôt une demande en attente (annulation de l'élément) */
    public function cancelPending(Model $approvable, string $reason): void
    {
        $approvable->approvals()->where('decision', ApprovalDecision::EN_ATTENTE->value)->update([
            'decision' => ApprovalDecision::REJETE->value,
            'comment' => $reason,
            'decided_at' => now(),
        ]);
    }

    public static function make(): self
    {
        return app(self::class);
    }

    /** @return class-string<Approval> */
    public static function model(): string
    {
        return Approval::class;
    }
}
