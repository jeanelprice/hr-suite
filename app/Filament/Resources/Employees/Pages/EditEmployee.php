<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Support\Ui;
use App\Services\EmployeeService;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EditEmployee extends EditRecord
{
    protected static string $resource = EmployeeResource::class;

    public function getTitle(): string
    {
        return 'Modifier le dossier de '.$this->getRecord()->full_name;
    }

    /** Chaque changement de poste, de lieu, de supérieur ou de statut est daté dans l'historique */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $ok = Ui::attempt(fn () => app(EmployeeService::class)->update(Auth::user(), $record, $data), 'Dossier mis à jour.');
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
