<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Règle de gestion non respectée : le message, en français, est affiché
 * tel quel à l'utilisateur ; le code stable permet de reconnaître le cas.
 */
class BusinessRuleException extends RuntimeException
{
    public function __construct(string $message, public readonly string $rule = 'REGLE_DE_GESTION')
    {
        parent::__construct($message);
    }
}
