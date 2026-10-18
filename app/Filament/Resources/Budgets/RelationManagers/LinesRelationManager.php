<?php

namespace App\Filament\Resources\Budgets\RelationManagers;

use App\Filament\Support\Ui;
use App\Models\BudgetLine;
use App\Services\BudgetLedger;
use App\Support\LineBalance;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Tableau de bord par ligne budgétaire — rapport financier principal (§4.5) :
 * budgétisé, pré-engagé, engagé, dépensé, solde disponible et taux de consommation.
 */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'activeLines';

    protected static ?string $title = 'Tableau de bord par ligne';

    /** @var array<int, LineBalance> */
    private array $balances = [];

    public function isReadOnly(): bool
    {
        return true;
    }

    private function balance(BudgetLine $line): LineBalance
    {
        return $this->balances[$line->id] ??= app(BudgetLedger::class)->balance($line);
    }

    private function amount(BudgetLine $line, string $field): string
    {
        $b = $this->balance($line);
        $value = match ($field) {
            'available' => $b->available(),
            default => $b->{$field},
        };

        return Ui::money((string) $value);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->paginated(false)
            ->columns([
                TextColumn::make('code')->label('Code')->weight('bold'),
                TextColumn::make('label')->label('Intitulé')->wrap(),
                TextColumn::make('budgeted')->label('Budgétisé')->alignEnd()->state(fn (BudgetLine $record) => $this->amount($record, 'budgeted')),
                TextColumn::make('pre_engaged')->label('Pré-engagé')->alignEnd()->state(fn (BudgetLine $record) => $this->amount($record, 'preEngaged')),
                TextColumn::make('engaged')->label('Engagé')->alignEnd()->state(fn (BudgetLine $record) => $this->amount($record, 'engaged')),
                TextColumn::make('spent')->label('Dépensé')->alignEnd()->state(fn (BudgetLine $record) => $this->amount($record, 'spent')),
                TextColumn::make('available')->label('Solde disponible')->alignEnd()->weight('bold')
                    ->state(fn (BudgetLine $record) => $this->amount($record, 'available'))
                    ->color(fn (BudgetLine $record) => $this->balance($record)->overrun() ? 'danger' : ($this->balance($record)->alert() ? 'warning' : 'success')),
                TextColumn::make('pct')->label('Consommé')->alignEnd()->badge()
                    ->state(fn (BudgetLine $record) => ($p = $this->balance($record)->consumptionPct()) === null ? '—' : number_format($p, 1, ',', ' ').' %')
                    ->color(fn (BudgetLine $record) => $this->balance($record)->overrun() ? 'danger' : ($this->balance($record)->alert() ? 'warning' : 'gray')),
            ])
            ->recordActions([
                Action::make('ledger')
                    ->label('Grand livre')
                    ->icon('heroicon-o-list-bullet')
                    ->modalHeading(fn (BudgetLine $record) => 'Grand livre — ligne '.$record->label())
                    ->modalWidth('5xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fermer')
                    ->modalContent(fn (BudgetLine $record) => view('filament.ledger', [
                        'movements' => $record->movements()->with('createdBy')->orderBy('id')->get(),
                    ])),
            ]);
    }
}
