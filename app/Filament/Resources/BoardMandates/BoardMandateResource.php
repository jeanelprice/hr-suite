<?php

namespace App\Filament\Resources\BoardMandates;

use App\Enums\AppModule;
use App\Filament\Concerns\HasModuleAccess;
use App\Models\BoardMandate;
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

/** Mandats des membres élus du Conseil d'Administration (3 ans, cahier des charges §4.3) */
class BoardMandateResource extends Resource
{
    use HasModuleAccess;

    protected static AppModule $module = AppModule::RH;

    protected static ?string $model = BoardMandate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Personnel';

    protected static ?int $navigationSort = 14;

    protected static ?string $modelLabel = "mandat";

    protected static ?string $pluralModelLabel = "Mandats du CA";

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $recordTitleAttribute = 'full_name';

    public static function canEdit(Model $record): bool
    {
        return static::canCreate();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('full_name')->label('Nom complet')->required(),
            TextInput::make('office')->label('Fonction')->required()->datalist(['Président', 'Vice-président', 'Secrétaire', 'Trésorier', 'Conseiller']),
            TextInput::make('email')->label('Email')->email(),
            DatePicker::make('start_date')->label('Début')->native(false)->required()->live()
                ->afterStateUpdated(fn ($state, $set) => $state ? $set('end_date', \Illuminate\Support\Carbon::parse($state)->addYears(3)->subDay()->toDateString()) : null),
            DatePicker::make('end_date')->label('Fin (3 ans)')->native(false)->required()->afterOrEqual('start_date'),
            Select::make('renewed_from_id')->label('Renouvellement du mandat')->relationship('renewedFrom', 'full_name')->placeholder('Premier mandat'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('end_date')
            ->columns([
                TextColumn::make('full_name')->label('Nom')->searchable()->sortable(),
                TextColumn::make('office')->label('Fonction'),
                TextColumn::make('start_date')->label('Début')->date('d/m/Y'),
                TextColumn::make('end_date')->label('Fin')->date('d/m/Y')->sortable()
                    ->color(fn ($record) => $record->end_date->isPast() ? 'danger' : ($record->end_date->lte(now()->addMonths(3)) ? 'warning' : null)),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageBoardMandates::route('/')];
    }
}
