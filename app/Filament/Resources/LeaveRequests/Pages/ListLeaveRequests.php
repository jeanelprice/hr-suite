<?php

namespace App\Filament\Resources\LeaveRequests\Pages;

use App\Enums\EmployeeStatus;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Support\Ui;
use App\Models\Employee;
use App\Services\LeaveService;
use App\Support\PersonnelAccess;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListLeaveRequests extends ListRecords
{
    protected static string $resource = LeaveRequestResource::class;

    private function employeeSelect(): Select
    {
        return Select::make('employee_id')->label('Employé')->required()->searchable()
            ->options(fn () => Employee::query()->where('status', '!=', EmployeeStatus::SORTI->value)->orderBy('last_name')->get()
                ->mapWithKeys(fn (Employee $e) => [$e->id => "{$e->full_name} ({$e->matricule})"]));
    }

    protected function getHeaderActions(): array
    {
        $s = app(LeaveService::class);
        $manage = fn () => PersonnelAccess::canManage(Auth::user());

        return [
            ActionGroup::make([
                Action::make('absence')->label('Enregistrer une absence non autorisée')->icon('heroicon-o-exclamation-triangle')->color('danger')
                    ->modalDescription('Salaire retenu au prorata. Deux jours consécutifs alertent le RH et la DE (Manuel §3.2).')
                    ->schema([
                        $this->employeeSelect(),
                        DatePicker::make('start_date')->label('Du')->native(false)->required(),
                        DatePicker::make('end_date')->label('Au (inclus)')->native(false)->required()->afterOrEqual('start_date'),
                        Textarea::make('reason')->label('Circonstances')->required()->rows(2),
                    ])
                    ->modalSubmitActionLabel('Enregistrer')
                    ->action(fn (array $data) => Ui::attempt(fn () => $s->recordAbsence(Auth::user(), Employee::query()->findOrFail($data['employee_id']), $data['start_date'], $data['end_date'], $data['reason']), 'Absence enregistrée.')),
                Action::make('adjust')->label('Ajuster un solde de congé')->icon('heroicon-o-adjustments-horizontal')
                    ->modalDescription('Reprise d\'un solde antérieur à HR Suite, correction, décision de la DE de reporter des jours. Nombre négatif pour retirer.')
                    ->schema([
                        $this->employeeSelect(),
                        TextInput::make('days')->label('Jours (+ ou −)')->numeric()->step(0.5)->required(),
                        Textarea::make('reason')->label('Motif')->required()->rows(2),
                    ])
                    ->modalSubmitActionLabel('Ajuster')
                    ->action(fn (array $data) => Ui::attempt(fn () => $s->adjust(Auth::user(), Employee::query()->findOrFail($data['employee_id']), (float) $data['days'], $data['reason']), 'Solde ajusté.')),
            ])->label('Autres saisies')->icon('heroicon-o-ellipsis-horizontal')->button()->color('gray')->visible($manage),
            CreateAction::make()->label('Saisir une demande')->visible($manage),
        ];
    }
}
