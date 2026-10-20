<?php

namespace App\Models;

use App\Enums\AttachmentCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Pièce jointe universelle (Fondation B), versionnée, avec empreinte SHA-256.
 * Le fichier est rangé dans storage/app/private : il n'est jamais accessible
 * directement depuis le site, seulement par la route de téléchargement contrôlée.
 */
class Attachment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['category' => AttachmentCategory::class, 'size' => 'integer', 'version' => 'integer'];
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'supersedes_id');
    }

    public function isCurrent(): bool
    {
        return ! static::query()->where('supersedes_id', $this->id)->exists();
    }

    public function humanSize(): string
    {
        return $this->size >= 1048576
            ? number_format($this->size / 1048576, 1, ',', ' ').' Mo'
            : number_format(max(1, $this->size / 1024), 0, ',', ' ').' Ko';
    }
}
