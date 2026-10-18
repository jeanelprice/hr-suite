<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Enums\AccessLevel;
use App\Enums\AppModule;
use App\Models\ModuleAccess;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Unique;

/**
 * Habilitations module par module, octroyées par la Direction Exécutive (Fondation C).
 * Une date d'expiration rend l'accès temporaire, sans reconduction tacite (Fondation D).
 */
class ModuleAccessesRelationManager extends RelationManager
{
    protected static string $relationship = 'moduleAccesses';

    protected static ?string $title = 'Habilitations';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    private function isDe(): bool
    {
        return (bool) Auth::user()?->isDirectionExecutive();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('module')->label('Module')->options(AppModule::class)->required()
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('user_id', $this->getOwnerRecord()->id)),
            Select::make('level')->label('Niveau')->options(AccessLevel::class)->required(),
            DateTimePicker::make('expires_at')->label('Expire le (facultatif)')->native(false)->after('now'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('module')
            ->columns([
                TextColumn::make('module')->label('Module')->badge()->color('gray'),
                TextColumn::make('level')->label('Niveau')->badge(),
                TextColumn::make('expires_at')->label('Expire le')->dateTime('d/m/Y H:i')->placeholder('Sans limite'),
                TextColumn::make('grantedBy.name')->label('Octroyé par')->placeholder('—'),
                TextColumn::make('state')->label('État')->badge()
                    ->state(fn (ModuleAccess $record) => $record->revoked_at ? 'Retiré' : ($record->isActive() ? 'Actif' : 'Expiré'))
                    ->color(fn (string $state) => $state === 'Actif' ? 'success' : 'danger'),
            ])
            ->headerActions([
                CreateAction::make()->label('Octroyer un module')
                    ->visible(fn () => $this->isDe())
                    ->mutateDataUsing(fn (array $data) => $data + ['granted_by_id' => Auth::id()]),
            ])
            ->recordActions([
                EditAction::make()->label('Modifier')
                    ->visible(fn () => $this->isDe())
                    ->mutateDataUsing(fn (array $data) => $data + ['granted_by_id' => Auth::id(), 'revoked_at' => null]),
                Action::make('revoke')->label('Retirer')->color('danger')->icon('heroicon-o-no-symbol')
                    ->requiresConfirmation()
                    ->visible(fn (ModuleAccess $record) => $this->isDe() && $record->revoked_at === null)
                    ->action(fn (ModuleAccess $record) => $record->update(['revoked_at' => now()])),
            ]);
    }
}
