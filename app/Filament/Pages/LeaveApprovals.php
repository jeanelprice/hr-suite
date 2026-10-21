<?php

namespace App\Filament\Pages;

use App\Enums\EmployeeStatus;
use App\Enums\LeaveStatus;
use App\Enums\Role;
use App\Filament\Support\LeaveForm;
use App\Filament\Support\Ui;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\LeaveService;
use App\Support\PersonnelAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Demandes de congé qui attendent une décision de l'utilisateur connecté :
 * validation du supérieur, puis approbation du RH ou de la DE (RG-23).
 */
class LeaveApprovals extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?int $navigationSort = -1;

    protected static ?string $navigationLabel = 'Congés à valider';

    protected static ?string $title = 'Congés à valider';

    protected static ?string $slug = 'conges-a-valider';

    protected string $view = 'filament.pages.leave-approvals';

    public static function canAccess(): bool
    {
        $u = Auth::user();
        if (! $u) {
            return false;
        }

        return PersonnelAccess::canManage($u)
            || in_array($u->role, [Role::DIRECTION_EXECUTIVE, Role::DIRECTION_EXECUTIVE_ADJOINTE], true)
            || ($u->employee && $u->employee->subordinates()->exists());
    }

    public static function getNavigationBadge(): ?string
    {
        $u = Auth::user();
        $n = $u ? app(LeaveService::class)->awaiting($u)->count() : 0;

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recovery')->label('Accorder une récupération')->icon('heroicon-o-plus-circle')->color('gray')
                ->modalDescription('Pour un travail un jour férié ou en heures longues (Manuel §3.2 et §5.4).')
                ->schema([
                    Select::make('employee_id')->label('Employé')->required()->searchable()
                        ->options(fn () => Employee::query()->where('status', '!=', EmployeeStatus::SORTI->value)
                            ->when(! PersonnelAccess::canManage(Auth::user()), fn ($q) => $q->where('supervisor_id', Auth::user()->employee?->id ?? 0))
                            ->orderBy('last_name')->get()->mapWithKeys(fn (Employee $e) => [$e->id => $e->full_name])),
                    TextInput::make('days')->label('Jours')->numeric()->minValue(0.5)->maxValue(10)->step(0.5)->required(),
                    Textarea::make('reason')->label('Motif')->required()->rows(2)->placeholder('ex. Distribution le dimanche 12/10'),
                ])
                ->modalSubmitActionLabel('Accorder')
                ->action(fn (array $data) => Ui::attempt(fn () => app(LeaveService::class)->grantRecovery(
                    Auth::user(), Employee::query()->findOrFail($data['employee_id']), (float) $data['days'], $data['reason'],
                ), 'Jours de récupération accordés.')),
        ];
    }

    public function table(Table $table): Table
    {
        $s = app(LeaveService::class);

        return $table
            ->query(fn () => LeaveRequest::query()->with('employee', 'type', 'attachments')->whereKey($s->awaiting(Auth::user())->pluck('id')))
            ->defaultSort('start_date')
            ->emptyStateHeading('Rien à valider')
            ->emptyStateDescription('Les demandes de vos collaborateurs apparaîtront ici.')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->columns([
                TextColumn::make('employee.full_name')->label('Employé')->weight('medium')
                    ->description(fn (LeaveRequest $r) => $r->number),
                TextColumn::make('type.label')->label('Type')->wrap(),
                TextColumn::make('period')->label('Période')->state(fn (LeaveRequest $r) => $r->periodLabel()),
                TextColumn::make('days')->label('Jours')->formatStateUsing(fn ($state) => $s->fmt((float) $state)),
                TextColumn::make('balance')->label('Solde')
                    ->state(function (LeaveRequest $r) use ($s) {
                        if (! $r->type->balanceKey()) {
                            return '—';
                        }
                        $b = $s->balance($r->employee, $r->type->balanceKey(), $r->type->balanceKey() === 'ANNUEL' ? $r->leave_year : null);

                        return $s->fmt($b['available']).' j';
                    }),
                TextColumn::make('flags')->label('À noter')->badge()->color('warning')
                    ->state(fn (LeaveRequest $r) => array_values(array_filter([
                        $r->is_advance ? 'Par anticipation' : null,
                        $r->late_notice ? 'Préavis court' : null,
                        $r->type->document_after_days && (float) $r->days >= $r->type->document_after_days && $r->attachments->isEmpty() ? 'Justificatif manquant' : null,
                    ])))->placeholder('—'),
                TextColumn::make('stage')->label('Étape')->badge()->state(fn (LeaveRequest $r) => LeaveForm::stage($r))->color('info')->toggleable(),
                TextColumn::make('reason')->label('Motif')->limit(30)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('validate')->label(fn (LeaveRequest $r) => $r->status === LeaveStatus::SOUMISE ? 'Valider' : 'Approuver')
                    ->icon('heroicon-o-check')->color('success')
                    ->schema([Textarea::make('comment')->label('Commentaire (facultatif)')->rows(2)])
                    ->modalSubmitActionLabel(fn (LeaveRequest $r) => $r->status === LeaveStatus::SOUMISE ? 'Valider et transmettre' : 'Approuver le congé')
                    ->action(fn (LeaveRequest $r, array $data) => Ui::attempt(
                        fn () => $r->status === LeaveStatus::SOUMISE
                            ? $s->supervisorDecision(Auth::user(), $r, true, $data['comment'] ?? null)
                            : $s->finalDecision(Auth::user(), $r, true, $data['comment'] ?? null),
                        $r->status === LeaveStatus::SOUMISE ? 'Demande validée et transmise.' : 'Congé approuvé. L\'employé est prévenu.',
                    )),
                Action::make('reject')->label('Refuser')->icon('heroicon-o-x-mark')->color('danger')
                    ->schema([Textarea::make('comment')->label('Motif du refus')->required()->rows(2)])
                    ->modalSubmitActionLabel('Refuser la demande')
                    ->action(fn (LeaveRequest $r, array $data) => Ui::attempt(
                        fn () => $r->status === LeaveStatus::SOUMISE
                            ? $s->supervisorDecision(Auth::user(), $r, false, $data['comment'])
                            : $s->finalDecision(Auth::user(), $r, false, $data['comment']),
                        'Demande refusée. L\'employé est prévenu.',
                    )),
            ]);
    }
}
