<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Numérotation séquentielle par année : DA-2026-0001, BC-2026-0001, PAI-2026-0001, CT-2026-0001.
 * À appeler dans une transaction ; la contrainte d'unicité protège en dernier recours.
 */
class NumberingService
{
    private const TABLES = ['DA' => 'purchase_requests', 'BC' => 'purchase_orders', 'PAI' => 'payments', 'CT' => 'contracts'];

    public function next(string $prefix): string
    {
        $base = $prefix.'-'.now()->year.'-';
        $count = DB::table(self::TABLES[$prefix])->where('number', 'like', $base.'%')->lockForUpdate()->count();

        return $base.str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
    }
}
