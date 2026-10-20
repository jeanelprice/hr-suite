<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Support\Ui;
use App\Models\Employee;
use App\Services\ContractService;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

class CreateContract extends CreateRecord
{
    protected static bool $canCreateAnother = false;

    protected static string $resource = ContractResource::class;

    protected static ?string $title = 'Nouveau contrat de travail';

    /** Préremplissage depuis le dossier de l'employé (?employee=) */
    protected function fillForm(): void
    {
        $employee = Employee::query()->find(request()->query('employee'));
        $grade = ContractResource::gradeFor($employee?->position?->salary_category, 1);
        $this->form->fill(array_filter([
            'employee_id' => $employee?->id,
            'position_id' => $employee?->position_id,
            'duty_station_id' => $employee?->duty_station_id,
            'salary_category' => $employee?->position?->salary_category,
            'start_date' => $employee && ! $employee->contracts()->exists() ? $employee->hired_at?->toDateString() : null,
            'probation_end_date' => $employee && ! $employee->contracts()->exists() ? $employee->hired_at?->copy()->addMonths(3)->subDay()->toDateString() : null,
            'base_salary' => $grade ? (string) $grade->amount : null,
        ], fn ($v) => $v !== null) + ['type' => 'CDD', 'currency' => 'USD', 'weekly_hours' => 40, 'salary_step' => 1, 'allocations' => [['percentage' => 100]]]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $record = null;
        $ok = Ui::attempt(function () use ($data, &$record) {
            $record = app(ContractService::class)->create(
                Auth::user(),
                Employee::query()->findOrFail($data['employee_id']),
                Arr::except($data, ['allocations', 'employee_id']),
                array_values($data['allocations'] ?? []),
            );
        }, 'Contrat enregistré en brouillon. Imprimez-le, faites-le signer puis cliquez sur « Contrat signé ».');
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
