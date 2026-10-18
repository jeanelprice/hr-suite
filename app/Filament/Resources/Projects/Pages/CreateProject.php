<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use Filament\Resources\Pages\CreateRecord;

/** Le projet naît en Brouillon ; il devient Actif à la validation de son premier budget */
class CreateProject extends CreateRecord
{
    protected static bool $canCreateAnother = false;

    protected static string $resource = ProjectResource::class;

    protected static ?string $title = 'Nouveau projet';

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['status' => \App\Enums\ProjectStatus::BROUILLON];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
