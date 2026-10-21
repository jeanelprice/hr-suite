<?php

namespace App\Filament\Resources\PublicHolidays;

use App\Enums\AppModule;
use App\Filament\Concerns\HasModuleAccess;
use App\Models\PublicHoliday;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Jours fériés légaux, exclus du décompte des jours de congé */
class PublicHolidayResource extends Resource
{
    use HasModuleAccess;

    protected static AppModule $module = AppModule::RH;

    protected static ?string $model = PublicHoliday::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSun;

    protected static string|UnitEnum|null $navigationGroup = 'Personnel';

    protected static ?int $navigationSort = 16;

    protected static ?string $modelLabel = "jour férié";

    protected static ?string $pluralModelLabel = "Jours fériés";

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $recordTitleAttribute = 'label';

    public static function canEdit(Model $record): bool
    {
        return static::canCreate();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            DatePicker::make('date')->label('Date')->native(false)->displayFormat('d/m/Y')->required()->unique(ignoreRecord: true),
            TextInput::make('label')->label('Libellé')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date')
            ->columns([
                TextColumn::make('date')->label('Date')->date('l d/m/Y')->sortable(),
                TextColumn::make('label')->label('Libellé')->searchable(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManagePublicHolidays::route('/')];
    }
}
