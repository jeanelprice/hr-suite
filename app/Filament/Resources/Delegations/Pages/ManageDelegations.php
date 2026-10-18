<?php

namespace App\Filament\Resources\Delegations\Pages;

use App\Filament\Resources\Delegations\DelegationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Auth;

class ManageDelegations extends ManageRecords
{
    protected static string $resource = DelegationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nouvelle délégation')
                ->mutateDataUsing(fn (array $data) => $data + ['delegator_id' => Auth::id()]),
        ];
    }
}
