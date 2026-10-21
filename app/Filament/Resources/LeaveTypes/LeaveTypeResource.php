<?php

namespace App\Filament\Resources\LeaveTypes;

use App\Enums\AppModule;
use App\Filament\Concerns\HasModuleAccess;
use App\Models\LeaveType;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Types de congé et leurs règles (Manuel §5.10), modifiables sans développeur */
class LeaveTypeResource extends Resource
{
    use HasModuleAccess;

    protected static AppModule $module = AppModule::RH;

    protected static ?string $model = LeaveType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Personnel';

    protected static ?int $navigationSort = 15;

    protected static ?string $modelLabel = "type de congé";

    protected static ?string $pluralModelLabel = "Types de congé";

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $recordTitleAttribute = 'label';

    public static function canEdit(Model $record): bool
    {
        return static::canCreate();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('code')->label('Code')->required()->maxLength(30)->unique(ignoreRecord: true)->disabledOn('edit')
                ->helperText('Identifiant stable (ex. ANNUEL, MALADIE).'),
            TextInput::make('label')->label('Libellé')->required(),
            Select::make('pay_rate')->label('Salaire pendant le congé')->options(['1.0000' => 'Maintenu', '0.6667' => '2/3 maintenus', '0.0000' => 'Non payé'])->required(),
            Select::make('final_approver')->label('Approbation finale')->options(\App\Enums\LeaveApprover::class)->required(),
            Toggle::make('deducts_balance')->label('Décompté du congé annuel')->inline(false),
            Toggle::make('own_balance')->label('Solde propre (récupération)')->inline(false),
            Toggle::make('calendar_days')->label('Compté en jours calendaires')->inline(false),
            Toggle::make('requires_confirmation')->label("Interdit pendant l'essai")->inline(false),
            Toggle::make('self_service')->label("L'employé peut le demander lui-même")->inline(false),
            Toggle::make('is_active')->label('Actif')->inline(false),
            TextInput::make('max_days')->label('Durée maximale (jours)')->numeric()->minValue(0.5),
            TextInput::make('document_after_days')->label('Justificatif exigé à partir de (jours)')->numeric()->integer(),
            TextInput::make('notice_days')->label('Préavis demandé (jours)')->numeric()->integer(),
            TextInput::make('min_seniority_months')->label('Ancienneté minimale (mois)')->numeric()->integer(),
            Select::make('gender')->label('Réservé à')->options(['F' => 'Femmes', 'M' => 'Hommes'])->placeholder('Tout le personnel'),
            TextInput::make('sort')->label('Ordre')->numeric()->integer()->default(10),
            Textarea::make('rule')->label('Règle affichée au demandeur')->rows(3)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('label')->label('Type')->searchable()->weight('medium'),
                TextColumn::make('pay_rate')->label('Salaire')->formatStateUsing(fn ($state) => (float) $state >= 1 ? 'Maintenu' : ((float) $state > 0 ? '2/3' : 'Non payé')),
                IconColumn::make('deducts_balance')->label('Décompté')->boolean(),
                TextColumn::make('max_days')->label('Max (j)')->placeholder('—')->formatStateUsing(fn ($state) => rtrim(rtrim((string) $state, '0'), '.')),
                TextColumn::make('final_approver')->label('Approuvé par'),
                IconColumn::make('self_service')->label('Libre-service')->boolean(),
                IconColumn::make('is_active')->label('Actif')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageLeaveTypes::route('/')];
    }
}
