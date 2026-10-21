<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Alertes du personnel (fin de contrat, fin d'essai) : chaque matin à 7 h
\Illuminate\Support\Facades\Schedule::command('hr:echeances')->dailyAt('07:00');

// Acquisition des congés (1,5 jour par mois de service) et expiration des soldes : chaque matin à 6 h 30
\Illuminate\Support\Facades\Schedule::command('hr:conges')->dailyAt('06:30');
