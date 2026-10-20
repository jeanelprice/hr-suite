<?php

namespace App\Services;

use App\Enums\AttachmentCategory;
use App\Exceptions\BusinessRuleException;
use App\Models\Attachment;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\DisciplinaryAction;
use App\Models\Employee;
use App\Models\EmployeeFamily;
use App\Models\User;
use App\Support\PersonnelAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Fondation B — Pièces jointes : contrôle du type et de la taille, empreinte SHA-256,
 * versions successives (une nouvelle version ne supprime jamais l'ancienne),
 * accès contrôlé document par document.
 */
class AttachmentService
{
    public const DISK = 'local';

    public const MAX_KB = 10240; // 10 Mo

    public const MIME_TYPES = [
        'application/pdf', 'image/jpeg', 'image/png', 'image/webp',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /** Enregistre un fichier déjà déposé sur le disque privé (par le formulaire) */
    public function store(User $actor, Model $attachable, string $path, AttachmentCategory|string $category, ?string $originalName = null, ?string $title = null, ?Attachment $supersedes = null): Attachment
    {
        if (! $this->canManage($actor, $attachable)) {
            throw new BusinessRuleException("Vous n'êtes pas autorisé à joindre des documents à ce dossier.", 'HABILITATION');
        }
        $disk = Storage::disk(self::DISK);
        if (! $disk->exists($path)) {
            throw new BusinessRuleException('Le fichier envoyé est introuvable ; recommencez le dépôt.', 'PIECE_JOINTE');
        }
        $mime = $disk->mimeType($path) ?: 'application/octet-stream';
        if (! in_array($mime, self::MIME_TYPES, true)) {
            $disk->delete($path);
            throw new BusinessRuleException('Type de fichier refusé. Formats acceptés : PDF, image, Word, Excel.', 'PIECE_JOINTE');
        }
        $size = $disk->size($path);
        if ($size > self::MAX_KB * 1024) {
            $disk->delete($path);
            throw new BusinessRuleException('Fichier trop volumineux (10 Mo au plus).', 'PIECE_JOINTE');
        }

        return Attachment::query()->create([
            'attachable_type' => $attachable->getMorphClass(),
            'attachable_id' => $attachable->getKey(),
            'category' => $category,
            'title' => $title,
            'original_name' => $originalName ?: basename($path),
            'path' => $path,
            'mime_type' => $mime,
            'size' => $size,
            'checksum' => hash('sha256', $disk->get($path)),
            'version' => $supersedes ? $supersedes->version + 1 : 1,
            'supersedes_id' => $supersedes?->id,
            'uploaded_by_id' => $actor->id,
        ]);
    }

    /** Dossier du personnel auquel se rattache la pièce (pour les droits d'accès) */
    public function employeeOf(Model $attachable): ?Employee
    {
        return match (true) {
            $attachable instanceof Employee => $attachable,
            $attachable instanceof EmployeeFamily, $attachable instanceof DisciplinaryAction, $attachable instanceof Contract => $attachable->employee,
            $attachable instanceof ContractAmendment => $attachable->contract->employee,
            default => null,
        };
    }

    /** Les pièces du personnel sont des données sensibles (RG-26) */
    public function canView(User $user, Attachment $attachment): bool
    {
        $attachable = $attachment->attachable;
        if (! $attachable) {
            return false;
        }
        $employee = $this->employeeOf($attachable);

        return $employee ? PersonnelAccess::canSeeSensitive($user, $employee) : $user->isDirectionExecutive();
    }

    public function canManage(User $user, Model $attachable): bool
    {
        return $this->employeeOf($attachable) ? PersonnelAccess::canManage($user) : $user->isDirectionExecutive();
    }
}
