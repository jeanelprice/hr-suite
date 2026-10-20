<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Employee;
use App\Support\PersonnelAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Documents imprimables (impression ou « Enregistrer en PDF » depuis le navigateur) :
 * carte de service (Manuel §3.13) et contrat de travail en trois exemplaires (§2.4.4).
 */
class PrintController
{
    public function card(Request $request, Employee $employee): View
    {
        abort_unless(PersonnelAccess::canManage($request->user()), 403);
        $card = $employee->serviceCards()->where('status', 'ACTIVE')->latest('id')->first();
        abort_unless($card, 404, "Aucune carte de service active : délivrez-en une depuis le dossier de l'employé.");

        return view('print.service-card', ['employee' => $employee->load('position', 'dutyStation'), 'card' => $card]);
    }

    public function contract(Request $request, Contract $contract): View
    {
        abort_unless(PersonnelAccess::canSeeSensitive($request->user(), $contract->employee), 403);

        return view('print.contract', [
            'contract' => $contract->load('employee', 'position.department', 'dutyStation', 'project', 'allocations.budgetLine.budget.project', 'amendments'),
        ]);
    }
}
