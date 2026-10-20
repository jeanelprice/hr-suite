<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Enums\FamilyRelation;
use App\Support\PersonnelAccess;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/** Famille immédiate (Manuel §1.5) : justificatifs à joindre dans « Documents » */
class FamilyRelationManager extends RelationManager
{
    protected static string $relationship = 'family';

    protected static ?string $modelLabel = 'membre de la famille';

    protected static ?string $pluralModelLabel = 'membres de la famille';

    protected static ?string $title = 'Famille';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-home';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return PersonnelAccess::canSeeSensitive(Auth::user(), $ownerRecord);
    }

    public function isReadOnly(): bool
    {
        return ! PersonnelAccess::canManage(Auth::user());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('relation')->label('Lien')->options(FamilyRelation::class)->required(),
            TextInput::make('full_name')->label('Nom complet')->required(),
            DatePicker::make('birth_date')->label('Date de naissance')->native(false),
            Toggle::make('is_dependant')->label('À charge')->inline(false)
                ->helperText('Enfant ou conjoint entièrement à charge (allocations familiales).'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->emptyStateHeading('Aucun membre de la famille enregistré')
            ->columns([
                TextColumn::make('relation')->label('Lien')->badge()->color('gray'),
                TextColumn::make('full_name')->label('Nom'),
                TextColumn::make('birth_date')->label('Naissance')->date('d/m/Y')->placeholder('—'),
                IconColumn::make('is_dependant')->label('À charge')->boolean(),
            ])
            ->headerActions([CreateAction::make()->label('Ajouter un membre')->createAnother(false)])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
