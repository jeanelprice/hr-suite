<?php

namespace App\Console\Commands;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Services\LeaveService;
use Illuminate\Console\Command;

/**
 * Inscrit chaque jour au registre les mois de service accomplis (1,5 jour), les jours
 * d'ancienneté et l'expiration des années contractuelles terminées (RG-23).
 * Le calcul est aussi fait à la demande, à chaque consultation d'un solde.
 */
class LeaveAccrualCommand extends Command
{
    protected $signature = 'hr:conges';

    protected $description = 'Acquisition mensuelle des congés, ancienneté et expiration des soldes';

    public function handle(LeaveService $leaves): int
    {
        $n = 0;
        Employee::query()->where('status', '!=', EmployeeStatus::SORTI->value)->each(function (Employee $e) use ($leaves, &$n) {
            $leaves->accrue($e);
            $n++;
        });
        $this->info("Soldes de congé à jour pour {$n} employé(s).");

        return self::SUCCESS;
    }
}
