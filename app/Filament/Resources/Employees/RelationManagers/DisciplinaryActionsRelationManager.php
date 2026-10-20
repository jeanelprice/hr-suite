<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Enums\AppealStatus;
use App\Enums\DisciplinaryMeasure;
use App\Enums\OffenceLevel;
use App\Filament\Support\Ui;
use App\Models\DisciplinaryAction;
use App\Services\EmployeeService;
use App\Support\PersonnelAccess;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/** Mesures disciplinaires (Manuel §4) ; une mise en demeure cesse de compter après 12 mois */
class DisciplinaryActionsRelationManager extends RelationManager
{
    protected static string $relationship = 'disciplinaryActions';

    protected static ?string $modelLabel = 'mesure disciplinaire';

    protected static ?string $pluralModelLabel = 'mesures disciplinaires';

    protected static ?string $title = 'Discipline';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-scale';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return PersonnelAccess::canSeeSensitive(Auth::user(), $ownerRecord);
    }

    public function isReadOnly(): bool
    {
        return ! PersonnelAccess::canManage(Auth::user());
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('appeal_status')->label('Appel')->options(AppealStatus::class)->required(),
            Textarea::make('appeal_notes')->label('Décision en appel')->rows(3)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->emptyStateHeading('Aucune mesure disciplinaire')
            ->defaultSort('decided_at', 'desc')
            ->columns([
                TextColumn::make('decided_at')->label('Date')->date('d/m/Y'),
                TextColumn::make('offence_level')->label('Infraction')->badge(),
                TextColumn::make('measure')->label('Mesure'),
                TextColumn::make('facts')->label('Faits')->limit(50)->tooltip(fn (DisciplinaryAction $record) => $record->facts),
                TextColumn::make('counts_until')->label('Compte jusqu\'au')->date('d/m/Y')->placeholder('—')
                    ->color(fn (DisciplinaryAction $record) => $record->counts_until?->isPast() ? 'gray' : 'danger'),
                TextColumn::make('appeal_status')->label('Appel')->badge(),
                TextColumn::make('decidedBy.name')->label('Saisi par')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Action::make('record')->label('Enregistrer une mesure')->icon('heroicon-o-plus')
                    ->schema([
                        Select::make('offence_level')->label('Nature de l\'infraction')->options(OffenceLevel::class)->required(),
                        Select::make('measure')->label('Mesure')->options(DisciplinaryMeasure::class)->required()
                            ->helperText('Une suspension place l\'employé au statut « Suspendu ».'),
                        DatePicker::make('decided_at')->label('Date de la décision')->native(false)->default(now())->required(),
                        Textarea::make('facts')->label('Faits reprochés et procédure suivie')->rows(4)->required(),
                    ])
                    ->modalSubmitActionLabel('Enregistrer')
                    ->action(fn (array $data) => Ui::attempt(fn () => app(EmployeeService::class)->recordDisciplinary(Auth::user(), $this->getOwnerRecord(), $data), 'Mesure enregistrée. Joignez la pièce signée dans « Documents ».')),
            ])
            ->recordActions([EditAction::make()->label('Appel')]);
    }
}
