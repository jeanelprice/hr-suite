<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Support\PersonnelAccess;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/** Historique daté de la situation : poste, lieu, supérieur, statut */
class HistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'history';

    protected static ?string $modelLabel = 'changement';

    protected static ?string $pluralModelLabel = 'changements';

    protected static ?string $title = 'Historique';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-clock';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return PersonnelAccess::canView(Auth::user());
    }

    public function table(Table $table): Table
    {
        return $table
            ->emptyStateHeading('Aucun changement enregistré')
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('effective_date')->label('Date d\'effet')->date('d/m/Y'),
                TextColumn::make('field')->label('Changement')->weight('medium'),
                TextColumn::make('old_value')->label('Avant')->placeholder('—'),
                TextColumn::make('new_value')->label('Après')->placeholder('—'),
                TextColumn::make('recordedBy.name')->label('Par')->placeholder('Système'),
            ]);
    }
}
