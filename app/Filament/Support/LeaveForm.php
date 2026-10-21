<?php

namespace App\Filament\Support;

use App\Enums\EmployeeStatus;
use App\Enums\LeaveStatus;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\LeaveService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * Formulaire de demande de congé, partagé par « Mon espace » (l'employé)
 * et la gestion RH (saisie pour le compte d'un employé).
 */
final class LeaveForm
{
    /** @param  callable(Get): ?Employee  $employee */
    public static function schema(callable $employee, bool $chooseEmployee = false, bool $selfService = true): array
    {
        $type = fn (Get $get) => LeaveType::query()->find($get('leave_type_id'));
        $isMaternity = fn (Get $get) => $type($get)?->code === 'MATERNITE';

        return array_values(array_filter([
            $chooseEmployee ? Select::make('employee_id')->label('Employé')->required()->searchable()->live()
                ->options(fn () => Employee::query()->where('status', '!=', EmployeeStatus::SORTI->value)->orderBy('last_name')->get()
                    ->mapWithKeys(fn (Employee $e) => [$e->id => "{$e->full_name} ({$e->matricule})"])) : null,
            Select::make('leave_type_id')->label('Type de congé')->required()->live()
                ->options(function (Get $get) use ($employee, $selfService) {
                    $e = $employee($get);

                    return LeaveType::query()->where('is_active', true)->where('code', '!=', 'ABSENCE')
                        ->when($selfService, fn ($q) => $q->where('self_service', true))
                        ->when($e, fn ($q) => $q->where(fn ($g) => $g->whereNull('gender')->orWhere('gender', $e->gender?->value)))
                        ->orderBy('sort')->pluck('label', 'id');
                }),
            Text::make(fn (Get $get) => static::hint($employee($get), $type($get)))->visible(fn (Get $get) => (bool) $type($get)),
            DatePicker::make('expected_birth_date')->label("Date prévue de l'accouchement")->native(false)->displayFormat('d/m/Y')
                ->visible($isMaternity)->required($isMaternity)
                ->helperText('Le congé commence 6 semaines avant et dure 14 semaines.'),
            Grid::make(2)->hidden($isMaternity)->schema([
                DatePicker::make('start_date')->label('Du')->native(false)->displayFormat('d/m/Y')->required(fn (Get $get) => ! $isMaternity($get))->live(),
                DatePicker::make('end_date')->label('Au (inclus)')->native(false)->displayFormat('d/m/Y')->required(fn (Get $get) => ! $isMaternity($get))->afterOrEqual('start_date')->live(),
                Toggle::make('start_half')->label("Commence l'après-midi")->live(),
                Toggle::make('end_half')->label('Se termine à midi')->live(),
            ]),
            Text::make(function (Get $get) use ($type) {
                $t = $type($get);
                if (! $t || ! $get('start_date') || ! $get('end_date')) {
                    return '';
                }
                $days = app(LeaveService::class)->countDays(Carbon::parse($get('start_date'))->toDateString(), Carbon::parse($get('end_date'))->toDateString(), (bool) $get('start_half'), (bool) $get('end_half'), $t->calendar_days);

                return new HtmlString('<strong>'.app(LeaveService::class)->fmt($days).' jour(s) '.($t->calendar_days ? 'calendaires' : 'ouvrables').'</strong> décomptés (week-ends et jours fériés exclus).');
            })->visible(fn (Get $get) => $get('start_date') && $get('end_date') && ! $isMaternity($get)),
            Select::make('interim_employee_id')->label('Intérimaire pendant l\'absence (facultatif)')->searchable()
                ->options(fn (Get $get) => Employee::query()->where('status', '!=', EmployeeStatus::SORTI->value)
                    ->when($employee($get), fn ($q, $e) => $q->whereKeyNot($e->id))
                    ->orderBy('last_name')->get()->mapWithKeys(fn (Employee $e) => [$e->id => $e->full_name])),
            Textarea::make('reason')->label('Motif ou précisions')->rows(2),
        ]));
    }

    /** Rappel de la règle du type et solde disponible, affichés au demandeur */
    public static function hint(?Employee $e, ?LeaveType $type): HtmlString|string
    {
        if (! $type) {
            return '';
        }
        $html = '<span style="opacity:.8">'.e($type->rule).'</span>';
        if ($e && ($key = $type->balanceKey())) {
            $b = app(LeaveService::class)->balance($e, $key);
            $s = app(LeaveService::class);
            $html .= '<br><strong>Solde disponible : '.$s->fmt($b['available'] - $b['pending']).' jour(s)</strong>'
                .($b['pending'] > 0 ? ' (dont '.$s->fmt($b['pending']).' déjà demandé(s) en attente déduit(s))' : '')
                .($key === 'ANNUEL' ? ' — année contractuelle du '.$b['from']->format('d/m/Y').' au '.$b['to']->format('d/m/Y').'.' : '.');
        }

        return new HtmlString($html);
    }

    /** Étape en cours, en clair */
    public static function stage(LeaveRequest $r): string
    {
        return match ($r->status) {
            LeaveStatus::SOUMISE => 'Chez le supérieur',
            LeaveStatus::VALIDEE_SUPERIEUR => $r->type->final_approver->value === 'DE' ? 'Chez la DE' : 'Chez le RH',
            default => $r->status->getLabel(),
        };
    }
}
