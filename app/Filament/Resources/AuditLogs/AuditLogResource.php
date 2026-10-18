<?php

namespace App\Filament\Resources\AuditLogs;

use App\Enums\Role;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Journal d'audit en lecture seule (RG-20), pour le Comité de Contrôle (Article 21)
 * et la Direction Exécutive. Aucune action ne permet de le modifier.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = "entrée d'audit";

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $pluralModelLabel = "Journal d'audit";

    public static function canViewAny(): bool
    {
        return in_array(Auth::user()?->role, [Role::DIRECTION_EXECUTIVE, Role::COMITE_CONTROLE], true);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('N°')->sortable(),
                TextColumn::make('occurred_at')->label('Date')->dateTime('d/m/Y H:i:s'),
                TextColumn::make('actor.name')->label('Auteur')->placeholder('Système'),
                TextColumn::make('action')->label('Action')->badge()->color('gray')->searchable(),
                TextColumn::make('entity_type')->label('Élément'),
                TextColumn::make('entity_id')->label('Réf.'),
                TextColumn::make('hash')->label('Empreinte')->limit(12)->fontFamily('mono')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('entity_type')->label('Élément')
                    ->options(fn () => AuditLog::query()->distinct()->orderBy('entity_type')->pluck('entity_type', 'entity_type')),
                SelectFilter::make('actor_id')->label('Auteur')->relationship('actor', 'name'),
            ])
            ->recordActions([
                Action::make('details')->label('Détail')->icon('heroicon-o-eye')
                    ->modalHeading(fn (AuditLog $record) => "Entrée n° {$record->id} — {$record->action}")
                    ->modalSubmitAction(false)->modalCancelActionLabel('Fermer')
                    ->modalContent(fn (AuditLog $record) => view('filament.audit-entry', ['entry' => $record])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAuditLogs::route('/')];
    }
}
