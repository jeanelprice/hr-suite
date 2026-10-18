<?php

namespace App\Providers;

use App\Models\BudgetVersion;
use App\Models\Delegation;
use App\Models\Derogation;
use App\Models\ModuleAccess;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Noms courts et stables pour les liens polymorphes (validations, échéances, dérogations)
        Relation::morphMap([
            'purchase_request' => PurchaseRequest::class,
            'purchase_order' => PurchaseOrder::class,
            'payment' => Payment::class,
            'budget_version' => BudgetVersion::class,
            'derogation' => Derogation::class,
            'module_access' => ModuleAccess::class,
            'delegation' => Delegation::class,
        ]);
    }
}
