<?php

namespace App\Filament\Resources\Budgets\RelationManagers;

use App\Enums\AccessLevel;
use App\Enums\AppModule;
use App\Enums\BudgetVersionStatus;
use App\Filament\Support\Ui;
use App\Models\BudgetVersion;
use App\Services\ApprovalService;
use App\Services\BudgetService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Versions du budget : budget initial puis révisions. Une version archivée
 * reste consultable (RG-10). Actions : soumettre, valider, rejeter.
 */
class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Versions';

    public function isReadOnly(): bool
    {
        return true;
    }

    private function canDecide(BudgetVersion $v): bool
    {
        $user = Auth::user();

        return $v->status === BudgetVersionStatus::SOUMISE
            && $user->canInModule(AppModule::FINANCES, AccessLevel::VALIDATION)
            && app(ApprovalService::class)->canValidate($user, $v->created_by_id, AppModule::FINANCES);
    }

    private function refreshPage(): void
    {
        $this->redirect(\App\Filament\Resources\Budgets\BudgetResource::getUrl('view', ['record' => $this->getOwnerRecord()]));
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('version_no', 'desc')
            ->paginated(false)
            ->columns([
                TextColumn::make('version_no')->label('Version')->formatStateUsing(fn ($state) => "v{$state}")->weight('bold'),
                TextColumn::make('status')->label('Statut')->badge(),
                TextColumn::make('reason')->label('Motif')->wrap(),
                TextColumn::make('total')->label('Total')->alignEnd()
                    ->state(fn (BudgetVersion $record) => Ui::money((string) \App\Support\Money::sum($record->amounts()->pluck('amount')), $this->getOwnerRecord()->currency)),
                TextColumn::make('createdBy.name')->label('Saisie par'),
                TextColumn::make('approvedBy.name')->label('Validée par')->placeholder('—'),
                TextColumn::make('approved_at')->label('Validée le')->date('d/m/Y')->placeholder('—'),
                TextColumn::make('archived_at')->label('Archivée le')->dateTime('d/m/Y H:i')->placeholder('—'),
            ])
            ->recordActions([
                Action::make('details')
                    ->label('Détail')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (BudgetVersion $record) => "Version {$record->version_no} — montants par ligne")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fermer')
                    ->modalContent(fn (BudgetVersion $record) => view('filament.version-amounts', [
                        'amounts' => $record->amounts()->with('line')->get()->sortBy(fn ($a) => $a->line->code),
                        'currency' => $this->getOwnerRecord()->currency->value,
                    ])),
                Action::make('submitVersion')
                    ->label('Soumettre')
                    ->icon('heroicon-o-paper-airplane')
                    ->requiresConfirmation()
                    ->visible(fn (BudgetVersion $record) => $record->status === BudgetVersionStatus::BROUILLON && $record->created_by_id === Auth::id())
                    ->action(function (BudgetVersion $record) {
                        if (Ui::attempt(fn () => app(BudgetService::class)->submit(Auth::user(), $record), 'Version soumise à la Direction Exécutive.')) {
                            $this->refreshPage();
                        }
                    }),
                Action::make('approveVersion')
                    ->label('Valider')
                    ->modalSubmitActionLabel('Valider la version')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (BudgetVersion $record) => $this->canDecide($record))
                    ->modalDescription('La version en vigueur sera archivée et remplacée par celle-ci.')
                    ->schema([Textarea::make('comment')->label('Commentaire (facultatif)')->rows(2)])
                    ->action(function (BudgetVersion $record, array $data) {
                        if (Ui::attempt(fn () => app(BudgetService::class)->approve(Auth::user(), $record, $data['comment'] ?? null), 'Version validée : elle est désormais en vigueur.')) {
                            $this->refreshPage();
                        }
                    }),
                Action::make('rejectVersion')
                    ->label('Rejeter')
                    ->modalSubmitActionLabel('Rejeter la version')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (BudgetVersion $record) => $this->canDecide($record))
                    ->schema([Textarea::make('comment')->label('Motif du rejet')->required()->minLength(5)])
                    ->action(function (BudgetVersion $record, array $data) {
                        if (Ui::attempt(fn () => app(BudgetService::class)->reject(Auth::user(), $record, $data['comment']), 'Version rejetée.')) {
                            $this->refreshPage();
                        }
                    }),
            ]);
    }
}
