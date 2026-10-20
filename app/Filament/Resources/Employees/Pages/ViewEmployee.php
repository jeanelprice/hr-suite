<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Enums\EmployeeStatus;
use App\Enums\ExitReason;
use App\Enums\ServiceCardStatus;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Support\Ui;
use App\Models\Employee;
use App\Services\EmployeeService;
use App\Support\PersonnelAccess;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

class ViewEmployee extends ViewRecord
{
    protected static string $resource = EmployeeResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        // RG-26 : chaque consultation d'un dossier est tracée
        app(EmployeeService::class)->logConsultation(Auth::user(), $this->getRecord());
    }

    public function getTitle(): string
    {
        return $this->getRecord()->full_name;
    }

    public function getSubheading(): ?string
    {
        return $this->getRecord()->matricule.' · '.($this->getRecord()->position?->title ?? 'Poste non renseigné');
    }

    private function employee(): Employee
    {
        return $this->getRecord();
    }

    private function canManage(): bool
    {
        return PersonnelAccess::canManage(Auth::user());
    }

    private function service(): EmployeeService
    {
        return app(EmployeeService::class);
    }

    private function activeCard()
    {
        return $this->employee()->serviceCards()->where('status', ServiceCardStatus::ACTIVE->value)->latest('id')->first();
    }

    protected function getHeaderActions(): array
    {
        $out = fn () => $this->employee()->status === EmployeeStatus::SORTI;

        return [
            EditAction::make()->label('Modifier')->visible(fn () => EmployeeResource::canEdit($this->employee())),
            Action::make('newContract')->label('Nouveau contrat')->icon('heroicon-o-document-plus')
                ->visible(fn () => $this->canManage() && ! $out())
                ->url(fn () => ContractResource::getUrl('create', ['employee' => $this->employee()->id])),
            ActionGroup::make([
                Action::make('issueCard')->label('Délivrer la carte de service')->icon('heroicon-o-identification')
                    ->visible(fn () => $this->canManage() && ! $out() && ! $this->activeCard())
                    ->modalDescription('La carte reste la propriété de HR et sera restituée en fin de contrat (Manuel §3.13).')
                    ->schema([DatePicker::make('expires_at')->label('Valable jusqu\'au')->native(false)
                        ->default(fn () => $this->employee()->activeContract?->end_date)])
                    ->modalSubmitActionLabel('Délivrer')
                    ->action(fn (array $data) => Ui::attempt(fn () => $this->service()->issueCard(Auth::user(), $this->employee(), $data['expires_at'] ?? null), 'Carte de service délivrée.')),
                Action::make('printCard')->label('Imprimer la carte')->icon('heroicon-o-printer')
                    ->visible(fn () => $this->canManage() && $this->activeCard())
                    ->url(fn () => route('print.card', $this->employee()), shouldOpenInNewTab: true),
                Action::make('lostCard')->label('Déclarer la carte perdue')->icon('heroicon-o-exclamation-triangle')->color('danger')
                    ->visible(fn () => $this->canManage() && $this->activeCard())
                    ->modalDescription('Le remplacement est à la charge de l\'employé, sur preuve de la perte (Manuel §3.13).')
                    ->schema([TextInput::make('fee')->label('Coût du remplacement (USD)')->numeric()->minValue(0)])
                    ->modalSubmitActionLabel('Déclarer perdue')
                    ->action(fn (array $data) => Ui::attempt(fn () => $this->service()->closeCard(Auth::user(), $this->activeCard(), ServiceCardStatus::PERDUE, $data['fee'] ?? null), 'Carte déclarée perdue. Vous pouvez en délivrer une nouvelle.')),
                Action::make('returnedCard')->label('Carte restituée')->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn () => $this->canManage() && $this->activeCard())
                    ->requiresConfirmation()->modalSubmitActionLabel('Confirmer la restitution')
                    ->action(fn () => Ui::attempt(fn () => $this->service()->closeCard(Auth::user(), $this->activeCard(), ServiceCardStatus::RESTITUEE), 'Restitution enregistrée.')),
            ])->label('Carte de service')->icon('heroicon-o-identification')->button()->color('gray')
                ->visible(fn () => $this->canManage()),
            ActionGroup::make([
                Action::make('recordExit')->label('Enregistrer la sortie')->icon('heroicon-o-arrow-right-start-on-rectangle')->color('danger')
                    ->visible(fn () => $this->canManage() && ! $out())
                    ->modalDescription('Le contrat actif prend fin et le compte de connexion est désactivé. Le dernier salaire attend le certificat d\'autorisation (Manuel §5.2).')
                    ->schema([
                        Select::make('reason')->label('Motif')->options(ExitReason::class)->required(),
                        DatePicker::make('exit_date')->label('Date de sortie')->native(false)->default(now())->required(),
                        Textarea::make('debrief_notes')->label('Compte rendu de fin de contrat (Manuel §2.7)')->rows(3),
                        Toggle::make('card_returned')->label('Carte de service restituée'),
                        Toggle::make('equipment_returned')->label('Matériel de l\'organisation rendu'),
                    ])
                    ->modalSubmitActionLabel('Enregistrer la sortie')
                    ->action(fn (array $data) => Ui::attempt(fn () => $this->service()->recordExit(Auth::user(), $this->employee(), $data), 'Sortie enregistrée.')),
                Action::make('editExit')->label('Compléter la sortie')->icon('heroicon-o-pencil-square')
                    ->visible(fn () => $this->canManage() && $this->employee()->exitRecord && ! $this->employee()->exitRecord->clearance_issued_at)
                    ->fillForm(fn () => $this->employee()->exitRecord->only(['debrief_notes', 'card_returned', 'equipment_returned']))
                    ->schema([
                        Textarea::make('debrief_notes')->label('Compte rendu de fin de contrat')->rows(4),
                        Toggle::make('card_returned')->label('Carte de service restituée'),
                        Toggle::make('equipment_returned')->label('Matériel rendu'),
                    ])
                    ->modalSubmitActionLabel('Enregistrer')
                    ->action(fn (array $data) => Ui::attempt(fn () => $this->service()->updateExit(Auth::user(), $this->employee()->exitRecord, $data), 'Sortie mise à jour.')),
                Action::make('clearance')->label('Délivrer le certificat d\'autorisation')->icon('heroicon-o-check-badge')->color('success')
                    ->visible(fn () => $this->canManage() && $this->employee()->exitRecord && ! $this->employee()->exitRecord->clearance_issued_at)
                    ->requiresConfirmation()
                    ->modalDescription('Carte et matériel restitués, compte rendu rédigé : le dernier salaire pourra être payé.')
                    ->modalSubmitActionLabel('Délivrer le certificat')
                    ->action(fn () => Ui::attempt(fn () => $this->service()->issueClearance(Auth::user(), $this->employee()->exitRecord), 'Certificat d\'autorisation délivré.')),
            ])->label('Sortie')->icon('heroicon-o-arrow-right-start-on-rectangle')->button()->color('gray')
                ->visible(fn () => $this->canManage()),
        ];
    }
}
