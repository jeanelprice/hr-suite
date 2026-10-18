<?php

namespace App\Filament\Resources\Budgets\Pages;

use App\Enums\AccessLevel;
use App\Enums\AppModule;
use App\Enums\BudgetVersionStatus;
use App\Filament\Resources\Budgets\BudgetResource;
use App\Filament\Support\Ui;
use App\Models\Budget;
use App\Services\BudgetService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

/**
 * Tableau de bord d'un budget (onglet Lignes) et historique des versions.
 * Action « Nouvelle révision » : dérivée de la version en vigueur (RG-10).
 */
class ViewBudget extends ViewRecord
{
    protected static string $resource = BudgetResource::class;

    public function getTitle(): string
    {
        return 'Budget '.$this->getRecord()->label();
    }

    private function budget(): Budget
    {
        /** @var Budget */
        return $this->getRecord();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('revise')
                ->label('Nouvelle révision')
                ->modalSubmitActionLabel('Créer la révision')
                ->icon('heroicon-o-pencil-square')
                ->visible(fn () => $this->budget()->current_version_id
                    && Auth::user()->canInModule(AppModule::FINANCES, AccessLevel::CREATION)
                    && ! $this->budget()->versions()->whereIn('status', [BudgetVersionStatus::BROUILLON->value, BudgetVersionStatus::SOUMISE->value])->exists())
                ->modalHeading('Révision budgétaire')
                ->modalDescription('Modifiez les montants ou ajoutez des lignes. La version en vigueur sera archivée à la validation. Une ligne ne peut pas descendre sous son montant déjà consommé (RG-10).')
                ->modalWidth('5xl')
                ->fillForm(fn () => [
                    'lines' => $this->budget()->currentVersion->amounts()->with('line')->get()
                        ->sortBy(fn ($a) => $a->line->code)
                        ->map(fn ($a) => ['code' => $a->line->code, 'label' => $a->line->label, 'amount' => $a->amount])
                        ->values()->all(),
                ])
                ->schema([
                    TextInput::make('reason')->label('Motif de la révision')->required()->minLength(5),
                    Repeater::make('lines')
                        ->label('Lignes')
                        ->addActionLabel('Ajouter une ligne')
                        ->columns(12)
                        ->schema([
                            TextInput::make('code')->label('Code')->required()->maxLength(20)->columnSpan(2),
                            TextInput::make('label')->label('Intitulé')->required()->columnSpan(6),
                            TextInput::make('amount')->label('Montant')->numeric()->minValue(0)->required()->columnSpan(4),
                        ]),
                ])
                ->action(function (array $data) {
                    // Seules les lignes modifiées ou nouvelles sont transmises
                    $current = $this->budget()->currentVersion->amounts()->with('line')->get()->mapWithKeys(fn ($a) => [$a->line->code => $a->amount]);
                    $changes = collect($data['lines'])
                        ->map(fn ($l) => ['code' => trim($l['code']), 'label' => $l['label'], 'amount' => (string) $l['amount']])
                        ->filter(fn ($l) => ! $current->has($l['code']) || ! Money::of($current[$l['code']])->isEqualTo(Money::of($l['amount'])))
                        ->values()->all();
                    if ($changes === []) {
                        \Filament\Notifications\Notification::make()->warning()->title('Aucun montant modifié : pas de révision créée.')->send();

                        return;
                    }
                    if (Ui::attempt(fn () => app(BudgetService::class)->revise(Auth::user(), $this->budget(), $data['reason'], $changes), 'Révision créée en brouillon. Soumettez-la dans l\'onglet « Versions ».')) {
                        $this->redirect(BudgetResource::getUrl('view', ['record' => $this->budget()]));
                    }
                }),
        ];
    }
}
