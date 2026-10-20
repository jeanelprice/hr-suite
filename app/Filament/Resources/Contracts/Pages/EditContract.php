<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Support\Ui;
use App\Services\ContractService;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

/** Un brouillon reste modifiable ; un contrat signé ne change que par avenant */
class EditContract extends EditRecord
{
    protected static string $resource = ContractResource::class;

    public function getTitle(): string
    {
        return 'Modifier le contrat '.$this->getRecord()->number;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['allocations'] = $this->getRecord()->allocations->map(fn ($a) => [
            'budget_line_id' => $a->budget_line_id,
            'percentage' => (string) $a->percentage,
            'valid_from' => $a->valid_from?->toDateString(),
            'valid_to' => $a->valid_to?->toDateString(),
        ])->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $ok = Ui::attempt(fn () => app(ContractService::class)->updateDraft(
            Auth::user(), $record, Arr::except($data, ['allocations', 'employee_id']), array_values($data['allocations'] ?? []),
        ), 'Brouillon mis à jour.');
        if (! $ok) {
            throw new Halt;
        }

        return $record;
    }

    protected function getSavedNotification(): ?\Filament\Notifications\Notification
    {
        return null;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
