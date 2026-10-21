<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Services\LeaveService;
use App\Support\PersonnelAccess;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/** Accueil : qui est absent cette semaine et la suivante (congés approuvés) */
class AbsencesThisWeek extends Widget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.absences-this-week';

    public static function canView(): bool
    {
        return PersonnelAccess::canView(Auth::user());
    }

    public function getRows(): array
    {
        $from = today()->startOfWeek();
        $to = today()->addWeek()->endOfWeek();

        return app(LeaveService::class)->absences($from->toDateString(), $to->toDateString())->map(fn ($r) => [
            'name' => $r->employee->full_name,
            'type' => $r->type->label,
            'period' => $r->periodLabel(),
            'now' => $r->start_date->lte(today()) && $r->end_date->gte(today()),
            'interim' => $r->interim?->full_name,
            'url' => LeaveRequestResource::getUrl('view', ['record' => $r]),
        ])->all();
    }
}
