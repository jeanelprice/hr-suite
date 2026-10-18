<?php

namespace App\Filament\Widgets;

use App\Enums\AccessLevel;
use App\Enums\AppModule;
use App\Enums\DocStatus;
use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\Derogations\DerogationResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Models\Derogation;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/** Accueil : ce qui attend une action (validations, paiements, dérogations à régulariser) */
class PendingWork extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'En attente';

    protected function getStats(): array
    {
        $user = Auth::user();
        $stats = [];

        if ($user->canInModule(AppModule::ACHATS)) {
            $stats[] = Stat::make("Demandes d'achat soumises", PurchaseRequest::query()->where('status', DocStatus::SOUMIS->value)->count())
                ->description('À valider par la Direction Exécutive')
                ->icon('heroicon-o-clipboard-document-list')
                ->url(PurchaseRequestResource::getUrl('index', ['filters' => ['status' => ['value' => 'SOUMIS']]]));
            $stats[] = Stat::make('Bons de commande soumis', PurchaseOrder::query()->where('status', PurchaseOrderStatus::SOUMIS->value)->count())
                ->description('À valider')
                ->icon('heroicon-o-document-text')
                ->url(PurchaseOrderResource::getUrl('index'));
        }
        if ($user->canInModule(AppModule::FINANCES)) {
            $stats[] = Stat::make('Paiements à approuver', Payment::query()->where('status', DocStatus::SOUMIS->value)->count())
                ->description('Double signature (RG-15)')
                ->icon('heroicon-o-banknotes')
                ->url(PaymentResource::getUrl('index'));
            $open = Derogation::query()->whereNull('regularized_at')->count();
            $stats[] = Stat::make('Dérogations à régulariser', $open)
                ->description($open ? 'Révision budgétaire attendue (RG-09)' : 'Aucune')
                ->color($open ? 'danger' : 'success')
                ->icon('heroicon-o-shield-exclamation')
                ->url(DerogationResource::getUrl('index'));
        }

        return $stats;
    }

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user && ($user->canInModule(AppModule::ACHATS) || $user->canInModule(AppModule::FINANCES));
    }
}
