<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Enums\AttachmentCategory;
use App\Filament\Support\Ui;
use App\Models\Attachment;
use App\Services\AttachmentService;
use App\Support\PersonnelAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Fondation B — Documents du dossier (ou du contrat) : dépôt contrôlé, versions
 * successives conservées, ouverture tracée. Données sensibles (RG-26).
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'attachments';

    protected static ?string $modelLabel = 'document';

    protected static ?string $pluralModelLabel = 'documents';

    protected static ?string $title = 'Documents';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-paper-clip';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $employee = app(AttachmentService::class)->employeeOf($ownerRecord);

        return PersonnelAccess::canSeeSensitive(Auth::user(), $employee);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    private function canManage(): bool
    {
        return app(AttachmentService::class)->canManage(Auth::user(), $this->getOwnerRecord());
    }

    private function uploadSchema(bool $withCategory = true): array
    {
        return array_values(array_filter([
            $withCategory ? Select::make('category')->label('Catégorie')->options(AttachmentCategory::class)->required() : null,
            $withCategory ? TextInput::make('title')->label('Intitulé (facultatif)')->placeholder('ex. Diplôme de licence, UNIGOM 2018') : null,
            FileUpload::make('file')->label('Fichier')->required()
                ->disk(AttachmentService::DISK)->directory('documents')->visibility('private')
                ->acceptedFileTypes(AttachmentService::MIME_TYPES)->maxSize(AttachmentService::MAX_KB)
                ->storeFileNamesIn('original_name')
                ->helperText('PDF, image, Word ou Excel ; 10 Mo au plus.'),
        ]));
    }

    public function table(Table $table): Table
    {
        return $table
            ->emptyStateHeading('Aucun document joint')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('category')->label('Catégorie')->badge()->color('gray'),
                TextColumn::make('title')->label('Intitulé')->placeholder('—')->limit(40),
                TextColumn::make('original_name')->label('Fichier')->limit(35)->color('primary')
                    ->url(fn (Attachment $record) => route('files.attachment', $record), shouldOpenInNewTab: true),
                TextColumn::make('version')->label('Version')->prefix('v'),
                IconColumn::make('current')->label('Actuelle')->boolean()->state(fn (Attachment $record) => $record->isCurrent()),
                TextColumn::make('size')->label('Taille')->formatStateUsing(fn ($state, Attachment $record) => $record->humanSize()),
                TextColumn::make('uploadedBy.name')->label('Déposé par'),
                TextColumn::make('created_at')->label('Le')->dateTime('d/m/Y H:i'),
            ])
            ->headerActions([
                Action::make('upload')->label('Joindre un document')->icon('heroicon-o-arrow-up-tray')
                    ->visible(fn () => $this->canManage())
                    ->schema($this->uploadSchema())
                    ->modalSubmitActionLabel('Joindre')
                    ->action(fn (array $data) => Ui::attempt(fn () => app(AttachmentService::class)->store(
                        Auth::user(), $this->getOwnerRecord(), $data['file'], $data['category'], $data['original_name'] ?? null, $data['title'] ?? null,
                    ), 'Document joint au dossier.')),
            ])
            ->recordActions([
                Action::make('open')->label('Ouvrir')->icon('heroicon-o-eye')->color('gray')
                    ->url(fn (Attachment $record) => route('files.attachment', $record), shouldOpenInNewTab: true),
                Action::make('newVersion')->label('Nouvelle version')->icon('heroicon-o-arrow-path')
                    ->visible(fn (Attachment $record) => $this->canManage() && $record->isCurrent())
                    ->modalDescription('L\'ancienne version reste conservée et consultable.')
                    ->schema($this->uploadSchema(false))
                    ->modalSubmitActionLabel('Déposer la nouvelle version')
                    ->action(fn (Attachment $record, array $data) => Ui::attempt(fn () => app(AttachmentService::class)->store(
                        Auth::user(), $this->getOwnerRecord(), $data['file'], $record->category, $data['original_name'] ?? null, $record->title, $record,
                    ), 'Nouvelle version déposée.')),
            ]);
    }
}
