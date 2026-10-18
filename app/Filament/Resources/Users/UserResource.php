<?php

namespace App\Filament\Resources\Users;

use App\Enums\Role;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Fondation C — Comptes utilisateurs. Créés par la DE ou l'administrateur technique ;
 * l'octroi des modules (onglet Habilitations) est réservé à la Direction Exécutive.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'utilisateur';

    protected static ?string $pluralModelLabel = "Utilisateurs";

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return in_array(Auth::user()?->role, [Role::DIRECTION_EXECUTIVE, Role::ADMINISTRATEUR], true);
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return false; // un compte se désactive, il ne se supprime pas (traçabilité)
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->label('Nom complet')->required(),
                TextInput::make('email')->label('Email')->email()->required()->unique(ignoreRecord: true),
                Select::make('role')->label('Rôle')->options(Role::class)->required(),
                Select::make('supervisor_id')->label('Supérieur hiérarchique direct')
                    ->relationship('supervisor', 'name', fn ($query, ?User $record) => $record ? $query->whereKeyNot($record->id) : $query)
                    ->searchable()->preload()
                    ->helperText('Reçoit les alertes budgétaires de cette personne (RG-08).'),
                TextInput::make('password')->label('Mot de passe')->password()->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->minLength(12)
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->helperText(fn (string $operation) => $operation === 'edit' ? 'Laissez vide pour conserver le mot de passe actuel.' : '12 caractères minimum.'),
                Toggle::make('is_active')->label('Compte actif')->default(true)
                    ->helperText('Un compte désactivé ne peut plus se connecter.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Nom')->searchable()->sortable(),
                TextColumn::make('email')->label('Email')->searchable(),
                TextColumn::make('role')->label('Rôle')->badge(),
                TextColumn::make('supervisor.name')->label('Supérieur')->placeholder('—')->toggleable(),
                TextColumn::make('module_accesses_count')->label('Modules')->counts(['moduleAccesses' => fn ($q) => $q->whereNull('revoked_at')]),
                IconColumn::make('is_active')->label('Actif')->boolean(),
            ])
            ->filters([SelectFilter::make('role')->label('Rôle')->options(Role::class)])
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\ModuleAccessesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
