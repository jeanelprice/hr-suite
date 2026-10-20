<?php

namespace App\Filament\Resources\DutyStations;

use App\Enums\AppModule;
use App\Filament\Concerns\HasModuleAccess;
use App\Models\DutyStation;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Lieux d'affectation (référentiel RH) */
class DutyStationResource extends Resource
{
    use HasModuleAccess;

    protected static AppModule $module = AppModule::RH;

    protected static ?string $model = DutyStation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Personnel';

    protected static ?int $navigationSort = 13;

    protected static ?string $modelLabel = "lieu d'affectation";

    protected static ?string $pluralModelLabel = "Lieux d'affectation";

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canEdit(Model $record): bool
    {
        return static::canCreate();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Nom')->required()->maxLength(255)->unique(ignoreRecord: true),
            TextInput::make('province')->label('Province')->default('Nord-Kivu'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Nom')->searchable()->sortable(),
                TextColumn::make('province')->label('Province')->placeholder('—'),
                TextColumn::make('employees_count')->label('Effectif')->counts('employees'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageDutyStations::route('/')];
    }
}
