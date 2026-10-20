<?php

namespace App\Filament\Resources\Positions;

use App\Enums\AppModule;
use App\Filament\Concerns\HasModuleAccess;
use App\Models\Position;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Postes (référentiel RH) */
class PositionResource extends Resource
{
    use HasModuleAccess;

    protected static AppModule $module = AppModule::RH;

    protected static ?string $model = Position::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Personnel';

    protected static ?int $navigationSort = 11;

    protected static ?string $modelLabel = "poste";

    protected static ?string $pluralModelLabel = "Postes";

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $recordTitleAttribute = 'title';

    public static function canEdit(Model $record): bool
    {
        return static::canCreate();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('title')->label('Intitulé')->required()->maxLength(255),
            Select::make('department_id')->label('Département')->relationship('department', 'name')->preload(),
            TextInput::make('salary_category')->label('Catégorie salariale')->placeholder('ex. B2')->maxLength(20),
            Toggle::make('is_key')->label("Poste clé ou d'encadrement")->helperText('Jury de recrutement élargi au Financier et à la DE (Manuel §2.4.2).')->inline(false),
            Textarea::make('description')->label('Description de poste')->rows(5)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('title')
            ->columns([
                TextColumn::make('title')->label('Intitulé')->searchable()->sortable(),
                TextColumn::make('department.name')->label('Département')->placeholder('—'),
                TextColumn::make('salary_category')->label('Catégorie')->placeholder('—'),
                IconColumn::make('is_key')->label('Poste clé')->boolean(),
                TextColumn::make('employees_count')->label('Titulaires')->counts('employees'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManagePositions::route('/')];
    }
}
