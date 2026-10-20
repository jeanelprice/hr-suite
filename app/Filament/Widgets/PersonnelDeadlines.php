<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Contracts\ContractResource;
use App\Models\Contract;
use App\Services\ContractService;
use App\Support\PersonnelAccess;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/** Accueil : contrats qui arrivent à terme (30 jours) et périodes d'essai qui s'achèvent (15 jours) */
class PersonnelDeadlines extends Widget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.personnel-deadlines';

    public static function canView(): bool
    {
        return PersonnelAccess::canView(Auth::user());
    }

    public function getRows(): array
    {
        $upcoming = app(ContractService::class)->upcoming();
        $rows = [];
        foreach ($upcoming['ending'] as $c) {
            $rows[] = $this->row($c, 'Fin de contrat', $c->termsAt()['end_date']);
        }
        foreach ($upcoming['probation'] as $c) {
            $rows[] = $this->row($c, "Fin de période d'essai", $c->termsAt()['probation_end_date']);
        }
        usort($rows, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return $rows;
    }

    private function row(Contract $c, string $kind, string $date): array
    {
        $days = (int) today()->diffInDays(Carbon::parse($date), false);

        return [
            'kind' => $kind,
            'name' => $c->employee->full_name,
            'number' => $c->number,
            'date' => $date,
            'label' => Carbon::parse($date)->format('d/m/Y'),
            'days' => $days,
            'url' => ContractResource::getUrl('view', ['record' => $c]),
        ];
    }
}
