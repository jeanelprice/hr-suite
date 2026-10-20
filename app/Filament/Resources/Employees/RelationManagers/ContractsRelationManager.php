<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Support\Ui;
use App\Models\Contract;
use App\Support\PersonnelAccess;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ContractsRelationManager extends RelationManager
{
    protected static string $relationship = 'contracts';

    protected static ?string $modelLabel = 'contrat';

    protected static ?string $pluralModelLabel = 'contrats';

    protected static ?string $title = 'Contrats';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-document-text';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return PersonnelAccess::canView(Auth::user());
    }

    public function table(Table $table): Table
    {
        $sensitive = PersonnelAccess::canSeeSensitive(Auth::user(), $this->getOwnerRecord());

        return $table
            ->emptyStateHeading('Aucun contrat')
            ->defaultSort('start_date', 'desc')
            ->columns([
                TextColumn::make('number')->label('Référence')->weight('medium'),
                TextColumn::make('type')->label('Type'),
                TextColumn::make('start_date')->label('Début')->date('d/m/Y'),
                TextColumn::make('end_date')->label('Fin')->placeholder('Indéterminée')
                    ->state(fn (Contract $record) => $record->termsAt()['end_date'])->date('d/m/Y'),
                TextColumn::make('position.title')->label('Poste')->placeholder('—'),
                TextColumn::make('salary')->label('Salaire de base')->visible($sensitive)
                    ->state(fn (Contract $record) => Ui::money($record->termsAt()['base_salary'], $record->currency)),
                TextColumn::make('status')->label('Statut')->badge(),
            ])
            ->recordUrl(fn (Contract $record) => ContractResource::getUrl('view', ['record' => $record]))
            ->headerActions([
                Action::make('create')->label('Nouveau contrat')->icon('heroicon-o-document-plus')
                    ->visible(fn () => PersonnelAccess::canManage(Auth::user()))
                    ->url(fn () => ContractResource::getUrl('create', ['employee' => $this->getOwnerRecord()->id])),
            ]);
    }
}
