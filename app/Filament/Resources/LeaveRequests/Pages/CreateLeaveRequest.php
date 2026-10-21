<?php

namespace App\Filament\Resources\LeaveRequests\Pages;

use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Support\Ui;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Services\LeaveService;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/** Saisie par le RH pour le compte d'un employé (sans compte, ou demande papier) */
class CreateLeaveRequest extends CreateRecord
{
    protected static bool $canCreateAnother = false;

    protected static string $resource = LeaveRequestResource::class;

    protected static ?string $title = 'Saisir une demande de congé';

    protected function handleRecordCreation(array $data): Model
    {
        $record = null;
        $ok = Ui::attempt(function () use ($data, &$record) {
            $record = app(LeaveService::class)->submit(Auth::user(), Employee::query()->findOrFail($data['employee_id']), LeaveType::query()->findOrFail($data['leave_type_id']), $data);
        }, 'Demande enregistrée et transmise au supérieur.');
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
