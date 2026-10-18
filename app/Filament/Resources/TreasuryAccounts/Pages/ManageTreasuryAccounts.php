<?php

namespace App\Filament\Resources\TreasuryAccounts\Pages;

use App\Filament\Resources\TreasuryAccounts\TreasuryAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTreasuryAccounts extends ManageRecords
{
    protected static string $resource = TreasuryAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nouveau compte')];
    }
}
