<?php

namespace App\Filament\Support;

use App\Exceptions\BudgetExceededException;
use App\Exceptions\BusinessRuleException;
use App\Support\Money;
use Filament\Notifications\Notification;
use Throwable;

/**
 * Petits outils communs aux écrans.
 */
final class Ui
{
    /** Montant lisible avec sa devise : « 12 345,67 USD » */
    public static function money(mixed $amount, mixed $currency = null): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }
        $code = is_object($currency) && property_exists($currency, 'value') ? $currency->value : (string) $currency;

        return trim(Money::fmt((string) $amount).' '.$code);
    }

    /**
     * Exécute une action métier et affiche le résultat :
     * notification verte si tout va bien, rouge avec le message de la règle sinon.
     */
    public static function attempt(callable $operation, string $success): bool
    {
        try {
            $operation();
            Notification::make()->success()->title($success)->send();

            return true;
        } catch (BudgetExceededException $e) {
            Notification::make()->danger()->title('Dépassement budgétaire bloqué')->body($e->getMessage().' Une alerte a été envoyée aux responsables.')->persistent()->send();
        } catch (BusinessRuleException $e) {
            Notification::make()->danger()->title('Opération refusée')->body($e->getMessage())->persistent()->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->danger()->title('Erreur inattendue')->body("L'opération n'a pas été enregistrée. Détail technique : ".$e->getMessage())->persistent()->send();
        }

        return false;
    }
}
