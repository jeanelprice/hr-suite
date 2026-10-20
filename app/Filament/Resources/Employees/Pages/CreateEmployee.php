<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Support\Ui;
use App\Services\EmployeeService;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateEmployee extends CreateRecord
{
    protected static bool $canCreateAnother = false;

    protected static string $resource = EmployeeResource::class;

    protected static ?string $title = 'Nouvel employé';

    /** Création par le service : matricule, historique, ligne hiérarchique */
    protected function handleRecordCreation(array $data): Model
    {
        $record = null;
        $ok = Ui::attempt(function () use ($data, &$record) {
            $record = app(EmployeeService::class)->create(Auth::user(), $data);
        }, 'Dossier créé. Établissez maintenant le contrat de travail.');
        if (! $ok) {
            throw new Halt;
        }

        return $record;
    }

    protected function getCreatedNotification(): ?\Filament\Notifications\Notification
    {
        return null;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
