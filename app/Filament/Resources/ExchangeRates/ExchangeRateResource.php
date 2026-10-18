<?php

namespace App\Filament\Resources\ExchangeRates;

use App\Enums\AppModule;
use App\Enums\Currency;
use App\Filament\Concerns\HasModuleAccess;
use App\Models\ExchangeRate;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Taux de change du jour saisis par le Financier (RG-11).
 * Un taux n'est jamais modifié : chaque opération garde le taux appliqué à sa saisie.
 */
class ExchangeRateResource extends Resource
{
    use HasModuleAccess;

    protected static AppModule $module = AppModule::FINANCES;

    protected static ?string $model = ExchangeRate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Finances';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'taux de change';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $pluralModelLabel = "Taux de change";

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            DatePicker::make('rate_date')->label('Date du taux')->default(now())->required()->native(false),
            TextInput::make('source')->label('Source')->default('BCC')->required(),
            Select::make('from_currency')->label('1 unité de')->options(Currency::class)->default(Currency::USD->value)->required(),
            Select::make('to_currency')->label('vaut, en')->options(Currency::class)->default(Currency::CDF->value)->required()->different('from_currency'),
            TextInput::make('rate')->label('Taux')->numeric()->minValue(0.000001)->required()
                ->helperText(fn (Get $get) => 'Exemple : 1 '.($get('from_currency') ?: 'USD').' = 2 850 '.($get('to_currency') ?: 'CDF').' → saisir 2850'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('rate_date', 'desc')
            ->columns([
                TextColumn::make('rate_date')->label('Date')->date('d/m/Y')->sortable(),
                TextColumn::make('pair')->label('Taux')->state(fn (ExchangeRate $r) => "1 {$r->from_currency->value} = ".rtrim(rtrim((string) $r->rate, '0'), '.')." {$r->to_currency->value}"),
                TextColumn::make('source')->label('Source'),
                TextColumn::make('enteredBy.name')->label('Saisi par'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageExchangeRates::route('/')];
    }
}
