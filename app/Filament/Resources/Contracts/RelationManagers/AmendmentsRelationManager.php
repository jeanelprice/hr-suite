<?php

namespace App\Filament\Resources\Contracts\RelationManagers;

use App\Filament\Support\Ui;
use App\Models\ContractAmendment;
use App\Models\DutyStation;
use App\Models\Position;
use App\Support\PersonnelAccess;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class AmendmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'amendments';

    protected static ?string $modelLabel = 'avenant';

    protected static ?string $pluralModelLabel = 'avenants';

    protected static ?string $title = 'Avenants';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-document-duplicate';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return PersonnelAccess::canView(Auth::user());
    }

    public static function describe(ContractAmendment $a, string $side): ?string
    {
        $values = $side === 'old' ? $a->old_value : $a->new_value;
        $value = $values ? array_values($values)[0] : null;
        if ($value === null) {
            return null;
        }
        $contract = $a->contract;
        if (! PersonnelAccess::canSeeSensitive(Auth::user(), $contract->employee) && $a->kind->value === 'SALAIRE') {
            return '•••';
        }

        return match ($a->kind->value) {
            'SALAIRE' => Ui::money($value, $contract->currency),
            'POSTE' => Position::query()->find($value)?->title,
            'LIEU' => DutyStation::query()->find($value)?->name,
            default => Carbon::parse($value)->format('d/m/Y'),
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->emptyStateHeading('Aucun avenant')
            ->defaultSort('effective_date', 'desc')
            ->columns([
                TextColumn::make('effective_date')->label('Effet au')->date('d/m/Y'),
                TextColumn::make('kind')->label('Objet')->badge()->color('gray'),
                TextColumn::make('old')->label('Avant')->state(fn (ContractAmendment $record) => static::describe($record, 'old'))->placeholder('—'),
                TextColumn::make('new')->label('Après')->state(fn (ContractAmendment $record) => static::describe($record, 'new')),
                TextColumn::make('reason')->label('Motif')->limit(50),
                TextColumn::make('createdBy.name')->label('Par'),
            ]);
    }
}
