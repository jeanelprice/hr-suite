<?php

namespace App\Filament\Resources\Delegations;

use App\Enums\AppModule;
use App\Models\Delegation;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/** Délégations temporaires de validation, accordées par la Direction Exécutive (RG-14) */
class DelegationResource extends Resource
{
    protected static ?string $model = Delegation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'délégation';

    protected static ?string $pluralModelLabel = "Délégations";

    protected static bool $hasTitleCaseModelLabel = false;

    public static function canViewAny(): bool
    {
        return (bool) Auth::user()?->isDirectionExecutive();
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('delegate_id')->label('Délégué')
                ->options(fn () => User::query()->where('is_active', true)->whereKeyNot(Auth::id())->orderBy('name')->pluck('name', 'id'))
                ->required()->searchable(),
            TextInput::make('reason')->label('Motif')->required()->minLength(5)->placeholder('Ex. mission de la DE à Kinshasa'),
            DateTimePicker::make('starts_at')->label('Début')->default(now())->required()->native(false),
            DateTimePicker::make('ends_at')->label('Fin (obligatoire)')->required()->native(false)->after('starts_at'),
            CheckboxList::make('modules')->label('Modules délégués')->options(AppModule::class)->required()->columns(3)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('delegate.name')->label('Délégué'),
                TextColumn::make('modules')->label('Modules')->badge()->color('gray')
                    ->formatStateUsing(fn ($state) => AppModule::tryFrom($state)?->getLabel() ?? $state),
                TextColumn::make('starts_at')->label('Du')->dateTime('d/m/Y H:i'),
                TextColumn::make('ends_at')->label('Au')->dateTime('d/m/Y H:i'),
                TextColumn::make('reason')->label('Motif')->limit(40),
                TextColumn::make('state')->label('État')->badge()
                    ->state(fn (Delegation $r) => $r->revoked_at ? 'Révoquée' : ($r->ends_at->isPast() ? 'Terminée' : ($r->starts_at->isFuture() ? 'À venir' : 'En cours')))
                    ->color(fn (string $state) => $state === 'En cours' ? 'success' : 'gray'),
            ])
            ->recordActions([
                Action::make('revoke')->label('Révoquer')->color('danger')->requiresConfirmation()
                    ->visible(fn (Delegation $r) => $r->revoked_at === null && $r->ends_at->isFuture())
                    ->action(fn (Delegation $r) => $r->update(['revoked_at' => now()])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageDelegations::route('/')];
    }
}
