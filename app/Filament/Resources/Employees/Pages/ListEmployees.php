<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('orgChart')->label('Organigramme')->icon('heroicon-o-share')->color('gray')
                ->url(fn () => \App\Filament\Pages\OrgChart::getUrl()),
            CreateAction::make()->label('Nouvel employé'),
        ];
    }
}
