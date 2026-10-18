<?php

namespace App\Filament\Resources\Budgets;

use App\Enums\AppModule;
use App\Enums\Currency;
use App\Enums\ProjectStatus;
use App\Filament\Concerns\HasModuleAccess;
use App\Filament\Support\Ui;
use App\Models\Budget;
use App\Models\Project;
use App\Services\BudgetService;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Budgets annuels par projet (module Finances) : saisie par le Financier,
 * validation par la Direction Exécutive, révisions versionnées (RG-10).
 */
class BudgetResource extends Resource
{
    use HasModuleAccess;

    protected static AppModule $module = AppModule::FINANCES;

    protected static ?string $model = Budget::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Finances';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'budget';

    protected static ?string $pluralModelLabel = "Budgets";

    protected static bool $hasTitleCaseModelLabel = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Budget')
                ->description('Le budget est saisi par exercice et par projet. Il entre en vigueur à sa validation par la Direction Exécutive, et le projet devient alors actif (RG-13).')
                ->columns(3)
                ->schema([
                    Select::make('project_id')
                        ->label('Projet')
                        ->options(fn () => Project::query()->where('status', '!=', ProjectStatus::CLOTURE->value)->orderBy('code')->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->code} — {$p->title}"]))
                        ->required(),
                    TextInput::make('year')->label('Exercice')->numeric()->minValue(2020)->maxValue(2100)->default(now()->year)->required(),
                    Select::make('currency')->label('Devise de référence')->options(Currency::class)->default(Currency::USD->value)->required(),
                ]),
            Section::make('Lignes budgétaires')->schema([
                Repeater::make('lines')
                    ->hiddenLabel()
                    ->addActionLabel('Ajouter une ligne')
                    ->minItems(1)
                    ->columns(12)
                    ->schema([
                        TextInput::make('code')->label('Code')->required()->maxLength(20)->columnSpan(2),
                        TextInput::make('label')->label('Intitulé')->required()->columnSpan(5),
                        TextInput::make('category')->label('Catégorie')->columnSpan(2),
                        TextInput::make('amount')->label('Montant')->numeric()->minValue(0)->required()->columnSpan(3),
                    ]),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('project.code')->label('Projet')->formatStateUsing(fn (Budget $record) => "{$record->project->code} — {$record->project->title}")->columnSpan(2),
                TextEntry::make('fiscalYear.year')->label('Exercice'),
                TextEntry::make('currency')->label('Devise'),
                TextEntry::make('currentVersion.version_no')->label('Version en vigueur')->placeholder('Aucune (en attente de validation)')
                    ->formatStateUsing(fn ($state) => "Version {$state}"),
                TextEntry::make('total_budgeted')->label('Total budgétisé')->state(fn (Budget $record) => Ui::money(app(BudgetService::class)->dashboard($record)['totals']['budgeted'], $record->currency)),
                TextEntry::make('total_available')->label('Solde disponible')->weight('bold')
                    ->state(fn (Budget $record) => Ui::money(app(BudgetService::class)->dashboard($record)['totals']['available'], $record->currency)),
                TextEntry::make('rate')->label('Taux de consommation')
                    ->state(fn (Budget $record) => ($p = app(BudgetService::class)->dashboard($record)['totals']['consumption_pct']) === null ? '—' : number_format($p, 1, ',', ' ').' %'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('project.code')->label('Projet')->searchable()->sortable(),
                TextColumn::make('project.title')->label('Intitulé')->limit(40),
                TextColumn::make('fiscalYear.year')->label('Exercice')->sortable(),
                TextColumn::make('currency')->label('Devise'),
                TextColumn::make('currentVersion.version_no')->label('Version en vigueur')->placeholder('À valider')
                    ->formatStateUsing(fn ($state) => "v{$state}"),
                TextColumn::make('available')->label('Solde disponible')->alignEnd()
                    ->state(fn (Budget $record) => Ui::money(app(BudgetService::class)->dashboard($record)['totals']['available'], $record->currency)),
            ])
            ->recordActions([ViewAction::make()->label('Tableau de bord')]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\LinesRelationManager::class,
            RelationManagers\VersionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBudgets::route('/'),
            'create' => Pages\CreateBudget::route('/create'),
            'view' => Pages\ViewBudget::route('/{record}'),
        ];
    }
}
