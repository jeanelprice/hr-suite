<?php

namespace App\Filament\Resources\StatutoryObjectives;

use App\Enums\AccessLevel;
use App\Enums\AppModule;
use App\Filament\Concerns\HasModuleAccess;
use App\Models\StatutoryObjective;
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

/** Objectifs statutaires (Article 8 des statuts) — modifiables au niveau Validation du module Projets */
class StatutoryObjectiveResource extends Resource
{
    use HasModuleAccess;

    protected static AppModule $module = AppModule::PROJETS;

    protected static ?AccessLevel $createLevel = AccessLevel::VALIDATION;

    protected static ?string $model = StatutoryObjective::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'Projets';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'objectif statutaire';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $pluralModelLabel = "Objectifs statutaires";

    public static function canEdit(Model $record): bool
    {
        return static::canCreate();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->label('Code')->required()->maxLength(10)->unique(ignoreRecord: true),
            TextInput::make('label')->label('Intitulé')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')->label('Code'),
                TextColumn::make('label')->label('Intitulé')->wrap(),
                TextColumn::make('projects_count')->label('Projets')->counts('projects'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageStatutoryObjectives::route('/')];
    }
}
