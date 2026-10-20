<?php

namespace App\Filament\Resources\Contracts;

use App\Enums\AppModule;
use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\Currency;
use App\Enums\EmployeeStatus;
use App\Filament\Concerns\HasModuleAccess;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Support\BudgetLineOptions;
use App\Filament\Support\Ui;
use App\Models\Contract;
use App\Models\DutyStation;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Project;
use App\Models\SalaryGrade;
use App\Support\PersonnelAccess;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/** Contrats de travail (RG-22) : brouillon → signé (actif) → terminé ou résilié */
class ContractResource extends Resource
{
    use HasModuleAccess;

    protected static AppModule $module = AppModule::RH;

    protected static ?string $model = Contract::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Personnel';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'contrat';

    protected static ?string $pluralModelLabel = 'Contrats';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $recordTitleAttribute = 'number';

    public static function canEdit(Model $record): bool
    {
        return static::canCreate() && $record->status === ContractStatus::BROUILLON;
    }

    /** Salaire de l'échelle pour une catégorie et une étape, à la date de début */
    public static function gradeFor(?string $category, mixed $step, ?string $date = null): ?SalaryGrade
    {
        if (! $category || ! $step) {
            return null;
        }

        return SalaryGrade::query()
            ->where('category', $category)->where('step', (int) $step)
            ->where('effective_from', '<=', $date ?: now()->toDateString())
            ->orderByDesc('effective_from')->first();
    }

    public static function fillSalaryFromGrade(Get $get, Set $set): void
    {
        $grade = static::gradeFor($get('salary_category'), $get('salary_step'), $get('start_date'));
        if ($grade) {
            $set('base_salary', (string) $grade->amount);
            $set('currency', $grade->currency);
        }
    }

    public static function form(Schema $schema): Schema
    {
        $isCdi = fn (Get $get) => ($get('type') instanceof ContractType ? $get('type') : ContractType::tryFrom((string) $get('type'))) === ContractType::CDI;

        return $schema->columns(1)->components([
            Section::make('Parties et durée')->schema([
                Grid::make(['default' => 1, 'md' => 3])->schema([
                    Select::make('employee_id')->label('Employé')->required()
                        ->options(fn () => Employee::query()->where('status', '!=', EmployeeStatus::SORTI->value)->orderBy('last_name')->get()->mapWithKeys(fn (Employee $e) => [$e->id => "{$e->full_name} ({$e->matricule})"]))
                        ->searchable()->disabledOn('edit')
                        ->live()->afterStateUpdated(function ($state, Set $set) {
                            $e = Employee::query()->find($state);
                            if ($e) {
                                $set('position_id', $e->position_id);
                                $set('duty_station_id', $e->duty_station_id);
                                $set('start_date', $e->contracts()->exists() ? null : $e->hired_at?->toDateString());
                            }
                        }),
                    Select::make('type')->label('Type de contrat')->options(ContractType::class)->default(ContractType::CDD)->required()->live(),
                    Select::make('project_id')->label('Projet qui finance le poste')
                        ->options(fn () => Project::query()->orderBy('code')->get()->mapWithKeys(fn (Project $p) => [$p->id => "{$p->code} — {$p->title} (fin {$p->end_date->format('d/m/Y')})"]))
                        ->searchable()->helperText('Un CDD ne peut pas dépasser la fin du projet (Manuel §2.4.4).'),
                    DatePicker::make('start_date')->label('Début')->native(false)->displayFormat('d/m/Y')->required()->live(onBlur: true)
                        ->afterStateUpdated(function ($state, Set $set, Get $get) {
                            if ($state && ! $get('probation_end_date')) {
                                $set('probation_end_date', Carbon::parse($state)->addMonths(3)->subDay()->toDateString());
                            }
                        }),
                    DatePicker::make('end_date')->label('Fin')->native(false)->displayFormat('d/m/Y')
                        ->hidden($isCdi)->required(fn (Get $get) => ! $isCdi($get))->afterOrEqual('start_date'),
                    DatePicker::make('probation_end_date')->label('Fin de la période d\'essai')->native(false)->displayFormat('d/m/Y')
                        ->helperText('3 à 6 mois (Manuel §2.4.8). Laisser vide s\'il n\'y a pas d\'essai.')->after('start_date'),
                ]),
            ]),
            Section::make('Poste et rémunération')->schema([
                Grid::make(['default' => 1, 'md' => 3])->schema([
                    Select::make('position_id')->label('Poste')->options(fn () => Position::query()->orderBy('title')->pluck('title', 'id'))->searchable()->required()
                        ->live()->afterStateUpdated(function ($state, Set $set) {
                            $set('salary_category', Position::query()->find($state)?->salary_category);
                        }),
                    Select::make('duty_station_id')->label("Lieu d'affectation")->options(fn () => DutyStation::query()->orderBy('name')->pluck('name', 'id'))->required(),
                    TextInput::make('weekly_hours')->label('Heures par semaine')->numeric()->integer()->default(40)->minValue(1)->maxValue(48)->required(),
                    TextInput::make('salary_category')->label('Catégorie salariale')->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => static::fillSalaryFromGrade($get, $set)),
                    TextInput::make('salary_step')->label('Étape')->numeric()->integer()->minValue(1)->default(1)->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => static::fillSalaryFromGrade($get, $set)),
                    TextInput::make('base_salary')->label('Salaire de base brut mensuel')->numeric()->minValue(0.01)->required()
                        ->helperText('Rempli depuis l\'échelle salariale ; modifiable.'),
                    Select::make('currency')->label('Devise')->options(Currency::class)->default('USD')->required(),
                ]),
            ]),
            Section::make('Ventilation du coût sur les lignes budgétaires')
                ->description('Totalise exactement 100 % sur chaque période. Aucun engagement n\'est créé à la signature : la paie engage chaque mois (RG-06).')
                ->schema([
                    Repeater::make('allocations')->hiddenLabel()
                        ->schema([
                            Select::make('budget_line_id')->label('Ligne budgétaire')->options(fn () => BudgetLineOptions::allApproved())->searchable()->required()->columnSpan(2),
                            TextInput::make('percentage')->label('Part (%)')->numeric()->minValue(0.01)->maxValue(100)->required()->default(100)->suffix('%'),
                            DatePicker::make('valid_from')->label('À partir du')->native(false)->placeholder('Début du contrat'),
                            DatePicker::make('valid_to')->label('Jusqu\'au')->native(false)->placeholder('Fin du contrat'),
                        ])
                        ->columns(5)->defaultItems(1)->minItems(1)->addActionLabel('Ajouter une ligne')->reorderable(false),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $sensitive = fn (Contract $record) => PersonnelAccess::canSeeSensitive(Auth::user(), $record->employee);

        return $schema->columns(1)->components([
            Section::make()->schema([
                Grid::make(['default' => 1, 'md' => 4])->schema([
                    TextEntry::make('employee.full_name')->label('Employé')->weight('bold')
                        ->url(fn (Contract $record) => EmployeeResource::getUrl('view', ['record' => $record->employee_id])),
                    TextEntry::make('type')->label('Type'),
                    TextEntry::make('status')->label('Statut')->badge(),
                    TextEntry::make('project.code')->label('Projet')->placeholder('—'),
                    TextEntry::make('start_date')->label('Début')->date('d/m/Y'),
                    TextEntry::make('current_end')->label('Fin en vigueur')->placeholder('Indéterminée')
                        ->state(fn (Contract $record) => $record->termsAt()['end_date'])->date('d/m/Y'),
                    TextEntry::make('current_probation')->label('Fin d\'essai en vigueur')->placeholder('Pas d\'essai')
                        ->state(fn (Contract $record) => $record->termsAt()['probation_end_date'])->date('d/m/Y'),
                    TextEntry::make('probation_outcome')->label('Issue de l\'essai')->badge()->placeholder('À décider par la DE'),
                    TextEntry::make('current_position')->label('Poste en vigueur')
                        ->state(fn (Contract $record) => Position::query()->find($record->termsAt()['position_id'])?->title)->placeholder('—'),
                    TextEntry::make('current_station')->label("Lieu en vigueur")
                        ->state(fn (Contract $record) => DutyStation::query()->find($record->termsAt()['duty_station_id'])?->name)->placeholder('—'),
                    TextEntry::make('current_salary')->label('Salaire de base en vigueur')->visible($sensitive)->weight('bold')
                        ->state(fn (Contract $record) => Ui::money($record->termsAt()['base_salary'], $record->currency))
                        ->helperText(fn (Contract $record) => $record->salary_category ? "Catégorie {$record->salary_category}, étape {$record->salary_step}" : null),
                    TextEntry::make('weekly_hours')->label('Heures par semaine'),
                    TextEntry::make('signed_at')->label('Signé le')->date('d/m/Y')->placeholder('Non signé'),
                    TextEntry::make('terminated_at')->label('Fin effective')->date('d/m/Y')->placeholder('—')
                        ->helperText(fn (Contract $record) => $record->termination_reason),
                    TextEntry::make('createdBy.name')->label('Établi par'),
                ]),
            ]),
            Section::make('Ventilation budgétaire')->visible($sensitive)->schema([
                RepeatableEntry::make('allocations')->hiddenLabel()->table([
                    \Filament\Infolists\Components\RepeatableEntry\TableColumn::make('Ligne budgétaire'),
                    \Filament\Infolists\Components\RepeatableEntry\TableColumn::make('Part'),
                    \Filament\Infolists\Components\RepeatableEntry\TableColumn::make('Période'),
                ])->schema([
                    TextEntry::make('budgetLine.label')->state(fn ($record) => $record->budgetLine->budget->project->code.' · '.$record->budgetLine->code.' — '.$record->budgetLine->label),
                    TextEntry::make('percentage')->formatStateUsing(fn ($state) => rtrim(rtrim(number_format((float) $state, 2, ',', ' '), '0'), ',').' %'),
                    TextEntry::make('period')->state(fn ($record) => 'du '.$record->valid_from->format('d/m/Y').($record->valid_to ? ' au '.$record->valid_to->format('d/m/Y') : ' à la fin du contrat')),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('start_date', 'desc')
            ->columns([
                TextColumn::make('number')->label('Référence')->searchable()->sortable(),
                TextColumn::make('employee.full_name')->label('Employé')->searchable(['last_name', 'first_name']),
                TextColumn::make('type')->label('Type'),
                TextColumn::make('project.code')->label('Projet')->placeholder('—'),
                TextColumn::make('start_date')->label('Début')->date('d/m/Y')->sortable(),
                TextColumn::make('current_end')->label('Fin')->placeholder('Indéterminée')
                    ->state(fn (Contract $record) => $record->termsAt()['end_date'])->date('d/m/Y')
                    ->color(fn (Contract $record) => ($end = $record->termsAt()['end_date']) && $record->status === ContractStatus::ACTIF && $end <= now()->addDays(30)->toDateString() ? 'danger' : null),
                TextColumn::make('status')->label('Statut')->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Statut')->options(ContractStatus::class),
                SelectFilter::make('type')->label('Type')->options(ContractType::class),
                SelectFilter::make('project_id')->label('Projet')->relationship('project', 'code'),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\AmendmentsRelationManager::class,
            \App\Filament\Resources\Employees\RelationManagers\DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContracts::route('/'),
            'create' => Pages\CreateContract::route('/create'),
            'view' => Pages\ViewContract::route('/{record}'),
            'edit' => Pages\EditContract::route('/{record}/edit'),
        ];
    }
}
