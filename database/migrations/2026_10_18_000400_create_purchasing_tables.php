<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Achats : fournisseurs, demandes d'achat, bons de commande et paiements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('tax_id')->nullable(); // RCCM / Id. Nat.
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Demande d'achat — validation → pré-engagement (RG-02)
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique(); // DA-2026-0001
            $table->foreignId('project_id')->constrained();
            $table->foreignId('budget_line_id')->constrained();
            $table->foreignId('requester_id')->constrained('users');
            $table->string('purpose');
            $table->string('currency', 3);
            $table->decimal('estimated_amount', 18, 2);
            $table->decimal('exchange_rate', 20, 10)->default(1); // figé à la saisie
            $table->decimal('amount_budget_ccy', 18, 2);
            $table->text('derogation_request')->nullable();
            $table->string('status')->default('BROUILLON');
            $table->foreignId('approved_by_id')->nullable()->constrained('users');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 18, 3);
            $table->string('unit')->nullable();
            $table->decimal('unit_price', 18, 2);
        });

        // Bon de commande — validation → engagement (RG-03), clôture → reliquat libéré (RG-05)
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique(); // BC-2026-0001
            $table->foreignId('purchase_request_id')->constrained();
            $table->foreignId('budget_line_id')->constrained();
            $table->foreignId('supplier_id')->constrained();
            $table->string('currency', 3);
            $table->decimal('amount', 18, 2);
            $table->decimal('exchange_rate', 20, 10)->default(1);
            $table->decimal('amount_budget_ccy', 18, 2);
            $table->text('derogation_request')->nullable();
            $table->string('status')->default('BROUILLON');
            $table->foreignId('created_by_id')->constrained('users');
            $table->foreignId('approved_by_id')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 18, 3);
            $table->string('unit')->nullable();
            $table->decimal('unit_price', 18, 2);
        });

        // Paiement — préparé par le Financier, approuvé par la DE (RG-15)
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique(); // PAI-2026-0001
            $table->foreignId('purchase_order_id')->constrained();
            $table->foreignId('treasury_account_id')->constrained();
            $table->string('currency', 3);
            $table->decimal('amount', 18, 2);
            $table->decimal('exchange_rate', 20, 10)->default(1);
            $table->decimal('amount_budget_ccy', 18, 2);
            $table->string('method')->nullable();
            $table->string('external_ref')->nullable();
            $table->string('status')->default('SOUMIS');
            $table->foreignId('prepared_by_id')->constrained('users');
            $table->foreignId('approved_by_id')->nullable()->constrained('users');
            $table->date('paid_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['payments', 'purchase_order_items', 'purchase_orders', 'purchase_request_items', 'purchase_requests', 'suppliers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
