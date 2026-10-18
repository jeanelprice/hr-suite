<?php

namespace App\Filament\Resources\Suppliers;

use App\Enums\AccessLevel;
use App\Enums\AppModule;
use App\Filament\Concerns\HasModuleAccess;
use App\Models\Supplier;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class SupplierResource extends Resource
{
    use HasModuleAccess;

    protected static AppModule $module = AppModule::ACHATS;

    protected static ?string $model = Supplier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Achats';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'fournisseur';

    protected static ?string $pluralModelLabel = "Fournisseurs";

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canEdit(Model $record): bool
    {
        return static::canCreate();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Nom')->required()->maxLength(255),
            TextInput::make('tax_id')->label('RCCM / Id. Nat.'),
            TextInput::make('phone')->label('Téléphone')->tel(),
            TextInput::make('email')->label('Email')->email(),
            TextInput::make('address')->label('Adresse')->columnSpanFull(),
            Toggle::make('is_active')->label('Actif')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Nom')->searchable()->sortable(),
                TextColumn::make('tax_id')->label('RCCM / Id. Nat.')->placeholder('—'),
                TextColumn::make('phone')->label('Téléphone')->placeholder('—'),
                TextColumn::make('email')->label('Email')->placeholder('—'),
                IconColumn::make('is_active')->label('Actif')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageSuppliers::route('/')];
    }
}
