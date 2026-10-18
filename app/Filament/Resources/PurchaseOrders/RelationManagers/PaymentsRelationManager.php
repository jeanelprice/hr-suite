<?php

namespace App\Filament\Resources\PurchaseOrders\RelationManagers;

use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Support\Ui;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Paiements d'un bon de commande, avec leurs actions d'approbation */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Paiements';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('number')->label('Numéro'),
                TextColumn::make('paid_at')->label('Date')->date('d/m/Y'),
                TextColumn::make('amount')->label('Montant')->alignEnd()->formatStateUsing(fn ($state, $record) => Ui::money($state, $record->currency)),
                TextColumn::make('treasuryAccount.name')->label('Compte débité'),
                TextColumn::make('preparedBy.name')->label('Préparé par'),
                TextColumn::make('approvedBy.name')->label('Approuvé par')->placeholder('—'),
                TextColumn::make('status')->label('Statut')->badge(),
            ])
            ->recordActions(PaymentResource::decisionActions());
    }
}
