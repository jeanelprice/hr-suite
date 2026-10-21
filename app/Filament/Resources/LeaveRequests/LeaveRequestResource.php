<?php

namespace App\Filament\Resources\LeaveRequests;

use App\Enums\AppModule;
use App\Enums\LeaveStatus;
use App\Filament\Concerns\HasModuleAccess;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Support\LeaveForm;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\LeaveService;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Congés et absences de tout le personnel, pour le RH (RG-23) */
class LeaveRequestResource extends Resource
{
    use HasModuleAccess;

    protected static AppModule $module = AppModule::RH;

    protected static ?string $model = LeaveRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Personnel';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Congés et absences';

    protected static ?string $modelLabel = 'congé';

    protected static ?string $pluralModelLabel = 'Congés et absences';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $slug = 'conges';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components(
            LeaveForm::schema(fn (Get $get) => Employee::query()->find($get('employee_id')), chooseEmployee: true, selfService: false),
        );
    }

    public static function infolist(Schema $schema): Schema
    {
        $s = app(LeaveService::class);

        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('employee.full_name')->label('Employé')->weight('bold')
                    ->url(fn (LeaveRequest $r) => EmployeeResource::getUrl('view', ['record' => $r->employee_id])),
                TextEntry::make('type.label')->label('Type'),
                TextEntry::make('status')->label('Statut')->badge(),
                TextEntry::make('stage')->label('Étape')->state(fn (LeaveRequest $r) => LeaveForm::stage($r)),
                TextEntry::make('period')->label('Période')->state(fn (LeaveRequest $r) => $r->periodLabel())->columnSpan(2),
                TextEntry::make('days')->label('Jours décomptés')->formatStateUsing(fn ($state, LeaveRequest $r) => $s->fmt((float) $state).($r->type->calendar_days ? ' (calendaires)' : ' (ouvrables)')),
                TextEntry::make('leave_year')->label('Année de congé')
                    ->formatStateUsing(function ($state, LeaveRequest $r) use ($s) {
                        [$from, $to] = $s->yearRange($r->employee, (int) $state);

                        return 'du '.$from->format('d/m/Y').' au '.$to->format('d/m/Y');
                    }),
                TextEntry::make('expected_birth_date')->label('Accouchement prévu')->date('d/m/Y')->visible(fn (LeaveRequest $r) => $r->expected_birth_date !== null),
                TextEntry::make('interim.full_name')->label('Intérimaire')->placeholder('—'),
                IconEntry::make('is_advance')->label('Par anticipation')->boolean(),
                IconEntry::make('late_notice')->label('Préavis non respecté')->boolean(),
                TextEntry::make('reason')->label('Motif')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Circuit')->columns(3)->schema([
                TextEntry::make('requestedBy.name')->label('Demandé par')->helperText(fn (LeaveRequest $r) => $r->created_at->format('d/m/Y H:i')),
                TextEntry::make('supervisorDecidedBy.name')->label('Supérieur')->placeholder('En attente')
                    ->helperText(fn (LeaveRequest $r) => $r->supervisor_decided_at?->format('d/m/Y H:i')),
                TextEntry::make('approvedBy.name')->label('Décision finale')->placeholder('En attente')
                    ->helperText(fn (LeaveRequest $r) => $r->approved_at?->format('d/m/Y H:i')),
                TextEntry::make('decision_comment')->label('Commentaire')->placeholder('—')->columnSpanFull(),
                TextEntry::make('cancel_reason')->label('Motif d\'annulation')->visible(fn (LeaveRequest $r) => $r->cancelled_at !== null)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $s = app(LeaveService::class);

        return $table
            ->defaultSort('start_date', 'desc')
            ->columns([
                TextColumn::make('number')->label('N°')->searchable(),
                TextColumn::make('employee.full_name')->label('Employé')->searchable(['last_name', 'first_name']),
                TextColumn::make('type.label')->label('Type'),
                TextColumn::make('period')->label('Période')->state(fn (LeaveRequest $r) => $r->periodLabel()),
                TextColumn::make('days')->label('Jours')->formatStateUsing(fn ($state) => $s->fmt((float) $state)),
                TextColumn::make('stage')->label('Étape')->badge()->state(fn (LeaveRequest $r) => LeaveForm::stage($r))->color(fn (LeaveRequest $r) => $r->status->getColor()),
            ])
            ->filters([
                SelectFilter::make('status')->label('Statut')->options(LeaveStatus::class),
                SelectFilter::make('leave_type_id')->label('Type')->relationship('type', 'label'),
                SelectFilter::make('employee_id')->label('Employé')->relationship('employee', 'last_name')
                    ->getOptionLabelFromRecordUsing(fn (Employee $e) => $e->full_name)->searchable(),
                Filter::make('current')->label('En cours ou à venir')->query(fn (Builder $q) => $q->where('end_date', '>=', today()))->default(),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [\App\Filament\Resources\Employees\RelationManagers\DocumentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLeaveRequests::route('/'),
            'create' => Pages\CreateLeaveRequest::route('/create'),
            'view' => Pages\ViewLeaveRequest::route('/{record}'),
        ];
    }
}
