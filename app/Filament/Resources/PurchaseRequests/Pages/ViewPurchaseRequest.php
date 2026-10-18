<?php

namespace App\Filament\Resources\PurchaseRequests\Pages;

use App\Enums\AccessLevel;
use App\Enums\AppModule;
use App\Enums\DocStatus;
use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Support\Ui;
use App\Models\PurchaseRequest;
use App\Services\ApprovalService;
use App\Services\PurchaseRequestService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

/**
 * Fiche d'une demande d'achat et ses actions de circuit :
 * soumettre, valider (avec dérogation éventuelle), rejeter, annuler, établir le BC.
 */
class ViewPurchaseRequest extends ViewRecord
{
    protected static string $resource = PurchaseRequestResource::class;

    public function getTitle(): string
    {
        return "Demande d'achat {$this->getRecord()->number}";
    }

    private function request(): PurchaseRequest
    {
        /** @var PurchaseRequest */
        return $this->getRecord();
    }

    private function service(): PurchaseRequestService
    {
        return app(PurchaseRequestService::class);
    }

    private function reload(): void
    {
        $this->redirect(PurchaseRequestResource::getUrl('view', ['record' => $this->request()]));
    }

    private function canDecide(): bool
    {
        $user = Auth::user();

        return $this->request()->status === DocStatus::SOUMIS
            && $user->canInModule(AppModule::ACHATS, AccessLevel::VALIDATION)
            && app(ApprovalService::class)->canValidate($user, $this->request()->requester_id, AppModule::ACHATS);
    }

    private function hasActiveOrder(): bool
    {
        return $this->request()->orders()->whereNotIn('status', [PurchaseOrderStatus::REJETE->value, PurchaseOrderStatus::ANNULE->value])->exists();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('submit')
                ->label('Soumettre pour validation')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->requiresConfirmation()
                ->modalDescription('La demande sera envoyée à la Direction Exécutive pour validation. Le solde de la ligne est contrôlé à nouveau.')
                ->visible(fn () => $this->request()->status === DocStatus::BROUILLON && $this->request()->requester_id === Auth::id())
                ->action(function () {
                    if (Ui::attempt(fn () => $this->service()->submit(Auth::user(), $this->request()), 'Demande soumise : la Direction Exécutive a été prévenue.')) {
                        $this->reload();
                    }
                }),

            Action::make('approve')
                ->label('Valider')
                ->modalSubmitActionLabel('Valider')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => $this->canDecide())
                ->modalHeading('Valider la demande')
                ->modalDescription('La validation réserve le montant sur la ligne budgétaire (pré-engagement, RG-02).')
                ->schema([
                    Textarea::make('comment')->label('Commentaire (facultatif)')->rows(2),
                    Textarea::make('derogation')
                        ->label('Dérogation : justification (uniquement si le solde est insuffisant)')
                        ->helperText('Réservé à la Direction Exécutive. Laissez vide si le solde suffit (RG-09).')
                        ->minLength(20)
                        ->rows(3)
                        ->visible(fn () => Auth::user()->isDirectionExecutive()),
                ])
                ->action(function (array $data) {
                    if (Ui::attempt(fn () => $this->service()->approve(Auth::user(), $this->request(), $data['comment'] ?? null, filled($data['derogation'] ?? null) ? $data['derogation'] : null), 'Demande validée : le montant est pré-engagé.')) {
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
                    if (Ui::attempt(fn () => $this->service()->reject(Auth::user(), $this->request(), $data['comment']), 'Demande rejetée : le demandeur a été prévenu.')) {
                        $this->reload();
                    }
                }),

            Action::make('createOrder')
                ->label('Établir le bon de commande')
                ->icon('heroicon-o-document-plus')
                ->color('primary')
                ->visible(fn () => $this->request()->status === DocStatus::VALIDE
                    && ! $this->hasActiveOrder()
                    && Auth::user()->canInModule(AppModule::ACHATS, AccessLevel::CREATION))
                ->url(fn () => PurchaseOrderResource::getUrl('create', ['request' => $this->request()->id])),

            Action::make('cancel')
                ->label('Annuler la demande')
                ->modalSubmitActionLabel('Confirmer l\'annulation')
                ->icon('heroicon-o-trash')
                ->color('gray')
                ->visible(fn () => in_array($this->request()->status, [DocStatus::BROUILLON, DocStatus::SOUMIS, DocStatus::VALIDE], true)
                    && ! $this->hasActiveOrder()
                    && ($this->request()->requester_id === Auth::id() || Auth::user()->isDirectionExecutive()))
                ->modalDescription('Si la demande était validée, son pré-engagement est libéré et retourne au solde de la ligne.')
                ->schema([Textarea::make('reason')->label("Motif de l'annulation")->required()->minLength(5)])
                ->action(function (array $data) {
                    if (Ui::attempt(fn () => $this->service()->cancel(Auth::user(), $this->request(), $data['reason']), 'Demande annulée.')) {
                        $this->reload();
                    }
                }),
        ];
    }
}
