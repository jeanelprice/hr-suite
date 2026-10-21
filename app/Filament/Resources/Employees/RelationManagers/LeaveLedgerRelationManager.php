<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Models\LeaveLedgerEntry;
use App\Services\LeaveService;
use App\Support\PersonnelAccess;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/** Registre des congés en ajout seul : chaque acquisition, prise, ajustement ou expiration */
class LeaveLedgerRelationManager extends RelationManager
{
    protected static string $relationship = 'leaveLedger';

    protected static ?string $modelLabel = 'mouvement';

    protected static ?string $pluralModelLabel = 'mouvements';

    protected static ?string $title = 'Registre des congés';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-book-open';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return PersonnelAccess::canSeeSensitive(Auth::user(), $ownerRecord);
    }

    public function table(Table $table): Table
    {
        $s = app(LeaveService::class);
        $owner = $this->getOwnerRecord();

        return $table
            ->emptyStateHeading('Aucun mouvement')
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('effective_date')->label('Date')->date('d/m/Y'),
                TextColumn::make('balance')->label('Solde')->formatStateUsing(fn ($state) => $state === 'ANNUEL' ? 'Annuel' : 'Récupération'),
                TextColumn::make('leave_year')->label('Année')->placeholder('—')
                    ->formatStateUsing(function ($state) use ($s, $owner) {
                        [$from, $to] = $s->yearRange($owner, (int) $state);

                        return $from->format('d/m/Y').' → '.$to->format('d/m/Y');
                    }),
                TextColumn::make('kind')->label('Mouvement')->badge(),
                TextColumn::make('days')->label('Jours')->formatStateUsing(fn ($state) => ((float) $state > 0 ? '+' : '').$s->fmt((float) $state))
                    ->color(fn ($state) => (float) $state >= 0 ? 'success' : 'danger'),
                TextColumn::make('note')->label('Détail')->placeholder('—')->limit(40),
                TextColumn::make('recordedBy.name')->label('Par')->placeholder('Calcul automatique'),
            ])
            ->filters([SelectFilter::make('balance')->label('Solde')->options(['ANNUEL' => 'Annuel', 'RECUPERATION' => 'Récupération'])]);
    }
}
