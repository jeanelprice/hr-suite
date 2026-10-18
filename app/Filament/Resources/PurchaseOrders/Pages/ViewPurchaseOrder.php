<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Enums\AccessLevel;
use App\Enums\AppModule;
use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Support\Ui;
use App\Models\PurchaseOrder;
use App\Models\TreasuryAccount;
use App\Services\ApprovalService;
use App\Services\PaymentService;
use App\Services\PurchaseOrderService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Fiche d'un bon de commande et ses actions : soumettre, valider, rejeter,
 * annuler, enregistrer un paiement, clôturer.
 */
class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    public function getTitle(): string
    {
        return "Bon de commande {$this->getRecord()->number}";
    }

    private function order(): PurchaseOrder
    {
        /** @var PurchaseOrder */
        return $this->getRecord();
    }

    private function service(): PurchaseOrderService
    {
        return app(PurchaseOrderService::class);
    }

    private function reload(): void
    {
        $this->redirect(PurchaseOrderResource::getUrl('view', ['record' => $this->order()]));
    }

    private function canDecide(): bool
    {
        $user = Auth::user();

        return $this->order()->status === PurchaseOrderStatus::SOUMIS
            && $user->canInModule(AppModule::ACHATS, AccessLevel::VALIDATION)
            && app(ApprovalService::class)->canValidate($user, $this->order()->created_by_id, AppModule::ACHATS);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('submit')
                ->label('Soumettre pour validation')
                ->icon('heroicon-o-paper-airplane')
                ->requiresConfirmation()
                ->visible(fn () => $this->order()->status === PurchaseOrderStatus::BROUILLON && $this->order()->created_by_id === Auth::id())
                ->action(function () {
                    if (Ui::attempt(fn () => $this->service()->submit(Auth::user(), $this->order()), 'Bon de commande soumis : la Direction Exécutive a été prévenue.')) {
                        $this->reload();
                    }
                }),

            Action::make('approve')
                ->label('Valider')
                ->modalSubmitActionLabel('Valider')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => $this->canDecide())
                ->modalDescription("La validation transforme le pré-engagement de la demande en engagement du montant du bon de commande (RG-03).")
                ->schema([
                    Textarea::make('comment')->label('Commentaire (facultatif)')->rows(2),
                    Textarea::make('derogation')
                        ->label('Dérogation : justification (uniquement si le solde est insuffisant)')
                        ->minLength(20)->rows(3)
                        ->visible(fn () => Auth::user()->isDirectionExecutive()),
                ])
                ->action(function (array $data) {
                    if (Ui::attempt(fn () => $this->service()->approve(Auth::user(), $this->order(), $data['comment'] ?? null, filled($data['derogation'] ?? null) ? $data['derogation'] : null), 'Bon de commande validé : le montant est engagé.')) {
                        $this->reload();
                    }
                }),

            Action::make('reject')
                ->label('Rejeter')
                ->modalSubmitActionLabel('Rejeter')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => $this->canDecide())
                ->schema([Textarea::make('comment')->label('Motif du rejet')->required()->minLength(5)])
                ->action(function (array $data) {
                    if (Ui::attempt(fn () => $this->service()->reject(Auth::user(), $this->order(), $data['comment']), 'Bon de commande rejeté.')) {
                        $this->reload();
                    }
                }),

            Action::make('pay')
                ->label('Enregistrer un paiement')
                ->modalSubmitActionLabel('Enregistrer le paiement')
                ->icon('heroicon-o-banknotes')
                ->color('primary')
                ->visible(fn () => $this->order()->status === PurchaseOrderStatus::VALIDE && Auth::user()->canInModule(AppModule::FINANCES, AccessLevel::CREATION))
                ->modalDescription(fn () => 'Montant du bon de commande : '.Ui::money($this->order()->amount, $this->order()->currency)
                    .' — reste à payer : '.Ui::money(PurchaseOrderResource::remainingToPay($this->order()), $this->order()->currency)
                    .'. Le paiement sera soumis à l\'approbation de la Direction Exécutive (double signature).')
                ->schema([
                    Select::make('treasury_account_id')
                        ->label('Caisse ou compte débité')
                        ->options(fn () => TreasuryAccount::query()->where('is_active', true)->where('currency', $this->order()->currency->value)->pluck('name', 'id'))
                        ->helperText(fn () => 'Seuls les comptes en '.$this->order()->currency->value.' sont proposés.')
                        ->required(),
                    TextInput::make('amount')->label('Montant payé')->numeric()->minValue(0.01)->required()
                        ->suffix(fn () => $this->order()->currency->value)
                        ->default(fn () => PurchaseOrderResource::remainingToPay($this->order())),
                    DatePicker::make('paid_at')->label('Date du paiement')->default(now())->required()->native(false),
                    Select::make('method')->label('Mode de paiement')->options([
                        'espèces' => 'Espèces', 'virement' => 'Virement', 'chèque' => 'Chèque', 'mobile money' => 'Mobile money',
                    ]),
                    TextInput::make('external_ref')->label('Référence (n° de chèque, de transaction…)'),
                ])
                ->action(function (array $data) {
                    $ok = Ui::attempt(fn () => app(PaymentService::class)->prepare(
                        Auth::user(),
                        $this->order(),
                        TreasuryAccount::query()->findOrFail($data['treasury_account_id']),
                        (string) $data['amount'],
                        Carbon::parse($data['paid_at']),
                        $data['method'] ?? null,
                        $data['external_ref'] ?? null,
                    ), 'Paiement préparé et soumis à la Direction Exécutive.');
                    if ($ok) {
                        $this->reload();
                    }
                }),

            Action::make('close')
                ->label('Clôturer')
                ->modalSubmitActionLabel('Clôturer')
                ->icon('heroicon-o-lock-closed')
                ->color('gray')
                ->visible(fn () => $this->order()->status === PurchaseOrderStatus::VALIDE && Auth::user()->canInModule(AppModule::FINANCES, AccessLevel::CREATION))
                ->modalDescription("La clôture libère le reliquat d'engagement non payé, qui retourne au solde de la ligne (RG-05).")
                ->schema([Textarea::make('reason')->label('Motif de la clôture')->required()->minLength(5)])
                ->action(function (array $data) {
                    if (Ui::attempt(fn () => $this->service()->close(Auth::user(), $this->order(), $data['reason']), 'Bon de commande clôturé : le reliquat est libéré.')) {
                        $this->reload();
                    }
                }),

            Action::make('cancel')
                ->label('Annuler')
                ->modalSubmitActionLabel('Confirmer l\'annulation')
                ->icon('heroicon-o-trash')
                ->color('gray')
                ->visible(fn () => in_array($this->order()->status, [PurchaseOrderStatus::BROUILLON, PurchaseOrderStatus::SOUMIS], true)
                    && ($this->order()->created_by_id === Auth::id() || Auth::user()->isDirectionExecutive()))
                ->schema([Textarea::make('reason')->label("Motif de l'annulation")->required()->minLength(5)])
                ->action(function (array $data) {
                    if (Ui::attempt(fn () => $this->service()->cancel(Auth::user(), $this->order(), $data['reason']), 'Bon de commande annulé.')) {
                        $this->reload();
                    }
                }),
        ];
    }
}
