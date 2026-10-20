<?php

namespace App\Filament\Pages;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Support\PersonnelAccess;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/** Organigramme généré à partir des supérieurs hiérarchiques, avec la photo de chacun */
class OrgChart extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShare;

    protected static string|UnitEnum|null $navigationGroup = 'Personnel';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Organigramme';

    protected static ?string $title = 'Organigramme';

    protected static ?string $slug = 'organigramme';

    protected string $view = 'filament.pages.org-chart';

    public static function canAccess(): bool
    {
        return PersonnelAccess::canView(Auth::user());
    }

    /** @return array{roots: Collection<int, Employee>, children: Collection<int, Collection<int, Employee>>} */
    public function getTree(): array
    {
        $all = Employee::query()->with('position', 'dutyStation')
            ->where('status', '!=', EmployeeStatus::SORTI->value)
            ->orderBy('last_name')->get();
        $ids = $all->pluck('id');
        $children = $all->groupBy(fn (Employee $e) => $e->supervisor_id && $ids->contains($e->supervisor_id) ? $e->supervisor_id : 0);

        return ['roots' => $children->get(0, collect()), 'children' => $children];
    }
}
