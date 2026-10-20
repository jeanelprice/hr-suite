<?php

namespace App\Filament\Resources\SalaryGrades;

use App\Enums\AppModule;
use App\Enums\Currency;
use App\Filament\Concerns\HasModuleAccess;
use App\Models\SalaryGrade;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Échelle salariale par catégorie et étape (l'étape 1 sert au calcul des indemnités d'intérim, Manuel §5.5) */
class SalaryGradeResource extends Resource
{
    use HasModuleAccess;

    protected static AppModule $module = AppModule::RH;

    protected static ?string $model = SalaryGrade::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Personnel';

    protected static ?int $navigationSort = 12;

    protected static ?string $modelLabel = "échelon";

    protected static ?string $pluralModelLabel = "Échelle salariale";

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $recordTitleAttribute = 'category';

    public static function canEdit(Model $record): bool
    {
        return static::canCreate();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('category')->label('Catégorie')->required()->maxLength(20)->placeholder('ex. B2'),
            TextInput::make('step')->label('Étape')->numeric()->integer()->minValue(1)->required()->default(1),
            TextInput::make('amount')->label('Salaire de base brut mensuel')->numeric()->minValue(0.01)->required(),
            Select::make('currency')->label('Devise')->options(Currency::class)->default('USD')->required(),
            DatePicker::make('effective_from')->label("Date d'effet")->native(false)->required()->default(now()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('category')
            ->columns([
                TextColumn::make('category')->label('Catégorie')->searchable()->sortable(),
                TextColumn::make('step')->label('Étape')->sortable(),
                TextColumn::make('amount')->label('Montant')->formatStateUsing(fn ($state, $record) => \App\Filament\Support\Ui::money($state, $record->currency)),
                TextColumn::make('effective_from')->label("Date d'effet")->date('d/m/Y')->sortable(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageSalaryGrades::route('/')];
    }
}
