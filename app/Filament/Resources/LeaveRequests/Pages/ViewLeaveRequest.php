<?php

namespace App\Filament\Resources\LeaveRequests\Pages;

use App\Enums\LeaveStatus;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Support\Ui;
use App\Models\LeaveRequest;
use App\Services\LeaveService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

class ViewLeaveRequest extends ViewRecord
{
    protected static string $resource = LeaveRequestResource::class;

    public function getTitle(): string
    {
        return 'Congé '.$this->getRecord()->number;
    }

    public function getSubheading(): ?string
    {
        return $this->getRecord()->employee->full_name.' · '.$this->getRecord()->type->label;
    }

    private function req(): LeaveRequest
    {
        return $this->getRecord();
    }

    protected function getHeaderActions(): array
    {
        $s = app(LeaveService::class);
        $can = fn () => $s->canSupervise(Auth::user(), $this->req()) || $s->canApprove(Auth::user(), $this->req());
        $first = fn () => $this->req()->status === LeaveStatus::SOUMISE;

        return [
            Action::make('validate')->label(fn () => $first() ? 'Valider (supérieur)' : 'Approuver')->icon('heroicon-o-check')->color('success')
                ->visible($can)
                ->schema([Textarea::make('comment')->label('Commentaire (facultatif)')->rows(2)])
                ->modalSubmitActionLabel(fn () => $first() ? 'Valider et transmettre' : 'Approuver le congé')
                ->action(fn (array $data) => Ui::attempt(fn () => $first()
                    ? $s->supervisorDecision(Auth::user(), $this->req(), true, $data['comment'] ?? null)
                    : $s->finalDecision(Auth::user(), $this->req(), true, $data['comment'] ?? null), 'Décision enregistrée.')),
            Action::make('reject')->label('Refuser')->icon('heroicon-o-x-mark')->color('danger')
                ->visible($can)
                ->schema([Textarea::make('comment')->label('Motif du refus')->required()->rows(2)])
                ->modalSubmitActionLabel('Refuser la demande')
                ->action(fn (array $data) => Ui::attempt(fn () => $first()
                    ? $s->supervisorDecision(Auth::user(), $this->req(), false, $data['comment'])
                    : $s->finalDecision(Auth::user(), $this->req(), false, $data['comment']), 'Demande refusée.')),
            Action::make('cancel')->label('Annuler')->icon('heroicon-o-arrow-uturn-left')->color('gray')
                ->visible(fn () => $s->canCancel(Auth::user(), $this->req()))
                ->modalDescription('Un congé approuvé annulé rend ses jours au solde.')
                ->schema([Textarea::make('reason')->label('Motif')->required()->rows(2)])
                ->modalSubmitActionLabel('Annuler la demande')
                ->action(fn (array $data) => Ui::attempt(fn () => $s->cancel(Auth::user(), $this->req(), $data['reason']), 'Demande annulée.')),
        ];
    }
}
