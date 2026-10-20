<?php

namespace App\Filament\Resources\Departments;

use App\Enums\AppModule;
use App\Filament\Concerns\HasModuleAccess;
use App\Models\Department;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Départements (référentiel RH) */
class DepartmentResource extends Resource
{
    use HasModuleAccess;

    protected static AppModule $module = AppModule::RH;

    protected static ?string $model = Department::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static string|UnitEnum|null $navigationGroup = 'Personnel';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = "département";

    protected static ?string $pluralModelLabel = "Départements";

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
            Select::make('head_employee_id')->label('Chef de département')->relationship('head', 'last_name')
                ->getOptionLabelFromRecordUsing(fn (\App\Models\Employee $e) => $e->full_name)->searchable(['last_name', 'first_name', 'matricule'])->preload(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Nom')->searchable()->sortable(),
                TextColumn::make('head.full_name')->label('Chef de département')->placeholder('—'),
                TextColumn::make('employees_count')->label('Effectif')->counts('employees'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageDepartments::route('/')];
    }
}
