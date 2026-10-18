<?php

namespace App\Filament\Resources\PurchaseRequests\Pages;

use App\Enums\Currency;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Support\Ui;
use App\Models\BudgetLine;
use App\Models\Project;
use App\Services\PurchaseRequestService;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreatePurchaseRequest extends CreateRecord
{
    protected static bool $canCreateAnother = false;

    protected static string $resource = PurchaseRequestResource::class;

    protected static ?string $title = "Nouvelle demande d'achat";

    /** La création passe par le service : contrôle du solde, numérotation, audit */
    protected function handleRecordCreation(array $data): Model
    {
        $record = null;
        $ok = Ui::attempt(function () use ($data, &$record) {
            $record = app(PurchaseRequestService::class)->create(
                Auth::user(),
                Project::query()->findOrFail($data['project_id']),
                BudgetLine::query()->findOrFail($data['budget_line_id']),
                $data['purpose'],
                Currency::from($data['currency'] instanceof Currency ? $data['currency']->value : $data['currency']),
                array_values($data['items']),
                filled($data['derogation_request'] ?? null) ? $data['derogation_request'] : null,
            );
        }, 'Demande enregistrée en brouillon. Pensez à la soumettre pour validation.');

        if (! $ok) {
            throw new Halt;
        }

        return $record;
    }

    protected function getCreatedNotification(): ?\Filament\Notifications\Notification
    {
        return null; // la notification est envoyée par Ui::attempt
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
