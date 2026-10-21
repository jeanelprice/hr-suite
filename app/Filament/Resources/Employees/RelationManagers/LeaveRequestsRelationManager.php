<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Support\LeaveForm;
use App\Models\LeaveRequest;
use App\Services\LeaveService;
use App\Support\PersonnelAccess;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class LeaveRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'leaveRequests';

    protected static ?string $modelLabel = 'congé';

    protected static ?string $pluralModelLabel = 'congés';

    protected static ?string $title = 'Congés';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-calendar-days';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return PersonnelAccess::canView(Auth::user());
    }

    public function table(Table $table): Table
    {
        $s = app(LeaveService::class);

        return $table
            ->emptyStateHeading('Aucun congé enregistré')
            ->defaultSort('start_date', 'desc')
            ->columns([
                TextColumn::make('number')->label('N°'),
                TextColumn::make('type.label')->label('Type'),
                TextColumn::make('period')->label('Période')->state(fn (LeaveRequest $r) => $r->periodLabel()),
                TextColumn::make('days')->label('Jours')->formatStateUsing(fn ($state) => $s->fmt((float) $state)),
                TextColumn::make('stage')->label('Étape')->badge()->state(fn (LeaveRequest $r) => LeaveForm::stage($r))->color(fn (LeaveRequest $r) => $r->status->getColor()),
            ])
            ->recordUrl(fn (LeaveRequest $r) => LeaveRequestResource::getUrl('view', ['record' => $r]));
    }
}
