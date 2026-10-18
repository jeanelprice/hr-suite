<?php

namespace App\Filament\Resources\Payments;

use App\Enums\AccessLevel;
use App\Enums\AppModule;
use App\Enums\DocStatus;
use App\Filament\Concerns\HasModuleAccess;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Support\Ui;
use App\Models\Payment;
use App\Services\ApprovalService;
use App\Services\PaymentService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Paiements (module Finances) : préparés par le Financier depuis un bon de commande,
 * approuvés par la Direction Exécutive (RG-15).
 */
class PaymentResource extends Resource
{
    use HasModuleAccess;

    protected static AppModule $module = AppModule::FINANCES;

    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Achats';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'paiement';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $pluralModelLabel = "Paiements";

    /** Les paiements se créent depuis la fiche d'un bon de commande */
    public static function canCreate(): bool
    {
        return false;
    }

    private static function canDecide(Payment $payment): bool
    {
        $user = Auth::user();

        return $payment->status === DocStatus::SOUMIS
            && $user->canInModule(AppModule::FINANCES, AccessLevel::VALIDATION)
            && app(ApprovalService::class)->canValidate($user, $payment->prepared_by_id, AppModule::FINANCES);
    }

    /** Actions « Approuver » et « Rejeter », partagées avec l'onglet Paiements du bon de commande */
    public static function decisionActions(): array
    {
        return [
            Action::make('approvePayment')
                ->label('Approuver')
                ->modalSubmitActionLabel('Approuver le paiement')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (Payment $record) => self::canDecide($record))
                ->modalDescription(fn (Payment $record) => 'Paiement de '.Ui::money($record->amount, $record->currency)
                    .' depuis « '.$record->treasuryAccount->name.' ». L\'engagement est libéré et la dépense inscrite.')
                ->schema([
                    Textarea::make('comment')->label('Commentaire (facultatif)')->rows(2),
                    Textarea::make('derogation')
                        ->label('Dérogation (écart de change dépassant le solde) : justification')
                        ->minLength(20)->rows(2)
                        ->visible(fn () => Auth::user()->isDirectionExecutive()),
                ])
                ->action(fn (Payment $record, array $data) => Ui::attempt(
                    fn () => app(PaymentService::class)->approve(Auth::user(), $record, $data['comment'] ?? null, filled($data['derogation'] ?? null) ? $data['derogation'] : null),
                    'Paiement approuvé.',
                )),
            Action::make('rejectPayment')
                ->label('Rejeter')
                ->modalSubmitActionLabel('Rejeter le paiement')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (Payment $record) => self::canDecide($record))
                ->schema([Textarea::make('comment')->label('Motif du rejet')->required()->minLength(5)])
                ->action(fn (Payment $record, array $data) => Ui::attempt(
                    fn () => app(PaymentService::class)->reject(Auth::user(), $record, $data['comment']),
                    'Paiement rejeté.',
                )),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label('Numéro')->searchable()->sortable(),
                TextColumn::make('purchaseOrder.number')->label('Bon de commande')
                    ->url(fn (Payment $record) => PurchaseOrderResource::getUrl('view', ['record' => $record->purchase_order_id])),
                TextColumn::make('purchaseOrder.supplier.name')->label('Fournisseur'),
                TextColumn::make('paid_at')->label('Date')->date('d/m/Y')->sortable(),
                TextColumn::make('amount')->label('Montant')->alignEnd()->formatStateUsing(fn ($state, $record) => Ui::money($state, $record->currency)),
                TextColumn::make('treasuryAccount.name')->label('Compte'),
                TextColumn::make('preparedBy.name')->label('Préparé par')->toggleable(),
                TextColumn::make('status')->label('Statut')->badge(),
            ])
            ->filters([SelectFilter::make('status')->label('Statut')->options(DocStatus::class)])
            ->recordActions(self::decisionActions());
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPayments::route('/')];
    }
}
