<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finances & Budget : exercices, budgets versionnés, lignes, grand livre,
 * dérogations, taux de change et comptes de trésorerie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->unique();
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_closed')->default(false);
            $table->timestamps();
        });

        // Budget annuel d'un projet (RG-12), dans une devise de référence (RG-11)
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained();
            $table->foreignId('fiscal_year_id')->constrained();
            $table->foreignId('grant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('currency', 3)->default('USD');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'fiscal_year_id']);
        });

        // Version d'un budget : initiale puis révisions (RG-10)
        Schema::create('budget_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_no');
            $table->string('status')->default('BROUILLON');
            $table->string('reason')->nullable();
            $table->foreignId('derived_from_id')->nullable()->constrained('budget_versions');
            $table->foreignId('created_by_id')->constrained('users');
            $table->foreignId('approved_by_id')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['budget_id', 'version_no']);
        });

        // Ligne budgétaire : identité stable d'une version à l'autre
        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('label');
            $table->string('category')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['budget_id', 'code']);
        });

        // Montant budgétisé d'une ligne dans une version
        Schema::create('budget_line_amounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_line_id')->constrained();
            $table->decimal('amount', 18, 2);
            $table->unique(['budget_version_id', 'budget_line_id']);
        });

        // Dérogation exceptionnelle de dépassement, réservée à la DE (RG-09)
        Schema::create('derogations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_line_id')->constrained();
            $table->decimal('amount_over', 18, 2);
            $table->text('justification');
            $table->nullableMorphs('source');
            $table->foreignId('granted_by_id')->constrained('users');
            $table->timestamp('granted_at');
            $table->timestamp('regularize_by');
            $table->timestamp('regularized_at')->nullable();
            $table->timestamps();
        });

        // Grand livre : écritures signées, en ajout seul, dans la devise du budget (RG-01)
        Schema::create('budget_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_line_id')->constrained();
            $table->string('kind');
            $table->decimal('amount', 18, 2);
            $table->string('source');
            $table->unsignedBigInteger('source_id');
            $table->foreignId('derogation_id')->nullable()->constrained();
            $table->string('note')->nullable();
            $table->foreignId('created_by_id')->constrained('users');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['budget_line_id', 'kind']);
            $table->index(['source', 'source_id']);
        });

        // Taux saisi par le Financier, ex. 1 USD = 2 850 CDF (RG-11)
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->date('rate_date');
            $table->string('from_currency', 3);
            $table->string('to_currency', 3);
            $table->decimal('rate', 18, 6);
            $table->string('source')->default('BCC');
            $table->foreignId('entered_by_id')->constrained('users');
            $table->timestamps();
            $table->unique(['rate_date', 'from_currency', 'to_currency']);
        });

        // Caisse ou compte bancaire (RG-16)
        Schema::create('treasury_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('type');
            $table->string('currency', 3);
            $table->string('bank_name')->nullable();
            $table->string('account_ref', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['treasury_accounts', 'exchange_rates', 'budget_movements', 'derogations', 'budget_line_amounts', 'budget_lines', 'budget_versions', 'budgets', 'fiscal_years'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
