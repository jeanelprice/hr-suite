<?php

namespace App\Console\Commands;

use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * Installation en une commande, sans connexion internet :
 *   php artisan hr:installer
 * Crée le fichier .env, la clé de chiffrement, la base SQLite, les tables
 * et (option) les données de démonstration.
 */
class InstallCommand extends Command
{
    protected $signature = 'hr:installer {--sans-demo : Ne pas charger les données de démonstration} {--force : Réinitialiser une base existante (efface tout)}';

    protected $description = 'Installe HR Suite sur cet ordinateur (base SQLite locale)';

    public function handle(): int
    {
        $this->info('Installation de HR Suite');

        if (! File::exists(base_path('.env'))) {
            File::copy(base_path('.env.example'), base_path('.env'));
            $this->line('  ✓ Fichier de configuration .env créé');
        }

        if (blank(env('APP_KEY')) && ! str_contains(File::get(base_path('.env')), 'APP_KEY=base64:')) {
            Artisan::call('key:generate', ['--force' => true]);
            $this->line('  ✓ Clé de chiffrement générée');
        }

        $database = database_path('database.sqlite');
        $exists = File::exists($database) && File::size($database) > 0;
        if ($exists && ! $this->option('force')) {
            $this->warn('  Une base de données existe déjà : elle est conservée.');
            $this->line('  Pour repartir de zéro (tout est effacé) : php artisan hr:installer --force');
            Artisan::call('migrate', ['--force' => true]);
            $this->line('  ✓ Tables mises à jour (vos données sont conservées)');
            Artisan::call('optimize:clear');
            $this->line('  ✓ Caches vidés');

            // Base de démonstration d'une version précédente : ajoute la démonstration du personnel
            if (\App\Models\User::query()->where('email', 'direction@humaniteresilience.org')->exists()
                && ! \App\Models\Employee::query()->exists()) {
                Artisan::call('db:seed', ['--class' => \Database\Seeders\PersonnelDemoSeeder::class, '--force' => true]);
                $this->line('  ✓ Démonstration du personnel ajoutée (compte RH : rh@humaniteresilience.org)');
            } elseif (\App\Models\User::query()->where('email', 'rh@humaniteresilience.org')->exists()
                && ! \App\Models\LeaveRequest::query()->exists()) {
                Artisan::call('db:seed', ['--class' => \Database\Seeders\LeaveDemoSeeder::class, '--force' => true]);
                $this->line('  ✓ Démonstration des congés ajoutée');
            }

            return self::SUCCESS;
        }

        File::put($database, '');
        $this->line('  ✓ Base de données SQLite créée');
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->line('  ✓ Tables créées');

        if (! $this->option('sans-demo')) {
            Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
            $this->line('  ✓ Données de démonstration chargées');
            $this->newLine();
            $this->table(['Rôle', 'Email', 'Mot de passe'], [
                ['Direction Exécutive', 'direction@humaniteresilience.org', DemoSeeder::PASSWORD],
                ['DE adjointe', 'adjointe@humaniteresilience.org', DemoSeeder::PASSWORD],
                ['Financier', 'financier@humaniteresilience.org', DemoSeeder::PASSWORD],
                ['Chef de projet', 'chef.projet@humaniteresilience.org', DemoSeeder::PASSWORD],
                ['Comité de Contrôle', 'controle@humaniteresilience.org', DemoSeeder::PASSWORD],
                ['Responsable RH', 'rh@humaniteresilience.org', DemoSeeder::PASSWORD],
            ]);
        }

        $this->newLine();
        $this->info('HR Suite est prêt. Ouvrez http://hr-suite.test dans votre navigateur.');

        return self::SUCCESS;
    }
}
