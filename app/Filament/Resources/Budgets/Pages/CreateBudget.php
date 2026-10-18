<?php

namespace App\Filament\Resources\Budgets\Pages;

use App\Enums\Currency;
use App\Filament\Resources\Budgets\BudgetResource;
use App\Filament\Support\Ui;
use App\Models\Project;
use App\Services\BudgetService;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateBudget extends CreateRecord
{
    protected static bool $canCreateAnother = false;

    protected static string $resource = BudgetResource::class;

    protected static ?string $title = 'Saisir un budget';

    protected function handleRecordCreation(array $data): Model
    {
        $record = null;
        $ok = Ui::attempt(function () use ($data, &$record) {
            $record = app(BudgetService::class)->create(
                Auth::user(),
                Project::query()->findOrFail($data['project_id']),
                (int) $data['year'],
                $data['currency'] instanceof Currency ? $data['currency'] : Currency::from($data['currency']),
                array_map(fn ($l) => ['code' => trim($l['code']), 'label' => $l['label'], 'category' => $l['category'] ?? null, 'amount' => (string) $l['amount']], array_values($data['lines'])),
            );
        }, 'Budget enregistré (version 1, brouillon). Soumettez-le pour validation dans l\'onglet « Versions ».');

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
