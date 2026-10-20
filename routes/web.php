<?php

use App\Http\Controllers\FileController;
use App\Http\Controllers\PrintController;
use Illuminate\Support\Facades\Route;

// Les écrans de HR Suite sont servis par le panneau Filament (app/Providers/Filament).
// Ci-dessous : fichiers privés et documents imprimables, réservés aux utilisateurs connectés.
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/fichiers/{attachment}', [FileController::class, 'attachment'])->name('files.attachment');
    Route::get('/fichiers/photo/{employee}', [FileController::class, 'photo'])->name('files.photo');
    Route::get('/impression/carte/{employee}', [PrintController::class, 'card'])->name('print.card');
    Route::get('/impression/contrat/{contract}', [PrintController::class, 'contract'])->name('print.contract');
});
