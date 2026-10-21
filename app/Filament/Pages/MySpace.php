<?php

namespace App\Filament\Pages;

use App\Enums\LeaveStatus;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Support\LeaveForm;
use App\Filament\Support\Ui;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\AttachmentService;
use App\Services\LeaveService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Espace personnel de chaque employé ayant un compte : sa fiche, ses soldes de congé,
 * ses demandes (demander, suivre, annuler, joindre un justificatif). Aucune habilitation requise.
 */
class MySpace extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static ?int $navigationSort = -2;

    protected static ?string $navigationLabel = 'Mon espace';

    protected static ?string $title = 'Mon espace';

    protected static ?string $slug = 'mon-espace';

    protected string $view = 'filament.pages.my-space';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->employee;
    }

    public function employee(): Employee
    {
        return Auth::user()->employee->load('position', 'dutyStation', 'supervisor', 'activeContract');
    }

    public function balances(): array
    {
        $s = app(LeaveService::class);
        $e = $this->employee();

        return ['annual' => $s->balance($e, 'ANNUEL'), 'recovery' => $s->balance($e, 'RECUPERATION'), 'fmt' => fn ($d) => $s->fmt((float) $d)];
    }

    public function photo(): string
    {
        $e = $this->employee();

        return EmployeeResource::photoUrl($e) ?? EmployeeResource::initialsAvatar($e);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('request')->label('Demander un congé')->icon('heroicon-o-calendar-days')
                ->modalHeading('Demande de congé')
                ->schema(LeaveForm::schema(fn () => Auth::user()->employee))
                ->modalSubmitActionLabel('Envoyer à mon supérieur')
                ->action(fn (array $data) => Ui::attempt(fn () => app(LeaveService::class)->submit(
                    Auth::user(), Auth::user()->employee, LeaveType::query()->findOrFail($data['leave_type_id']), $data,
                ), 'Demande envoyée à votre supérieur.')),
        ];
    }

    public function table(Table $table): Table
    {
        $service = app(LeaveService::class);

        return $table
            ->heading('Mes demandes de congé')
            ->query(fn () => LeaveRequest::query()->with('type')->where('employee_id', Auth::user()->employee?->id ?? 0))
            ->defaultSort('start_date', 'desc')
            ->emptyStateHeading('Aucune demande pour l\'instant')
            ->emptyStateDescription('Cliquez sur « Demander un congé » en haut de la page.')
            ->columns([
                TextColumn::make('number')->label('N°'),
                TextColumn::make('type.label')->label('Type'),
                TextColumn::make('period')->label('Période')->state(fn (LeaveRequest $r) => $r->periodLabel()),
                TextColumn::make('days')->label('Jours')->formatStateUsing(fn ($state) => $service->fmt((float) $state)),
                TextColumn::make('stage')->label('Où en est-elle ?')->badge()
                    ->state(fn (LeaveRequest $r) => LeaveForm::stage($r))
                    ->color(fn (LeaveRequest $r) => $r->status->getColor()),
                TextColumn::make('decision_comment')->label('Commentaire')->placeholder('—')->limit(40)->toggleable(),
            ])
            ->recordActions([
                Action::make('attach')->label('Justificatif')->icon('heroicon-o-paper-clip')->color('gray')
                    ->visible(fn (LeaveRequest $r) => in_array($r->status, [LeaveStatus::SOUMISE, LeaveStatus::VALIDEE_SUPERIEUR, LeaveStatus::APPROUVEE], true))
                    ->modalDescription('Certificat médical, acte de décès, attestation… (PDF ou image, 10 Mo au plus).')
                    ->schema([
                        Select::make('category')->label('Nature')->options(['CERTIFICAT_MEDICAL' => 'Certificat médical', 'AUTRE' => 'Autre justificatif'])->default('CERTIFICAT_MEDICAL')->required(),
                        FileUpload::make('file')->label('Fichier')->required()->disk(AttachmentService::DISK)->directory('documents')->visibility('private')
                            ->acceptedFileTypes(AttachmentService::MIME_TYPES)->maxSize(AttachmentService::MAX_KB)->storeFileNamesIn('original_name'),
                    ])
                    ->modalSubmitActionLabel('Joindre')
                    ->action(fn (LeaveRequest $r, array $data) => Ui::attempt(fn () => app(AttachmentService::class)->store(
                        Auth::user(), $r, $data['file'], $data['category'], $data['original_name'] ?? null, 'Justificatif '.$r->number,
                    ), 'Justificatif joint.')),
                Action::make('cancel')->label('Annuler')->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (LeaveRequest $r) => $service->canCancel(Auth::user(), $r))
                    ->schema([Textarea::make('reason')->label('Motif')->required()->rows(2)])
                    ->modalSubmitActionLabel('Annuler la demande')
                    ->action(fn (LeaveRequest $r, array $data) => Ui::attempt(fn () => $service->cancel(Auth::user(), $r, $data['reason']), 'Demande annulée.')),
            ]);
    }
}
