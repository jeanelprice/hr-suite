<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Employee;
use App\Services\AttachmentService;
use App\Services\AuditService;
use App\Support\PersonnelAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Seule porte d'accès aux fichiers privés : chaque téléchargement est contrôlé
 * puis tracé dans le journal d'audit (RG-26).
 */
class FileController
{
    public function attachment(Request $request, Attachment $attachment, AttachmentService $attachments, AuditService $audit): Response
    {
        abort_unless($attachments->canView($request->user(), $attachment), 403, "Vous n'avez pas accès à ce document.");
        $disk = Storage::disk(AttachmentService::DISK);
        abort_unless($disk->exists($attachment->path), 404, 'Fichier introuvable.');
        $audit->record('PIECE_CONSULTEE', $attachment, null, ['fichier' => $attachment->original_name]);

        $inline = in_array($attachment->mime_type, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true) && ! $request->boolean('telecharger');

        return $inline
            ? $disk->response($attachment->path, $attachment->original_name)
            : $disk->download($attachment->path, $attachment->original_name);
    }

    public function photo(Request $request, Employee $employee): Response
    {
        abort_unless(PersonnelAccess::canSeePhoto($request->user(), $employee), 403);
        $disk = Storage::disk(AttachmentService::DISK);
        abort_unless($employee->photo_path && $disk->exists($employee->photo_path), 404);

        return $disk->response($employee->photo_path, null, ['Cache-Control' => 'private, max-age=3600']);
    }
}
