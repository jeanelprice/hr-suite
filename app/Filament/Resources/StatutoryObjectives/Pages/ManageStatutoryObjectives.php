<?php

namespace App\Filament\Resources\StatutoryObjectives\Pages;

use App\Filament\Resources\StatutoryObjectives\StatutoryObjectiveResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageStatutoryObjectives extends ManageRecords
{
    protected static string $resource = StatutoryObjectiveResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nouvel objectif')];
    }
}
