<?php

namespace App\Filament\Resources\BoardMandates\Pages;

use App\Filament\Resources\BoardMandates\BoardMandateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageBoardMandates extends ManageRecords
{
    protected static string $resource = BoardMandateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Ajouter')->createAnother(false)];
    }
}
