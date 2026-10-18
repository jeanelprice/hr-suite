<?php

namespace App\Filament\Resources\Derogations;

use App\Enums\AppModule;
use App\Filament\Concerns\HasModuleAccess;
use App\Filament\Support\Ui;
use App\Models\Derogation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Registre des dérogations accordées par la Direction Exécutive (RG-09), en lecture seule */
class DerogationResource extends Resource
{
    use HasModuleAccess;

    protected static AppModule $module = AppModule::FINANCES;

    protected static ?string $model = Derogation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'Finances';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'dérogation';

    protected static ?string $pluralModelLabel = "Dérogations";

    protected static bool $hasTitleCaseModelLabel = false;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $open = Derogation::query()->whereNull('regularized_at')->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('granted_at', 'desc')
            ->columns([
                TextColumn::make('line.budget.project.code')->label('Projet'),
                TextColumn::make('line.code')->label('Ligne')->formatStateUsing(fn (Derogation $record) => $record->line->label()),
                TextColumn::make('amount_over')->label('Dépassement autorisé')->alignEnd()
                    ->formatStateUsing(fn ($state, Derogation $record) => Ui::money($state, $record->line->budget->currency)),
                TextColumn::make('justification')->label('Justification')->limit(50)->tooltip(fn (Derogation $record) => $record->justification),
                TextColumn::make('grantedBy.name')->label('Accordée par'),
                TextColumn::make('granted_at')->label('Le')->date('d/m/Y'),
                TextColumn::make('regularize_by')->label('À régulariser avant')->date('d/m/Y')
                    ->color(fn (Derogation $record) => ! $record->regularized_at && $record->regularize_by->isPast() ? 'danger' : null),
                TextColumn::make('regularized_at')->label('Régularisée le')->date('d/m/Y')->placeholder('En attente'),
            ])
            ->filters([
                TernaryFilter::make('regularized')->label('Régularisée')
                    ->queries(true: fn (Builder $q) => $q->whereNotNull('regularized_at'), false: fn (Builder $q) => $q->whereNull('regularized_at')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListDerogations::route('/')];
    }
}
