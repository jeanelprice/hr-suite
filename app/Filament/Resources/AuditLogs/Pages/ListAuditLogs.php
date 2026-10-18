<?php

namespace App\Filament\Resources\AuditLogs\Pages;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Services\AuditService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListAuditLogs extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('verify')
                ->label("Vérifier l'intégrité")
                ->icon('heroicon-o-shield-check')
                ->action(function () {
                    $r = app(AuditService::class)->verify();
                    $r['intact']
                        ? Notification::make()->success()->title('Journal intact')->body("{$r['checked']} entrées vérifiées : aucune altération détectée.")->send()
                        : Notification::make()->danger()->title('Altération détectée')->body("La chaîne d'empreintes est rompue à l'entrée n° {$r['broken_at']}.")->persistent()->send();
                }),
        ];
    }
}
