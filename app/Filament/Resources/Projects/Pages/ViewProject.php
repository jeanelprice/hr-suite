<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Enums\ProjectStatus;
use App\Filament\Resources\Budgets\BudgetResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\Ui;
use App\Services\ProjectService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    public function getTitle(): string
    {
        return 'Projet '.$this->getRecord()->code;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('budgets')
                ->label('Budgets du projet')
                ->icon('heroicon-o-calculator')
                ->color('gray')
                ->url(fn () => BudgetResource::getUrl('index')),
            EditAction::make(),
            Action::make('close')
                ->label('Clôturer le projet')
                ->modalSubmitActionLabel('Clôturer')
                ->icon('heroicon-o-lock-closed')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Plus aucune opération financière ne sera possible. Tous les bons de commande doivent être clôturés.')
                ->visible(fn () => Auth::user()->isDirectionExecutive() && $this->getRecord()->status !== ProjectStatus::CLOTURE)
                ->action(fn () => Ui::attempt(fn () => app(ProjectService::class)->close(Auth::user(), $this->getRecord()), 'Projet clôturé.')),
        ];
    }
}
