<?php

namespace Database\Seeders;

use App\Enums\AccessLevel;
use App\Enums\AppModule;
use App\Enums\ContractType;
use App\Enums\EmploymentCategory;
use App\Enums\Gender;
use App\Enums\Role;
use App\Models\BudgetLine;
use App\Models\Department;
use App\Models\DutyStation;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Project;
use App\Models\SalaryGrade;
use App\Models\User;
use App\Services\ContractService;
use App\Services\EmployeeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Démonstration du module Personnel : référentiels, compte Responsable RH,
 * dossiers liés aux comptes de démonstration et contrats. Les montants sont fictifs.
 * Lancé par DemoSeeder, ou seul sur une base existante :
 *   php artisan db:seed --class=PersonnelDemoSeeder
 */
class PersonnelDemoSeeder extends Seeder
{
    public function run(EmployeeService $employees, ContractService $contracts): void
    {
        if (Employee::query()->exists()) {
            $this->command?->warn('Des dossiers du personnel existent déjà : démonstration du personnel ignorée.');

            return;
        }
        $de = User::query()->where('email', 'direction@humaniteresilience.org')->first();
        if (! $de) {
            $this->command?->warn('Comptes de démonstration absents : lancez d\'abord DemoSeeder.');

            return;
        }

        $rh = User::query()->firstOrCreate(['email' => 'rh@humaniteresilience.org'], [
            'name' => 'Responsable RH (démo)', 'role' => Role::RESPONSABLE_RH, 'password' => DemoSeeder::PASSWORD,
            'email_verified_at' => now(), 'supervisor_id' => $de->id,
        ]);
        foreach ([
            [AppModule::RH, AccessLevel::CREATION], [AppModule::RECRUTEMENT, AccessLevel::CREATION],
            [AppModule::EVALUATIONS, AccessLevel::CREATION], [AppModule::PAIE, AccessLevel::CREATION],
            [AppModule::PROJETS, AccessLevel::LECTURE], [AppModule::TABLEAU_BORD, AccessLevel::LECTURE],
        ] as [$module, $level]) {
            $rh->moduleAccesses()->firstOrCreate(['module' => $module], ['level' => $level, 'granted_by_id' => $de->id]);
        }
        // Le chef de projet consulte le personnel de son équipe
        User::query()->where('email', 'chef.projet@humaniteresilience.org')->first()?->moduleAccesses()
            ->firstOrCreate(['module' => AppModule::RH], ['level' => AccessLevel::LECTURE, 'granted_by_id' => $de->id]);

        Auth::login($rh);

        $dep = [];
        foreach (['Direction', 'Programmes', 'Finances et administration', 'Logistique'] as $name) {
            $dep[$name] = Department::query()->create(['name' => $name]);
        }
        $station = [];
        foreach (['Goma', 'Nyiragongo', 'Kirotshe'] as $name) {
            $station[$name] = DutyStation::query()->create(['name' => $name, 'province' => 'Nord-Kivu']);
        }
        foreach (['A1' => 2500, 'B1' => 1500, 'B2' => 1200, 'C1' => 800, 'C2' => 600, 'D1' => 350] as $cat => $amount) {
            foreach ([1, 2, 3] as $step) {
                SalaryGrade::query()->create([
                    'category' => $cat, 'step' => $step, 'amount' => round($amount * (1 + 0.04 * ($step - 1)), 2),
                    'currency' => 'USD', 'effective_from' => '2026-01-01',
                ]);
            }
        }
        $pos = [];
        foreach ([
            ['Directeur Exécutif', 'Direction', 'A1', true],
            ['Responsable des ressources humaines', 'Finances et administration', 'B1', true],
            ['Financier', 'Finances et administration', 'B1', true],
            ['Chef de projet Abris', 'Programmes', 'B1', true],
            ['Superviseur terrain abris', 'Programmes', 'C1', false],
            ['Chauffeur', 'Logistique', 'D1', false],
        ] as [$title, $d, $cat, $key]) {
            $pos[$title] = Position::query()->create(['title' => $title, 'department_id' => $dep[$d]->id, 'salary_category' => $cat, 'is_key' => $key]);
        }

        $make = function (array $data) use ($employees, $rh) {
            return $employees->create($rh, $data + ['nationality' => 'Congolaise', 'employment_category' => EmploymentCategory::TEMPS_PLEIN, 'address_city' => 'Goma']);
        };
        $deEmp = $make(['last_name' => 'Byenda', 'middle_name' => 'Muhanya', 'first_name' => 'Jean-El-Price', 'gender' => Gender::M, 'phone' => '+243 000 000 001',
            'hired_at' => '2026-01-05', 'position_id' => $pos['Directeur Exécutif']->id, 'department_id' => $dep['Direction']->id, 'duty_station_id' => $station['Goma']->id,
            'user_id' => $de->id, 'status' => 'CONFIRME', 'work_email' => $de->email]);
        $rhEmp = $make(['last_name' => 'Kahindo', 'first_name' => 'Esther', 'gender' => Gender::F, 'phone' => '+243 000 000 002',
            'hired_at' => '2026-02-02', 'position_id' => $pos['Responsable des ressources humaines']->id, 'department_id' => $dep['Finances et administration']->id,
            'duty_station_id' => $station['Goma']->id, 'supervisor_id' => $deEmp->id, 'user_id' => $rh->id, 'status' => 'CONFIRME', 'work_email' => $rh->email]);
        $finUser = User::query()->where('email', 'financier@humaniteresilience.org')->first();
        $make(['last_name' => 'Mumbere', 'first_name' => 'Patrick', 'gender' => Gender::M, 'phone' => '+243 000 000 003',
            'hired_at' => '2026-02-02', 'position_id' => $pos['Financier']->id, 'department_id' => $dep['Finances et administration']->id,
            'duty_station_id' => $station['Goma']->id, 'supervisor_id' => $deEmp->id, 'user_id' => $finUser?->id, 'status' => 'CONFIRME', 'work_email' => $finUser?->email]);
        $cpUser = User::query()->where('email', 'chef.projet@humaniteresilience.org')->first();
        $cp = $make(['last_name' => 'Kavira', 'first_name' => 'Grâce', 'gender' => Gender::F, 'phone' => '+243 000 000 004',
            'hired_at' => '2026-03-01', 'position_id' => $pos['Chef de projet Abris']->id, 'department_id' => $dep['Programmes']->id,
            'duty_station_id' => $station['Goma']->id, 'supervisor_id' => $deEmp->id, 'user_id' => $cpUser?->id, 'status' => 'CONFIRME', 'work_email' => $cpUser?->email]);
        $sup = $make(['last_name' => 'Paluku', 'middle_name' => 'Sikuli', 'first_name' => 'David', 'gender' => Gender::M, 'phone' => '+243 000 000 005',
            'hired_at' => now()->subMonths(3)->addDays(10)->toDateString(), 'position_id' => $pos['Superviseur terrain abris']->id, 'department_id' => $dep['Programmes']->id,
            'duty_station_id' => $station['Nyiragongo']->id, 'supervisor_id' => $cp->id]);
        $driver = $make(['last_name' => 'Bahati', 'first_name' => 'Jonas', 'gender' => Gender::M, 'phone' => '+243 000 000 006',
            'hired_at' => '2026-04-01', 'position_id' => $pos['Chauffeur']->id, 'department_id' => $dep['Logistique']->id,
            'duty_station_id' => $station['Goma']->id, 'supervisor_id' => $cp->id, 'status' => 'CONFIRME']);
        $sup->family()->create(['relation' => 'CONJOINT', 'full_name' => 'Masika Furaha', 'is_dependant' => true]);
        $sup->family()->create(['relation' => 'ENFANT', 'full_name' => 'Paluku Merveille', 'birth_date' => '2019-05-14', 'is_dependant' => true]);

        $line = BudgetLine::query()->where('code', '1.1')->first();
        $project = Project::query()->where('code', 'HR-ABR-2027')->first();
        if ($line) {
            // Superviseur : CDD en cours, période d'essai qui se termine bientôt
            $c1 = $contracts->create($rh, $sup, [
                'type' => ContractType::CDD, 'start_date' => $sup->hired_at->toDateString(), 'end_date' => '2026-12-31',
                'probation_end_date' => $sup->hired_at->copy()->addMonths(3)->subDay()->toDateString(),
                'position_id' => $sup->position_id, 'duty_station_id' => $sup->duty_station_id,
                'salary_category' => 'C1', 'salary_step' => 1, 'base_salary' => '800', 'currency' => 'USD', 'weekly_hours' => 40,
            ], [['budget_line_id' => $line->id, 'percentage' => 100]]);
            $contracts->activate($rh, $c1, $sup->hired_at->toDateString());

            // Chauffeur : CDD qui arrive à terme dans moins de 30 jours
            $c2 = $contracts->create($rh, $driver, [
                'type' => ContractType::CDD, 'start_date' => '2026-04-01', 'end_date' => now()->addDays(20)->toDateString(),
                'position_id' => $driver->position_id, 'duty_station_id' => $driver->duty_station_id,
                'salary_category' => 'D1', 'salary_step' => 2, 'base_salary' => '364', 'currency' => 'USD', 'weekly_hours' => 40,
            ], [['budget_line_id' => $line->id, 'percentage' => 100]]);
            $contracts->activate($rh, $c2, '2026-03-28');

            // Chef de projet : contrat 2027 en brouillon, financé par le projet abris
            if ($project) {
                $contracts->create($rh, $cp, [
                    'type' => ContractType::CDD, 'start_date' => '2027-01-01', 'end_date' => '2027-12-31',
                    'position_id' => $cp->position_id, 'duty_station_id' => $cp->duty_station_id, 'project_id' => $project->id,
                    'salary_category' => 'B1', 'salary_step' => 2, 'base_salary' => '1560', 'currency' => 'USD', 'weekly_hours' => 40,
                ], [['budget_line_id' => $line->id, 'percentage' => 100]]);
            }
        }
        Auth::logout();
        $this->call(LeaveDemoSeeder::class);

        $this->command?->info('Démonstration du personnel créée. Compte Responsable RH : rh@humaniteresilience.org / '.DemoSeeder::PASSWORD);
    }
}
