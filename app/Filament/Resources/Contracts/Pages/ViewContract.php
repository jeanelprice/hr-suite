<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Enums\AmendmentKind;
use App\Enums\ContractStatus;
use App\Enums\ProbationOutcome;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Support\Ui;
use App\Models\Contract;
use App\Models\DutyStation;
use App\Models\Position;
use App\Services\ContractService;
use App\Support\PersonnelAccess;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;

class ViewContract extends ViewRecord
{
    protected static string $resource = ContractResource::class;

    public function getTitle(): string
    {
        return 'Contrat '.$this->getRecord()->number;
    }

    public function getSubheading(): ?string
    {
        return $this->getRecord()->employee->full_name.' · '.$this->getRecord()->type->getLabel();
    }

    private function contract(): Contract
    {
        return $this->getRecord();
    }

    private function service(): ContractService
    {
        return app(ContractService::class);
    }

    private function canManage(): bool
    {
        return PersonnelAccess::canManage(Auth::user());
    }

    private function isActive(): bool
    {
        return $this->contract()->status === ContractStatus::ACTIF;
    }

    protected function getHeaderActions(): array
    {
        $kind = fn (Get $get) => $get('kind') instanceof AmendmentKind ? $get('kind') : AmendmentKind::tryFrom((string) $get('kind'));

        return [
            Action::make('print')->label('Imprimer')->icon('heroicon-o-printer')->color('gray')
                ->visible(fn () => PersonnelAccess::canSeeSensitive(Auth::user(), $this->contract()->employee))
                ->url(fn () => route('print.contract', $this->contract()), shouldOpenInNewTab: true),
            EditAction::make()->label('Modifier le brouillon')->visible(fn () => ContractResource::canEdit($this->contract())),
            Action::make('activate')->label('Contrat signé')->icon('heroicon-o-pencil-square')->color('success')
                ->visible(fn () => $this->canManage() && $this->contract()->status === ContractStatus::BROUILLON)
                ->modalDescription('Le contrat devient actif. Poste et lieu sont reportés dans le dossier. Joignez ensuite l\'exemplaire signé dans « Documents ».')
                ->schema([DatePicker::make('signed_at')->label('Date de signature')->native(false)->default(now())->required()])
                ->modalSubmitActionLabel('Activer le contrat')
                ->action(fn (array $data) => Ui::attempt(fn () => $this->service()->activate(Auth::user(), $this->contract(), $data['signed_at']), 'Contrat actif.')),
            Action::make('amend')->label('Avenant')->icon('heroicon-o-document-duplicate')
                ->visible(fn () => $this->canManage() && $this->isActive())
                ->modalDescription('Un avenant ne modifie pas le contrat d\'origine : il s\'applique à sa date d\'effet. Une lettre de transfert se saisit comme un avenant « Lieu d\'affectation ».')
                ->schema([
                    Select::make('kind')->label('Objet')->options(AmendmentKind::class)->required()->live(),
                    TextInput::make('salary')->label('Nouveau salaire de base')->numeric()->minValue(0.01)
                        ->visible(fn (Get $get) => $kind($get) === AmendmentKind::SALAIRE)->required(fn (Get $get) => $kind($get) === AmendmentKind::SALAIRE),
                    Select::make('position_id')->label('Nouveau poste')->options(fn () => Position::query()->orderBy('title')->pluck('title', 'id'))->searchable()
                        ->visible(fn (Get $get) => $kind($get) === AmendmentKind::POSTE)->required(fn (Get $get) => $kind($get) === AmendmentKind::POSTE),
                    Select::make('duty_station_id')->label('Nouveau lieu')->options(fn () => DutyStation::query()->orderBy('name')->pluck('name', 'id'))
                        ->visible(fn (Get $get) => $kind($get) === AmendmentKind::LIEU)->required(fn (Get $get) => $kind($get) === AmendmentKind::LIEU),
                    DatePicker::make('date')->label('Nouvelle date')->native(false)
                        ->visible(fn (Get $get) => in_array($kind($get), [AmendmentKind::PROLONGATION_CONTRAT, AmendmentKind::PROLONGATION_ESSAI], true))
                        ->required(fn (Get $get) => in_array($kind($get), [AmendmentKind::PROLONGATION_CONTRAT, AmendmentKind::PROLONGATION_ESSAI], true)),
                    DatePicker::make('effective_date')->label('Date d\'effet')->native(false)->default(now())->required(),
                    Textarea::make('reason')->label('Motif')->rows(2)->required(),
                ])
                ->modalSubmitActionLabel('Enregistrer l\'avenant')
                ->action(function (array $data) {
                    $kind = $data['kind'] instanceof AmendmentKind ? $data['kind'] : AmendmentKind::from($data['kind']);
                    $value = match ($kind) {
                        AmendmentKind::SALAIRE => $data['salary'],
                        AmendmentKind::POSTE => $data['position_id'],
                        AmendmentKind::LIEU => $data['duty_station_id'],
                        default => $data['date'],
                    };
                    Ui::attempt(fn () => $this->service()->amend(Auth::user(), $this->contract(), $kind, $value, $data['effective_date'], $data['reason']), 'Avenant enregistré.');
                }),
            Action::make('probation')->label('Décision de fin d\'essai')->icon('heroicon-o-check-badge')->color('warning')
                ->visible(fn () => Auth::user()->isDirectionExecutive() && $this->isActive() && $this->contract()->probation_end_date
                    && in_array($this->contract()->probation_outcome, [null, ProbationOutcome::PROLONGE], true))
                ->modalDescription('Sur recommandation du supérieur et du RH, après l\'évaluation intermédiaire (Manuel §2.4.8).')
                ->schema([
                    Select::make('outcome')->label('Décision')->options(ProbationOutcome::class)->required()->live(),
                    DatePicker::make('new_end')->label('Nouvelle fin d\'essai')->native(false)
                        ->visible(fn (Get $get) => in_array($get('outcome'), [ProbationOutcome::PROLONGE, 'PROLONGE'], true))
                        ->required(fn (Get $get) => in_array($get('outcome'), [ProbationOutcome::PROLONGE, 'PROLONGE'], true)),
                    Textarea::make('comment')->label('Motif')->rows(2)->required(),
                ])
                ->modalSubmitActionLabel('Enregistrer la décision')
                ->action(fn (array $data) => Ui::attempt(fn () => $this->service()->decideProbation(
                    Auth::user(), $this->contract(),
                    $data['outcome'] instanceof ProbationOutcome ? $data['outcome'] : ProbationOutcome::from($data['outcome']),
                    $data['comment'], $data['new_end'] ?? null,
                ), 'Décision enregistrée. Le RH est prévenu.')),
            Action::make('terminate')->label('Mettre fin')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn () => $this->canManage() && $this->isActive())
                ->modalDescription('Pour une sortie définitive de l\'employé, utilisez plutôt « Sortie » dans son dossier : elle clôt aussi le contrat.')
                ->schema([
                    DatePicker::make('date')->label('Date de fin')->native(false)->default(now())->required(),
                    Textarea::make('reason')->label('Motif')->rows(2)->required(),
                ])
                ->modalSubmitActionLabel('Mettre fin au contrat')
                ->action(fn (array $data) => Ui::attempt(fn () => $this->service()->terminate(Auth::user(), $this->contract(), $data['date'], $data['reason']), 'Contrat clôturé.')),
        ];
    }
}
