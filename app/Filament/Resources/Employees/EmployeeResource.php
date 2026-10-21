<?php

namespace App\Filament\Resources\Employees;

use App\Enums\AppModule;
use App\Enums\AttachmentCategory;
use App\Enums\EmployeeStatus;
use App\Enums\EmploymentCategory;
use App\Enums\Gender;
use App\Enums\IdDocumentType;
use App\Enums\MaritalStatus;
use App\Filament\Concerns\HasModuleAccess;
use App\Filament\Support\Ui;
use App\Models\Employee;
use App\Models\User;
use App\Support\PersonnelAccess;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Dossier du personnel (Manuel §2.4.5 et §2.4.6). Les rubriques sensibles
 * ne s'affichent qu'au RH, à la Direction Exécutive et à l'intéressé (RG-26).
 */
class EmployeeResource extends Resource
{
    use HasModuleAccess;

    protected static AppModule $module = AppModule::RH;

    protected static ?string $model = Employee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Personnel';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'employé';

    protected static ?string $pluralModelLabel = 'Employés';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $recordTitleAttribute = 'matricule';

    public static function canEdit(Model $record): bool
    {
        return static::canCreate() && $record->status !== EmployeeStatus::SORTI;
    }

    public static function photoUrl(?Employee $employee): ?string
    {
        return $employee?->photo_path ? route('files.photo', $employee).'?v='.substr(md5($employee->photo_path), 0, 8) : null;
    }

    public static function initialsAvatar(?Employee $employee): string
    {
        $initials = e($employee?->initials ?? '?');
        $svg = "<svg xmlns='http://www.w3.org/2000/svg' width='120' height='120'><rect width='120' height='120' fill='#E3E7EF'/><text x='60' y='74' font-family='Arial' font-size='44' font-weight='700' fill='#1F3864' text-anchor='middle'>{$initials}</text></svg>";

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    public static function form(Schema $schema): Schema
    {
        $isEdit = fn (?Employee $record) => $record?->exists === true;

        return $schema->columns(1)->components([
            Tabs::make('dossier')->tabs([
                Tab::make('Identité')->icon('heroicon-o-user')->schema([
                    Grid::make(['default' => 1, 'md' => 4])->schema([
                        FileUpload::make('photo_path')->label('Photo')
                            ->avatar()->imageEditor()
                            ->disk('local')->directory('photos')->visibility('private')
                            ->acceptedFileTypes(['image/jpeg', 'image/png'])->maxSize(2048)
                            ->required()
                            ->helperText('JPG ou PNG, 2 Mo au plus. Recadrée au carré.')
                            ->columnSpan(1),
                        Grid::make(['default' => 1, 'md' => 3])->columnSpan(3)->schema([
                            TextInput::make('last_name')->label('Nom')->required()->maxLength(100),
                            TextInput::make('middle_name')->label('Post-nom')->maxLength(100),
                            TextInput::make('first_name')->label('Prénom')->required()->maxLength(100),
                            Select::make('gender')->label('Sexe')->options(Gender::class)->required(),
                            DatePicker::make('birth_date')->label('Date de naissance')->native(false)->displayFormat('d/m/Y')->maxDate(now()->subYears(15)),
                            TextInput::make('birth_place')->label('Lieu de naissance'),
                            TextInput::make('nationality')->label('Nationalité')->default('Congolaise')->required(),
                            Select::make('marital_status')->label('État civil')->options(MaritalStatus::class),
                        ]),
                    ]),
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        TextInput::make('father_name')->label('Nom du père'),
                        TextInput::make('mother_name')->label('Nom de la mère'),
                    ]),
                ]),
                Tab::make('Pièce et coordonnées')->icon('heroicon-o-phone')->schema([
                    Grid::make(['default' => 1, 'md' => 3])->schema([
                        Select::make('id_type')->label("Pièce d'identité")->options(IdDocumentType::class),
                        TextInput::make('id_number')->label('Numéro'),
                        DatePicker::make('id_expires_at')->label("Date d'expiration")->native(false)->displayFormat('d/m/Y'),
                        TextInput::make('phone')->label('Téléphone principal')->tel()->required(),
                        TextInput::make('phone2')->label('Téléphone secondaire')->tel(),
                        TextInput::make('personal_email')->label('Email personnel')->email(),
                        TextInput::make('work_email')->label('Email professionnel')->email(),
                        TextInput::make('address_city')->label('Ville')->default('Goma'),
                        TextInput::make('address_commune')->label('Commune'),
                        TextInput::make('address_quartier')->label('Quartier'),
                        TextInput::make('address_avenue')->label('Avenue'),
                        TextInput::make('address_number')->label('Numéro'),
                    ]),
                ]),
                Tab::make('Urgence et administratif')->icon('heroicon-o-lifebuoy')->schema([
                    Grid::make(['default' => 1, 'md' => 3])->schema([
                        TextInput::make('emergency_name')->label("Personne à contacter en cas d'urgence"),
                        TextInput::make('emergency_relation')->label('Lien'),
                        TextInput::make('emergency_phone')->label('Téléphone')->tel(),
                        Textarea::make('medical_notes')->label('Traitements réguliers et allergies (facultatif)')
                            ->helperText('Visible du seul RH, de la DE et de l\'intéressé ; chiffré dans la base.')->rows(2)->columnSpanFull(),
                        TextInput::make('cnss_number')->label('Numéro CNSS'),
                        TextInput::make('tax_number')->label("Numéro d'impôt (NIF)"),
                        TextInput::make('bank_name')->label('Banque'),
                        TextInput::make('bank_account')->label('Numéro de compte')->helperText('Chiffré dans la base.'),
                    ]),
                ]),
                Tab::make('Formation')->icon('heroicon-o-academic-cap')->schema([
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        Select::make('education_level')->label("Niveau d'études")->options([
                            'Primaire' => 'Primaire', 'Secondaire' => 'Secondaire (diplôme d\'État)', 'Graduat' => 'Graduat (bac+3)',
                            'Licence' => 'Licence (bac+5)', 'Master' => 'Master', 'Doctorat' => 'Doctorat', 'Autre' => 'Autre',
                        ]),
                        TextInput::make('education_field')->label("Domaine d'études"),
                    ]),
                    Text::make('Les diplômes se détaillent dans l\'onglet « Diplômes » du dossier, avec leurs copies certifiées dans « Documents ».'),
                ]),
                Tab::make('Situation')->icon('heroicon-o-briefcase')->schema([
                    Grid::make(['default' => 1, 'md' => 3])->schema([
                        Select::make('employment_category')->label("Catégorie d'emploi")->options(EmploymentCategory::class)->default(EmploymentCategory::TEMPS_PLEIN)->required(),
                        DatePicker::make('hired_at')->label("Date d'entrée en fonction")->native(false)->displayFormat('d/m/Y')->required()
                            ->helperText('Date de présentation au lieu d\'affectation (Manuel §1.5).'),
                        Select::make('status')->label('Statut')
                            ->options(collect(EmployeeStatus::cases())->reject(fn ($s) => $s === EmployeeStatus::SORTI)->mapWithKeys(fn ($s) => [$s->value => $s->getLabel()]))
                            ->visible($isEdit)->required()
                            ->helperText('La confirmation après l\'essai est décidée par la DE depuis le contrat ; la sortie s\'enregistre depuis le dossier.'),
                        Select::make('position_id')->label('Poste')->relationship('position', 'title')->searchable()->preload(),
                        Select::make('department_id')->label('Département')->relationship('department', 'name')->preload(),
                        Select::make('duty_station_id')->label("Lieu d'affectation")->relationship('dutyStation', 'name')->preload(),
                        Select::make('supervisor_id')->label('Supérieur hiérarchique direct')
                            ->options(fn (?Employee $record) => Employee::query()
                                ->where('status', '!=', EmployeeStatus::SORTI->value)
                                ->when($record?->exists, fn (Builder $q) => $q->whereKeyNot($record->id))
                                ->orderBy('last_name')->get()->mapWithKeys(fn (Employee $e) => [$e->id => "{$e->full_name} ({$e->matricule})"]))
                            ->searchable()
                            ->helperText('Pilote les validations et les alertes (recopié sur le compte de connexion).'),
                        Select::make('user_id')->label('Compte de connexion lié')
                            ->options(fn (?Employee $record) => User::query()
                                ->where(fn (Builder $q) => $q->whereDoesntHave('employee')->when($record?->user_id, fn (Builder $q) => $q->orWhere('id', $record->user_id)))
                                ->orderBy('name')->get()->mapWithKeys(fn (User $u) => [$u->id => "{$u->name} — {$u->email}"]))
                            ->searchable()->placeholder('Aucun compte'),
                    ]),
                ]),
            ])->persistTabInQueryString(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $sensitive = fn (Employee $record) => PersonnelAccess::canSeeSensitive(Auth::user(), $record);

        return $schema->columns(1)->components([
            Section::make()->schema([
                Grid::make(['default' => 1, 'md' => 6])->schema([
                    ImageEntry::make('photo')->hiddenLabel()
                        ->state(fn (Employee $record) => static::photoUrl($record) ?? static::initialsAvatar($record))
                        ->imageSize(120)->circular()->columnSpan(1),
                    Grid::make(['default' => 1, 'md' => 3])->columnSpan(5)->schema([
                        TextEntry::make('full_name')->label('Nom complet')->weight('bold')->size('lg')->columnSpan(2),
                        TextEntry::make('status')->label('Statut')->badge(),
                        TextEntry::make('matricule')->label('Matricule')->copyable(),
                        TextEntry::make('position.title')->label('Poste')->placeholder('—'),
                        TextEntry::make('department.name')->label('Département')->placeholder('—'),
                        TextEntry::make('dutyStation.name')->label("Lieu d'affectation")->placeholder('—'),
                        TextEntry::make('supervisor.full_name')->label('Supérieur direct')->placeholder('—'),
                        TextEntry::make('hired_at')->label("Entrée en fonction")->date('d/m/Y')
                            ->suffix(fn (Employee $record) => ' · '.$record->seniorityYears().' an(s) d\'ancienneté'),
                        TextEntry::make('employment_category')->label('Catégorie'),
                        TextEntry::make('activeContract.number')->label('Contrat actif')->placeholder('Aucun contrat actif')
                            ->url(fn (Employee $record) => $record->activeContract ? \App\Filament\Resources\Contracts\ContractResource::getUrl('view', ['record' => $record->activeContract]) : null),
                        TextEntry::make('user.email')->label('Compte de connexion')->placeholder('Aucun'),
                    ]),
                ]),
            ]),
            Section::make('Pièces exigées à l\'embauche')->description('Manuel §2.4.4 : copies certifiées des diplômes, certificat médical, photos, pièce d\'identité, contrat signé.')
                ->collapsible()->schema([
                    TextEntry::make('missing_documents')->hiddenLabel()
                        ->state(function (Employee $record) {
                            $have = $record->attachments()->pluck('category')->map(fn ($c) => $c instanceof AttachmentCategory ? $c : AttachmentCategory::from($c));
                            $have = $have->merge($record->contracts()->with('attachments')->get()->flatMap->attachments->pluck('category'));
                            $missing = collect(AttachmentCategory::requiredAtHiring())->reject(fn ($c) => $have->contains($c));

                            return $missing->isEmpty() ? 'Dossier complet.' : 'À fournir : '.$missing->map->getLabel()->implode(', ').'.';
                        })
                        ->color(fn ($state) => $state === 'Dossier complet.' ? 'success' : 'warning'),
                ]),
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                Section::make('Identité et contacts')->columns(2)->schema([
                    TextEntry::make('gender')->label('Sexe'),
                    TextEntry::make('birth_date')->label('Naissance')->date('d/m/Y')->placeholder('—')
                        ->suffix(fn (Employee $record) => $record->birth_place ? ' à '.$record->birth_place : ''),
                    TextEntry::make('nationality')->label('Nationalité'),
                    TextEntry::make('marital_status')->label('État civil')->placeholder('—'),
                    TextEntry::make('father_name')->label('Père')->placeholder('—'),
                    TextEntry::make('mother_name')->label('Mère')->placeholder('—'),
                    TextEntry::make('phone')->label('Téléphone')->placeholder('—')
                        ->suffix(fn (Employee $record) => $record->phone2 ? ' · '.$record->phone2 : ''),
                    TextEntry::make('work_email')->label('Email professionnel')->placeholder('—'),
                    TextEntry::make('personal_email')->label('Email personnel')->placeholder('—'),
                    TextEntry::make('address')->label('Adresse')->placeholder('—')
                        ->state(fn (Employee $record) => collect([$record->address_number ? 'N° '.$record->address_number : null, $record->address_avenue ? 'av. '.$record->address_avenue : null, $record->address_quartier ? 'Q. '.$record->address_quartier : null, $record->address_commune ? 'C. '.$record->address_commune : null, $record->address_city])->filter()->implode(', ') ?: null),
                    TextEntry::make('education_level')->label('Études')->placeholder('—')
                        ->suffix(fn (Employee $record) => $record->education_field ? ' — '.$record->education_field : ''),
                ]),
                Section::make('Urgence et administratif')->description('Données personnelles (RG-26)')->columns(2)->visible($sensitive)->schema([
                    TextEntry::make('emergency_name')->label("Contact d'urgence")->placeholder('—')
                        ->suffix(fn (Employee $record) => collect([$record->emergency_relation, $record->emergency_phone])->filter()->map(fn ($v) => ' · '.$v)->implode('')),
                    TextEntry::make('medical_notes')->label('Traitements et allergies')->placeholder('—'),
                    TextEntry::make('id_type')->label("Pièce d'identité")->placeholder('—')
                        ->suffix(fn (Employee $record) => $record->id_number ? ' n° '.$record->id_number : ''),
                    TextEntry::make('id_expires_at')->label('Expire le')->date('d/m/Y')->placeholder('—')
                        ->color(fn (Employee $record) => $record->id_expires_at?->isPast() ? 'danger' : null),
                    TextEntry::make('cnss_number')->label('CNSS')->placeholder('—'),
                    TextEntry::make('tax_number')->label('NIF')->placeholder('—'),
                    TextEntry::make('bank_name')->label('Banque')->placeholder('—'),
                    TextEntry::make('bank_account')->label('Compte')->placeholder('—'),
                ]),
            ]),
            Section::make('Congés')->collapsible()->columns(4)
                ->visible(fn (Employee $record) => $sensitive($record) || PersonnelAccess::canView(Auth::user()))
                ->schema([
                    TextEntry::make('leave_available')->label('Congé annuel disponible')->weight('bold')
                        ->state(function (Employee $record) {
                            $s = app(\App\Services\LeaveService::class);
                            $b = $s->balance($record);

                            return $s->fmt($b['available']).' j sur '.$s->fmt($b['entitlement']).' j de droit annuel';
                        })
                        ->helperText(function (Employee $record) {
                            $b = app(\App\Services\LeaveService::class)->balance($record);

                            return 'Année contractuelle du '.$b['from']->format('d/m/Y').' au '.$b['to']->format('d/m/Y');
                        })->columnSpan(2),
                    TextEntry::make('leave_taken')->label('Pris cette année')
                        ->state(fn (Employee $record) => app(\App\Services\LeaveService::class)->fmt(app(\App\Services\LeaveService::class)->balance($record)['taken']).' j'),
                    TextEntry::make('leave_recovery')->label('Récupération')
                        ->state(fn (Employee $record) => app(\App\Services\LeaveService::class)->fmt(app(\App\Services\LeaveService::class)->balance($record, 'RECUPERATION')['available']).' j'),
                ]),
            Section::make('Carte de service')->collapsible()->columns(4)
                ->visible(fn (Employee $record) => $record->serviceCards()->exists())
                ->schema([
                    TextEntry::make('card_number')->label('Numéro')->state(fn (Employee $record) => $record->serviceCards()->latest('id')->first()?->number),
                    TextEntry::make('card_status')->label('Statut')->badge()->state(fn (Employee $record) => $record->serviceCards()->latest('id')->first()?->status),
                    TextEntry::make('card_issued')->label('Délivrée le')->state(fn (Employee $record) => $record->serviceCards()->latest('id')->first()?->issued_at?->format('d/m/Y')),
                    TextEntry::make('card_fee')->label('Remplacement facturé')->placeholder('—')
                        ->state(fn (Employee $record) => $record->serviceCards()->whereNotNull('replacement_fee')->sum('replacement_fee') ?: null)
                        ->formatStateUsing(fn ($state) => Ui::money($state, 'USD')),
                ]),
            Section::make('Sortie')->collapsible()->columns(3)
                ->visible(fn (Employee $record) => $record->exitRecord !== null)
                ->schema([
                    TextEntry::make('exitRecord.reason')->label('Motif'),
                    TextEntry::make('exitRecord.exit_date')->label('Date de sortie')->date('d/m/Y'),
                    TextEntry::make('exitRecord.clearance_issued_at')->label("Certificat d'autorisation")->dateTime('d/m/Y')
                        ->placeholder('Non délivré — dernier salaire en attente')->color(fn ($state) => $state ? 'success' : 'warning'),
                    IconEntry::make('exitRecord.card_returned')->label('Carte restituée')->boolean(),
                    IconEntry::make('exitRecord.equipment_returned')->label('Matériel rendu')->boolean(),
                    TextEntry::make('exitRecord.debrief_notes')->label('Compte rendu de fin de contrat')->placeholder('À rédiger')->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_name')
            ->columns([
                ImageColumn::make('photo')->label('')
                    ->state(fn (Employee $record) => static::photoUrl($record) ?? static::initialsAvatar($record))
                    ->circular()->imageSize(36),
                TextColumn::make('matricule')->label('Matricule')->searchable()->sortable(),
                TextColumn::make('full_name')->label('Nom')->searchable(['last_name', 'middle_name', 'first_name'])->sortable(['last_name'])->weight('medium'),
                TextColumn::make('position.title')->label('Poste')->placeholder('—')->toggleable(),
                TextColumn::make('dutyStation.name')->label('Lieu')->placeholder('—')->toggleable(),
                TextColumn::make('supervisor.full_name')->label('Supérieur')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('hired_at')->label('Entrée')->date('d/m/Y')->sortable()->toggleable(),
                TextColumn::make('status')->label('Statut')->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Statut')->options(EmployeeStatus::class)
                    ->default(null),
                SelectFilter::make('department_id')->label('Département')->relationship('department', 'name'),
                SelectFilter::make('duty_station_id')->label("Lieu d'affectation")->relationship('dutyStation', 'name'),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ContractsRelationManager::class,
            RelationManagers\LeaveRequestsRelationManager::class,
            RelationManagers\LeaveLedgerRelationManager::class,
            RelationManagers\DocumentsRelationManager::class,
            RelationManagers\FamilyRelationManager::class,
            RelationManagers\DiplomasRelationManager::class,
            RelationManagers\DeclaredRelationsRelationManager::class,
            RelationManagers\DisciplinaryActionsRelationManager::class,
            RelationManagers\HistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmployees::route('/'),
            'create' => Pages\CreateEmployee::route('/create'),
            'view' => Pages\ViewEmployee::route('/{record}'),
            'edit' => Pages\EditEmployee::route('/{record}/edit'),
        ];
    }
}
