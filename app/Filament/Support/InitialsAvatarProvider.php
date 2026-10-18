<?php

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Avatar aux initiales, généré localement (pas d'appel à un service externe) :
 * l'application reste rapide avec une connexion internet faible.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        $name = (string) ($record->name ?? '?');
        $initials = Str::of($name)->explode(' ')->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" rx="32" fill="#1F3864"/>'
            .'<text x="50%" y="50%" dy=".35em" text-anchor="middle" font-family="Arial, sans-serif" font-size="26" fill="#ffffff">'
            .e($initials ?: '?').'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
