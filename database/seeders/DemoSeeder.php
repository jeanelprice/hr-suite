<?php

namespace Database\Seeders;

use App\Enums\AccessLevel;
use App\Enums\AppModule;
use App\Enums\Currency;
use App\Enums\Role;
use App\Enums\TreasuryType;
use App\Models\ExchangeRate;
use App\Models\StatutoryObjective;
use App\Models\Supplier;
use App\Models\TreasuryAccount;
use App\Models\User;
use App\Services\BudgetService;
use App\Services\ProjectService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Données de démonstration. Les opérations passent par les services métier :
 * elles sont donc auditées et respectent les mêmes règles que l'application.
 * Mot de passe de tous les comptes : ChangezMoi123!
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'ChangezMoi123!';

    public function run(BudgetService $budgets, ProjectService $projects): void
    {
        if (User::query()->where('role', Role::DIRECTION_EXECUTIVE->value)->exists()) {
            $this->command?->warn('La base contient déjà des données : démonstration ignorée.');

            return;
        }

        $objectives = [
            'OS1' => "Assistance humanitaire d'urgence",
            'OS2' => 'Protection et accompagnement des personnes déplacées et réfugiées',
            'OS3' => "Autonomisation économique et moyens d'existence",
            'OS4' => "Accès à l'éducation et à la santé",
            'OS5' => "Protection de l'environnement et résilience climatique",
            'OS6' => 'Promotion des droits humains',
            'OS7' => 'Logistique humanitaire',
        ];
        foreach ($objectives as $code => $label) {
            StatutoryObjective::query()->create(['code' => $code, 'label' => $label]);
        }

        $all = array_map(fn (AppModule $m) => [$m, AccessLevel::VALIDATION], AppModule::cases());
        $accounts = [
            'de' => ['Jean-El-Price Byenda Muhanya', 'direction@humaniteresilience.org', Role::DIRECTION_EXECUTIVE, $all],
            'dea' => ['DE adjointe (démo)', 'adjointe@humaniteresilience.org', Role::DIRECTION_EXECUTIVE_ADJOINTE, [
                [AppModule::PROJETS, AccessLevel::VALIDATION], [AppModule::FINANCES, AccessLevel::VALIDATION], [AppModule::ACHATS, AccessLevel::VALIDATION], [AppModule::TABLEAU_BORD, AccessLevel::LECTURE],
            ]],
            'fin' => ['Financier (démo)', 'financier@humaniteresilience.org', Role::FINANCIER, [
                [AppModule::FINANCES, AccessLevel::CREATION], [AppModule::ACHATS, AccessLevel::LECTURE], [AppModule::PROJETS, AccessLevel::LECTURE], [AppModule::TABLEAU_BORD, AccessLevel::LECTURE],
            ]],
            'cp' => ['Chef de projet (démo)', 'chef.projet@humaniteresilience.org', Role::CHEF_PROJET, [
                [AppModule::PROJETS, AccessLevel::CREATION], [AppModule::ACHATS, AccessLevel::CREATION], [AppModule::FINANCES, AccessLevel::LECTURE], [AppModule::TABLEAU_BORD, AccessLevel::LECTURE],
            ]],
            'cc' => ['Comité de Contrôle (démo)', 'controle@humaniteresilience.org', Role::COMITE_CONTROLE, [
                [AppModule::FINANCES, AccessLevel::LECTURE], [AppModule::ACHATS, AccessLevel::LECTURE], [AppModule::PROJETS, AccessLevel::LECTURE], [AppModule::TABLEAU_BORD, AccessLevel::LECTURE],
            ]],
        ];

        $users = [];
        foreach ($accounts as $key => [$name, $email, $role]) {
            $users[$key] = User::query()->create([
                'name' => $name, 'email' => $email, 'role' => $role, 'password' => self::PASSWORD, 'email_verified_at' => now(),
            ]);
        }
        foreach (['dea', 'fin', 'cp'] as $key) {
            $users[$key]->update(['supervisor_id' => $users['de']->id]); // ligne hiérarchique
        }
        foreach ($accounts as $key => [, , , $accesses]) {
            foreach ($accesses as [$module, $level]) {
                $users[$key]->moduleAccesses()->create(['module' => $module, 'level' => $level, 'granted_by_id' => $users['de']->id]);
            }
        }

        Auth::login($users['fin']);
        ExchangeRate::query()->create([
            'rate_date' => now()->startOfYear()->toDateString(), 'from_currency' => Currency::USD, 'to_currency' => Currency::CDF,
            'rate' => '2850', 'source' => 'BCC', 'entered_by_id' => $users['fin']->id,
        ]);
        Auth::login($users['de']);
        TreasuryAccount::query()->create(['name' => 'Caisse Goma USD', 'type' => TreasuryType::CAISSE, 'currency' => Currency::USD]);
        TreasuryAccount::query()->create(['name' => 'Caisse Goma CDF', 'type' => TreasuryType::CAISSE, 'currency' => Currency::CDF]);
        TreasuryAccount::query()->create(['name' => 'Banque principale USD', 'type' => TreasuryType::BANQUE, 'currency' => Currency::USD, 'bank_name' => 'Banque (démo)']);
        Supplier::query()->create(['name' => 'Quincaillerie du Lac (démo)', 'phone' => '+243 000 000 000']);

        Auth::login($users['cp']);
        $project = $projects->create($users['cp'], [
            'code' => 'HR-ABR-2027',
            'title' => 'Abris transitionnels Nyiragongo (démo)',
            'zone' => 'Nyiragongo, Nord-Kivu',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'manager_id' => $users['cp']->id,
        ], StatutoryObjective::query()->whereIn('code', ['OS1', 'OS2'])->pluck('id')->all());

        $budget = $budgets->create($users['fin'], $project, 2027, Currency::USD, [
            ['code' => '1.1', 'label' => 'Salaires terrain', 'amount' => '24000'],
            ['code' => '2.1', 'label' => 'Achats kits abris', 'amount' => '60000'],
            ['code' => '3.1', 'label' => 'Transport et logistique', 'amount' => '8000'],
        ]);
        $version = $budget->versions()->first();
        $budgets->submit($users['fin'], $version);
        $budgets->approve($users['de'], $version, 'Budget initial validé (démo)');
        Auth::logout();

        $this->command?->info('Données de démonstration créées. Mot de passe de tous les comptes : '.self::PASSWORD);
        foreach ($accounts as [$name, $email, $role]) {
            $this->command?->line(sprintf('  %-28s %s', $role->getLabel(), $email));
        }
    }
}
