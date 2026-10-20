<?php

namespace App\Console\Commands;

use App\Enums\AmendmentKind;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Deadline;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\HrNotification;
use App\Services\ContractService;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Fondation D appliquée au personnel (RG-19) : alerte au RH, à la DE et au
 * supérieur à l'approche de la fin d'un contrat ou d'une période d'essai,
 * et report dans le dossier des avenants (poste, lieu) arrivés à leur date d'effet.
 * Lancée chaque jour par le planificateur ; peut aussi être lancée à la main.
 */
class PersonnelDeadlinesCommand extends Command
{
    protected $signature = 'hr:echeances';

    protected $description = 'Alertes de fin de contrat et de fin d\'essai, avenants arrivés à échéance';

    public function handle(NotificationService $notifications, ContractService $contracts): int
    {
        $due = Deadline::query()->whereIn('kind', ['CONTRAT_FIN', 'FIN_ESSAI'])
            ->where('status', 'OUVERTE')->whereNull('last_reminded_at')->where('due_at', '<=', now())->get();

        foreach ($due as $deadline) {
            $contract = Contract::query()->with('employee.supervisor.user')->find($deadline->subject_id);
            if (! $contract) {
                continue;
            }
            $terms = $contract->termsAt();
            $employee = $contract->employee;
            [$subject, $date] = $deadline->kind === 'CONTRAT_FIN'
                ? ["Contrat de {$employee->full_name} : fin le ".date('d/m/Y', strtotime($terms['end_date'])), $terms['end_date']]
                : ["Période d'essai de {$employee->full_name} : fin le ".date('d/m/Y', strtotime($terms['probation_end_date'])), $terms['probation_end_date']];
            $lines = $deadline->kind === 'CONTRAT_FIN'
                ? ["Le contrat {$contract->number} arrive à son terme.", 'Préparez le renouvellement (nouveau contrat si le projet continue) ou la sortie.']
                : ["L'évaluation de fin d'essai doit être faite par le supérieur et un délégué du RH.", 'La Direction Exécutive décide ensuite : confirmation, prolongation ou fin du contrat.'];
            $recipients = $notifications->hrManagers()->push($employee->supervisor?->user)->filter();
            $notifications->send($recipients, new HrNotification($subject, $lines, 'warning'));
            $deadline->update(['last_reminded_at' => now()]);
            $this->line($subject);
        }

        // Avenants de poste ou de lieu dont la date d'effet est arrivée
        $system = User::query()->where('role', 'DIRECTION_EXECUTIVE')->first();
        ContractAmendment::query()->whereIn('kind', [AmendmentKind::POSTE->value, AmendmentKind::LIEU->value])
            ->where('effective_date', '<=', today())->where('effective_date', '>=', today()->subDays(7))
            ->with('contract.employee')->get()
            ->each(function (ContractAmendment $a) use ($contracts, $system) {
                $field = $a->kind === AmendmentKind::POSTE ? 'position_id' : 'duty_station_id';
                if ($system && $a->contract->employee->{$field} !== $a->new_value[$field]) {
                    $contracts->applyToEmployee($system, $a);
                }
            });

        $this->info('Échéances du personnel traitées : '.$due->count().' alerte(s).');

        return self::SUCCESS;
    }
}
