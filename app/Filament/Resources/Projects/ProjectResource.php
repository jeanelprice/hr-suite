<?php

namespace App\Filament\Resources\Projects;

use App\Enums\AppModule;
use App\Enums\ProjectStatus;
use App\Filament\Concerns\HasModuleAccess;
use App\Models\Project;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Projets rattachés aux objectifs statutaires (RG-13) */
class ProjectResource extends Resource
{
    use HasModuleAccess;

    protected static AppModule $module = AppModule::PROJETS;

    protected static ?string $model = Project::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static string|UnitEnum|null $navigationGroup = 'Projets';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'projet';

    protected static ?string $pluralModelLabel = "Projets";

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $recordTitleAttribute = 'code';

    public static function canEdit(Model $record): bool
    {
        return static::canCreate() && $record->status !== ProjectStatus::CLOTURE;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('code')->label('Code')->required()->maxLength(30)->unique(ignoreRecord: true),
                TextInput::make('title')->label('Intitulé')->required(),
                TextInput::make('zone')->label("Zone d'intervention")->required()->placeholder('Province, territoire, localité'),
                Select::make('manager_id')->label('Chef de projet')
                    ->options(fn () => User::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))->searchable(),
                DatePicker::make('start_date')->label('Début')->required()->native(false),
                DatePicker::make('end_date')->label('Fin')->required()->native(false)->afterOrEqual('start_date'),
                Select::make('objectives')->label('Objectifs statutaires')
                    ->relationship('objectives', 'label')
                    ->multiple()->preload()->required()
                    ->helperText('Au moins un objectif statutaire est obligatoire (RG-13).')
                    ->columnSpanFull(),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('code')->label('Code')->weight('bold'),
                TextEntry::make('title')->label('Intitulé')->columnSpan(2),
                TextEntry::make('status')->label('Statut')->badge(),
                TextEntry::make('zone')->label('Zone'),
                TextEntry::make('manager.name')->label('Chef de projet')->placeholder('—'),
                TextEntry::make('start_date')->label('Début')->date('d/m/Y'),
                TextEntry::make('end_date')->label('Fin')->date('d/m/Y'),
                TextEntry::make('activated_at')->label('Activé le')->date('d/m/Y')->placeholder('À la validation du premier budget'),
                TextEntry::make('objectives.label')->label('Objectifs statutaires')->listWithLineBreaks()->bulleted()->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')->label('Code')->searchable()->sortable(),
                TextColumn::make('title')->label('Intitulé')->searchable()->limit(50),
                TextColumn::make('zone')->label('Zone')->toggleable(),
                TextColumn::make('manager.name')->label('Chef de projet')->placeholder('—'),
                TextColumn::make('end_date')->label('Fin')->date('d/m/Y')->sortable(),
                TextColumn::make('status')->label('Statut')->badge(),
            ])
            ->filters([SelectFilter::make('status')->label('Statut')->options(ProjectStatus::class)])
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProjects::route('/'),
            'create' => Pages\CreateProject::route('/create'),
            'view' => Pages\ViewProject::route('/{record}'),
            'edit' => Pages\EditProject::route('/{record}/edit'),
        ];
    }
}
