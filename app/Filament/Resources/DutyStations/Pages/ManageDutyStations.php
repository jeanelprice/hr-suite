<?php

namespace App\Filament\Resources\DutyStations\Pages;

use App\Filament\Resources\DutyStations\DutyStationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDutyStations extends ManageRecords
{
    protected static string $resource = DutyStationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Ajouter')->createAnother(false)];
    }
}
