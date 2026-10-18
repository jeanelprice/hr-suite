<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Enums\Currency;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Support\Ui;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Services\PurchaseOrderService;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreatePurchaseOrder extends CreateRecord
{
    protected static bool $canCreateAnother = false;

    protected static string $resource = PurchaseOrderResource::class;

    protected static ?string $title = 'Nouveau bon de commande';

    /** Préremplissage depuis le bouton « Établir le bon de commande » d'une demande */
    protected function afterFill(): void
    {
        $requestId = request()->integer('request');
        $request = $requestId ? PurchaseRequest::query()->with('items')->find($requestId) : null;
        if ($request && array_key_exists($request->id, PurchaseOrderResource::orderableRequests())) {
            $this->form->fill([
                'purchase_request_id' => $request->id,
                'currency' => $request->currency->value,
                'items' => $request->items->map(fn ($i) => [
                    'description' => $i->description,
                    'quantity' => rtrim(rtrim((string) $i->quantity, '0'), '.'),
                    'unit' => $i->unit,
                    'unit_price' => $i->unit_price,
                ])->all(),
            ]);
        }
    }

    protected function handleRecordCreation(array $data): Model
    {
        $record = null;
        $ok = Ui::attempt(function () use ($data, &$record) {
            $record = app(PurchaseOrderService::class)->create(
                Auth::user(),
                PurchaseRequest::query()->findOrFail($data['purchase_request_id']),
                Supplier::query()->findOrFail($data['supplier_id']),
                $data['currency'] instanceof Currency ? $data['currency'] : Currency::from($data['currency']),
                array_values($data['items']),
                filled($data['derogation_request'] ?? null) ? $data['derogation_request'] : null,
            );
        }, 'Bon de commande enregistré en brouillon. Pensez à le soumettre pour validation.');

        if (! $ok) {
            throw new Halt;
        }

        return $record;
    }

    protected function getCreatedNotification(): ?\Filament\Notifications\Notification
    {
        return null;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
