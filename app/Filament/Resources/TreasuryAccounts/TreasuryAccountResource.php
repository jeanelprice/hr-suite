<?php

namespace App\Filament\Resources\TreasuryAccounts;

use App\Enums\AccessLevel;
use App\Enums\AppModule;
use App\Enums\Currency;
use App\Enums\TreasuryType;
use App\Filament\Concerns\HasModuleAccess;
use App\Models\TreasuryAccount;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
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

/** Caisses et comptes bancaires (RG-16) — création réservée au niveau Validation */
class TreasuryAccountResource extends Resource
{
    use HasModuleAccess;

    protected static AppModule $module = AppModule::FINANCES;

    protected static ?AccessLevel $createLevel = AccessLevel::VALIDATION;

    protected static ?string $model = TreasuryAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Finances';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'compte de trésorerie';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $pluralModelLabel = "Caisses et banques";

    public static function canEdit(Model $record): bool
    {
        return static::canCreate();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label('Nom')->required()->unique(ignoreRecord: true),
            Select::make('type')->label('Type')->options(TreasuryType::class)->required(),
            Select::make('currency')->label('Devise')->options(Currency::class)->required(),
            TextInput::make('bank_name')->label('Banque')->placeholder('Pour un compte bancaire'),
            TextInput::make('account_ref')->label('Référence interne')->maxLength(20)
                ->helperText('Référence courte : ne saisissez pas le numéro de compte complet.'),
            Toggle::make('is_active')->label('Actif')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Nom')->searchable(),
                TextColumn::make('type')->label('Type')->badge(),
                TextColumn::make('currency')->label('Devise'),
                TextColumn::make('bank_name')->label('Banque')->placeholder('—'),
                IconColumn::make('is_active')->label('Actif')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageTreasuryAccounts::route('/')];
    }
}
