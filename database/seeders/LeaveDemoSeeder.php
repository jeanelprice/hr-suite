<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\LeaveService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Démonstration des congés : un congé approuvé cette semaine, une demande en attente
 * de la DE, une maladie saisie par le RH, des jours de récupération.
 */
class LeaveDemoSeeder extends Seeder
{
    public function run(LeaveService $leaves): void
    {
        if (LeaveRequest::query()->exists()) {
            return;
        }
        $by = fn (string $email) => User::query()->where('email', $email)->first();
        $de = $by('direction@humaniteresilience.org');
        $rh = $by('rh@humaniteresilience.org');
        $fin = $by('financier@humaniteresilience.org');
        $cp = $by('chef.projet@humaniteresilience.org');
        if (! $de || ! $rh || ! $fin?->employee || ! $cp?->employee) {
            return;
        }
        $type = fn (string $code) => LeaveType::byCode($code);
        $monday = today()->startOfWeek();

        // Financier : congé annuel cette semaine, approuvé (validé par la DE, approuvé par le RH)
        Auth::login($fin);
        $r1 = $leaves->submit($fin, $fin->employee, $type('ANNUEL'), [
            'start_date' => $monday->toDateString(), 'end_date' => $monday->copy()->addDays(4)->toDateString(),
            'interim_employee_id' => $rh->employee?->id, 'reason' => 'Congé familial',
        ]);
        $leaves->supervisorDecision($de, $r1, true);
        $leaves->finalDecision($rh, $r1->fresh(), true, 'Bon congé.');

        // Chef de projet : demande en attente de validation par sa supérieure (la DE)
        Auth::login($cp);
        $start = $monday->copy()->addWeeks(4);
        $leaves->submit($cp, $cp->employee, $type('ANNUEL'), [
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays(4)->toDateString(), 'reason' => 'Repos après la clôture des distributions',
        ]);

        // Chauffeur (sans compte) : maladie d'un jour saisie par le RH, validée par la chef de projet
        $driver = Employee::query()->where('last_name', 'Bahati')->first();
        if ($driver) {
            Auth::login($rh);
            $day = $monday->copy()->subWeek()->addDay();
            $r3 = $leaves->submit($rh, $driver, $type('MALADIE'), ['start_date' => $day->toDateString(), 'end_date' => $day->toDateString(), 'reason' => 'Paludisme']);
            $leaves->supervisorDecision($cp, $r3, true);
            $leaves->finalDecision($rh, $r3->fresh(), true);
        }

        // Superviseur terrain : une journée de récupération accordée par sa supérieure
        $sup = Employee::query()->where('last_name', 'Paluku')->first();
        if ($sup) {
            $leaves->grantRecovery($cp, $sup, 1, 'Distribution le dimanche');
        }
        Auth::logout();
    }
}
