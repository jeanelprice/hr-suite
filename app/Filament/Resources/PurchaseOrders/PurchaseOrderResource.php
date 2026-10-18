<?php

namespace App\Filament\Resources\PurchaseOrders;

use App\Enums\AppModule;
use App\Enums\Currency;
use App\Enums\DocStatus;
use App\Enums\PurchaseOrderStatus;
use App\Filament\Concerns\HasModuleAccess;
use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Filament\Support\Ui;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
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
 * Bons de commande (module Achats) — RG-03 engagement, RG-04 paiements, RG-05 clôture.
 */
class PurchaseOrderResource extends Resource
{
    use HasModuleAccess;

    protected static AppModule $module = AppModule::ACHATS;

    protected static ?string $model = PurchaseOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Achats';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'bon de commande';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $pluralModelLabel = "Bons de commande";

    protected static ?string $recordTitleAttribute = 'number';

    /** Demandes validées sans bon de commande actif */
    public static function orderableRequests(): array
    {
        return PurchaseRequest::query()
            ->where('status', DocStatus::VALIDE->value)
            ->whereDoesntHave('orders', fn ($q) => $q->whereNotIn('status', [PurchaseOrderStatus::REJETE->value, PurchaseOrderStatus::ANNULE->value]))
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(fn (PurchaseRequest $r) => [$r->id => "{$r->number} — {$r->purpose} (".Ui::money($r->estimated_amount, $r->currency).')'])
            ->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Commande')
                ->description("Le bon de commande reprend une demande validée. S'il dépasse le montant pré-engagé, l'écart est contrôlé contre le solde de la ligne (RG-03).")
                ->columns(2)
                ->schema([
                    Select::make('purchase_request_id')
                        ->label("Demande d'achat validée")
                        ->options(fn () => self::orderableRequests())
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, Set $set) {
                            $request = $state ? PurchaseRequest::query()->with('items')->find($state) : null;
                            if (! $request) {
                                return;
                            }
                            $set('currency', $request->currency->value);
                            $set('items', $request->items->map(fn ($i) => [
                                'description' => $i->description,
                                'quantity' => rtrim(rtrim((string) $i->quantity, '0'), '.'),
                                'unit' => $i->unit,
                                'unit_price' => $i->unit_price,
                            ])->all());
                        })
                        ->columnSpanFull(),
                    Select::make('supplier_id')
                        ->label('Fournisseur')
                        ->options(fn () => Supplier::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required(),
                    Select::make('currency')->label('Devise')->options(Currency::class)->default(Currency::USD->value)->required(),
                ]),
            Section::make('Articles commandés')->schema([
                Repeater::make('items')
                    ->hiddenLabel()
                    ->addActionLabel('Ajouter un article')
                    ->minItems(1)
                    ->columns(12)
                    ->live()
                    ->schema([
                        TextInput::make('description')->label('Désignation')->required()->columnSpan(5),
                        TextInput::make('quantity')->label('Quantité')->numeric()->minValue(0.001)->required()->columnSpan(2),
                        TextInput::make('unit')->label('Unité')->columnSpan(2),
                        TextInput::make('unit_price')->label('Prix unitaire')->numeric()->minValue(0.01)->required()->columnSpan(3),
                    ]),
                Placeholder::make('total')
                    ->label('Montant du bon de commande')
                    ->content(fn (Get $get) => Ui::money(
                        (string) PurchaseRequestService::itemsTotal(PurchaseRequestResource::validItems($get('items') ?? [])),
                        $get('currency'),
                    )),
            ]),
            Section::make('Urgence : demande de dérogation')
                ->description('Uniquement si le bon de commande dépasse le pré-engagement et que le solde ne suffit pas (RG-09).')
                ->collapsed()
                ->schema([Textarea::make('derogation_request')->label("Justification de l'urgence")->minLength(20)->rows(3)]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('number')->label('Numéro')->weight('bold'),
                TextEntry::make('status')->label('Statut')->badge(),
                TextEntry::make('supplier.name')->label('Fournisseur'),
                TextEntry::make('request.number')->label("Demande d'achat")
                    ->url(fn ($record) => PurchaseRequestResource::getUrl('view', ['record' => $record->purchase_request_id])),
                TextEntry::make('line.code')->label('Ligne budgétaire')->formatStateUsing(fn ($record) => $record->line->label()),
                TextEntry::make('createdBy.name')->label('Établi par'),
                TextEntry::make('amount')->label('Montant du BC (lecture seule)')->weight('bold')
                    ->formatStateUsing(fn ($state, $record) => Ui::money($state, $record->currency)),
                TextEntry::make('paid')->label('Déjà payé')
                    ->state(fn ($record) => Ui::money(\App\Support\Money::sum($record->payments()->where('status', 'VALIDE')->pluck('amount')), $record->currency)),
                TextEntry::make('remaining')->label('Reste à payer')
                    ->state(fn ($record) => Ui::money(self::remainingToPay($record), $record->currency)),
                TextEntry::make('approvedBy.name')->label('Validé par')->placeholder('—'),
                TextEntry::make('close_reason')->label('Motif de clôture / annulation')->placeholder('—'),
                TextEntry::make('derogation_request')->label('Dérogation demandée')->placeholder('Aucune'),
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

    /** Montant du BC − paiements validés − paiements en attente */
    public static function remainingToPay(PurchaseOrder $order): string
    {
        $committed = \App\Support\Money::sum($order->payments()->whereIn('status', [DocStatus::VALIDE->value, DocStatus::SOUMIS->value, DocStatus::BROUILLON->value])->pluck('amount'));

        return (string) \App\Support\Money::of($order->amount)->minus($committed);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('number')->label('Numéro')->searchable()->sortable(),
                TextColumn::make('request.number')->label('DA'),
                TextColumn::make('supplier.name')->label('Fournisseur')->searchable(),
                TextColumn::make('line.code')->label('Ligne'),
                TextColumn::make('amount')->label('Montant')->alignEnd()->formatStateUsing(fn ($state, $record) => Ui::money($state, $record->currency)),
                TextColumn::make('status')->label('Statut')->badge(),
                TextColumn::make('created_at')->label('Créé le')->date('d/m/Y')->sortable()->toggleable(),
            ])
            ->filters([SelectFilter::make('status')->label('Statut')->options(PurchaseOrderStatus::class)])
            ->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\PaymentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseOrders::route('/'),
            'create' => Pages\CreatePurchaseOrder::route('/create'),
            'view' => Pages\ViewPurchaseOrder::route('/{record}'),
        ];
    }
}
