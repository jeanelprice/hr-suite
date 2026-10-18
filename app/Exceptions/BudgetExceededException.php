<?php

namespace App\Exceptions;

/** RG-07 : dépassement du solde bloqué avant exécution */
class BudgetExceededException extends BusinessRuleException
{
    /**
     * @param  array{line_id:int, line_code:string, line_label:string, project_code:string, currency:string, requested:string, available:string, operation:string}  $info
     */
    public function __construct(public readonly array $info)
    {
        parent::__construct(
            "Solde insuffisant sur la ligne {$info['line_code']} « {$info['line_label']} » "
            ."(disponible : {$info['available']} {$info['currency']}, demandé : {$info['requested']} {$info['currency']}). "
            .'Opération bloquée. Seule la Direction Exécutive peut accorder une dérogation, avec justification.',
            'BUDGET_DEPASSE',
        );
    }
}
