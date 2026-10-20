<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Support\PersonnelAccess;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class DiplomasRelationManager extends RelationManager
{
    protected static string $relationship = 'diplomas';

    protected static ?string $modelLabel = 'diplôme';

    protected static ?string $pluralModelLabel = 'diplômes';

    protected static ?string $title = 'Diplômes';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-academic-cap';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return PersonnelAccess::canView(Auth::user());
    }

    public function isReadOnly(): bool
    {
        return ! PersonnelAccess::canManage(Auth::user());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextInput::make('title')->label('Diplôme')->required()->placeholder('ex. Licence en sciences commerciales'),
            TextInput::make('institution')->label('Établissement'),
            TextInput::make('year')->label('Année')->numeric()->integer()->minValue(1960)->maxValue((int) date('Y')),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->emptyStateHeading('Aucun diplôme enregistré')
            ->defaultSort('year', 'desc')
            ->columns([
                TextColumn::make('title')->label('Diplôme'),
                TextColumn::make('institution')->label('Établissement')->placeholder('—'),
                TextColumn::make('year')->label('Année')->placeholder('—'),
            ])
            ->headerActions([CreateAction::make()->label('Ajouter un diplôme')->createAnother(false)])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
