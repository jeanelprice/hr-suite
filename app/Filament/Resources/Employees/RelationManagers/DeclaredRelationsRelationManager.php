<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Filament\Support\Ui;
use App\Models\Employee;
use App\Models\EmployeeRelation;
use App\Services\EmployeeService;
use App\Support\PersonnelAccess;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Liens de parenté déclarés avec un employé ou un membre du CA (Manuel §3.17).
 * Ils empêchent qu'un parent soit supérieur, évaluateur ou membre du jury de l'autre (RG-27).
 */
class DeclaredRelationsRelationManager extends RelationManager
{
    protected static string $relationship = 'declaredRelations';

    protected static ?string $modelLabel = 'lien déclaré';

    protected static ?string $pluralModelLabel = 'liens déclarés';

    protected static ?string $title = 'Liens déclarés';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-link';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return PersonnelAccess::canSeeSensitive(Auth::user(), $ownerRecord);
    }

    public function isReadOnly(): bool
    {
        return ! PersonnelAccess::canManage(Auth::user());
    }

    public function table(Table $table): Table
    {
        return $table
            ->emptyStateHeading('Aucun lien déclaré')
            ->description('Relations familiales avec un autre employé ou un membre du Conseil d\'Administration, à déclarer avant l\'embauche (Manuel §3.17).')
            ->columns([
                TextColumn::make('person')->label('Personne')
                    ->state(fn (EmployeeRelation $record) => $record->relatedEmployee?->full_name ?? $record->related_name),
                TextColumn::make('kind')->label('Qualité')
                    ->state(fn (EmployeeRelation $record) => $record->related_employee_id ? 'Employé' : 'Membre du CA ou autre'),
                TextColumn::make('relation')->label('Lien'),
                TextColumn::make('declared_at')->label('Déclaré le')->date('d/m/Y'),
            ])
            ->headerActions([
                Action::make('declare')->label('Déclarer un lien')->icon('heroicon-o-plus')
                    ->schema([
                        Select::make('related_employee_id')->label('Employé concerné')
                            ->options(fn () => Employee::query()->whereKeyNot($this->getOwnerRecord()->id)->orderBy('last_name')->get()->mapWithKeys(fn (Employee $e) => [$e->id => "{$e->full_name} ({$e->matricule})"]))
                            ->searchable()->live()->placeholder('Aucun (membre du CA ou autre personne)'),
                        TextInput::make('related_name')->label('Nom de la personne')
                            ->visible(fn (Get $get) => blank($get('related_employee_id')))->required(fn (Get $get) => blank($get('related_employee_id'))),
                        TextInput::make('relation')->label('Lien de parenté')->required()->datalist(['Conjoint(e)', 'Frère / sœur', 'Parent', 'Enfant', 'Cousin(e)', 'Beau-parent', 'Autre']),
                    ])
                    ->modalSubmitActionLabel('Déclarer')
                    ->action(fn (array $data) => Ui::attempt(fn () => app(EmployeeService::class)->declareRelation(Auth::user(), $this->getOwnerRecord(), $data), 'Lien déclaré.')),
            ])
            ->recordActions([DeleteAction::make()->label('Retirer')]);
    }
}
