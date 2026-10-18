<?php

namespace App\Filament\Resources\PurchaseRequests;

use App\Enums\AppModule;
use App\Enums\Currency;
use App\Enums\DocStatus;
use App\Filament\Concerns\HasModuleAccess;
use App\Filament\Support\BudgetLineOptions;
use App\Filament\Support\Ui;
use App\Models\PurchaseRequest;
use App\Services\PurchaseRequestService;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Demandes d'achat (module Achats) — circuit RG-02 / RG-07 / RG-09.
 */
class PurchaseRequestResource extends Resource
{
    use HasModuleAccess;

    protected static AppModule $module = AppModule::ACHATS;

    protected static ?string $model = PurchaseRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Achats';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = "demande d'achat";

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $pluralModelLabel = "Demandes d'achat";

    protected static ?string $recordTitleAttribute = 'number';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Rattachement budgétaire')
                ->description('Le solde disponible de chaque ligne est affiché : une demande qui le dépasse est bloquée (RG-07).')
                ->columns(2)
                ->schema([
                    Select::make('project_id')
                        ->label('Projet')
                        ->options(fn () => BudgetLineOptions::activeProjects())
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('budget_line_id', null)),
                    Select::make('budget_line_id')
                        ->label('Ligne budgétaire')
                        ->options(fn (Get $get) => BudgetLineOptions::forProject($get('project_id')))
                        ->required()
                        ->disabled(fn (Get $get) => ! $get('project_id'))
                        ->helperText(fn (Get $get) => $get('project_id') ? null : 'Choisissez d\'abord le projet.'),
                    TextInput::make('purpose')->label('Objet de la demande')->required()->minLength(5)->columnSpanFull(),
                    Select::make('currency')->label('Devise')->options(Currency::class)->default(Currency::USD->value)->required(),
                ]),
            Section::make('Articles')
                ->schema([
                    Repeater::make('items')
                        ->hiddenLabel()
                        ->addActionLabel('Ajouter un article')
                        ->minItems(1)
                        ->defaultItems(1)
                        ->columns(12)
                        ->live()
                        ->schema([
                            TextInput::make('description')->label('Désignation')->required()->columnSpan(5),
                            TextInput::make('quantity')->label('Quantité')->numeric()->minValue(0.001)->required()->columnSpan(2),
                            TextInput::make('unit')->label('Unité')->columnSpan(2),
                            TextInput::make('unit_price')->label('Prix unitaire')->numeric()->minValue(0.01)->required()->columnSpan(3),
                        ]),
                    Placeholder::make('total')
                        ->label('Montant estimé')
                        ->content(fn (Get $get) => Ui::money(
                            (string) PurchaseRequestService::itemsTotal(self::validItems($get('items') ?? [])),
                            $get('currency'),
                        )),
                ]),
            Section::make('Urgence : demande de dérogation')
                ->description("À remplir uniquement si le montant dépasse le solde disponible. La Direction Exécutive décidera d'accorder ou non la dérogation (RG-09).")
                ->collapsed()
                ->schema([
                    Textarea::make('derogation_request')->label('Justification de l\'urgence')->minLength(20)->rows(3),
                ]),
        ]);
    }

    /** Articles complets uniquement (pendant la saisie, certains champs sont encore vides) */
    public static function validItems(array $items): array
    {
        return array_values(array_filter($items, fn ($i) => is_numeric($i['quantity'] ?? null) && is_numeric($i['unit_price'] ?? null)));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('number')->label('Numéro')->weight('bold'),
                TextEntry::make('status')->label('Statut')->badge(),
                TextEntry::make('requester.name')->label('Demandeur'),
                TextEntry::make('project.code')->label('Projet'),
                TextEntry::make('line.code')->label('Ligne budgétaire')->formatStateUsing(fn ($record) => $record->line->label()),
                TextEntry::make('purpose')->label('Objet'),
                TextEntry::make('estimated_amount')->label('Montant estimé')->formatStateUsing(fn ($state, $record) => Ui::money($state, $record->currency)),
                TextEntry::make('amount_budget_ccy')->label('Montant dans la devise du budget')
                    ->formatStateUsing(fn ($state, $record) => Ui::money($state, $record->line->budget->currency))
                    ->helperText(fn ($record) => $record->currency !== $record->line->budget->currency ? "Taux figé à la saisie : {$record->exchange_rate}" : null),
                TextEntry::make('approvedBy.name')->label('Validée par')->placeholder('—'),
                TextEntry::make('derogation_request')->label('Dérogation demandée')->placeholder('Aucune')->columnSpanFull(),
            ]),
            Section::make('Articles')->schema([
                RepeatableEntry::make('items')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('description')->label('Désignation'),
                    TextEntry::make('quantity')->label('Quantité')->formatStateUsing(fn ($state) => rtrim(rtrim((string) $state, '0'), '.')),
                    TextEntry::make('unit')->label('Unité')->placeholder('—'),
                    TextEntry::make('unit_price')->label('Prix unitaire')->formatStateUsing(fn ($state) => Ui::money($state)),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label('Numéro')->searchable()->sortable(),
                TextColumn::make('project.code')->label('Projet')->sortable(),
                TextColumn::make('line.code')->label('Ligne'),
                TextColumn::make('purpose')->label('Objet')->limit(40)->searchable(),
                TextColumn::make('estimated_amount')->label('Montant')->alignEnd()
                    ->formatStateUsing(fn ($state, $record) => Ui::money($state, $record->currency)),
                TextColumn::make('requester.name')->label('Demandeur')->toggleable(),
                TextColumn::make('status')->label('Statut')->badge(),
                TextColumn::make('created_at')->label('Créée le')->date('d/m/Y')->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Statut')->options(DocStatus::class),
                SelectFilter::make('project_id')->label('Projet')->relationship('project', 'code'),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseRequests::route('/'),
            'create' => Pages\CreatePurchaseRequest::route('/create'),
            'view' => Pages\ViewPurchaseRequest::route('/{record}'),
        ];
    }
}
