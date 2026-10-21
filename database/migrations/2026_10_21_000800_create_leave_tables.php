<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module Personnel, étape 2 : congés et absences (RG-23, Manuel §3.2 et §5.10).
 * Le registre des congés est en ajout seul, comme le grand livre budgétaire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // ANNUEL, MALADIE, MATERNITE…
            $table->string('label');
            $table->boolean('deducts_balance')->default(false); // décompté du solde annuel
            $table->boolean('own_balance')->default(false); // solde propre (récupération)
            $table->decimal('pay_rate', 5, 4)->default(1); // 1 = salaire maintenu, 0.6667 = 2/3, 0 = non payé
            $table->boolean('calendar_days')->default(false); // maternité : jours calendaires
            $table->decimal('max_days', 6, 2)->nullable();
            $table->unsignedSmallInteger('document_after_days')->nullable(); // justificatif exigé à partir de n jours
            $table->unsignedSmallInteger('notice_days')->nullable(); // préavis demandé
            $table->string('final_approver')->default('RH');
            $table->unsignedSmallInteger('min_seniority_months')->nullable();
            $table->boolean('requires_confirmation')->default(false); // interdit pendant l'essai
            $table->boolean('self_service')->default(true); // l'employé peut le demander lui-même
            $table->string('gender', 1)->nullable(); // F pour maternité, M pour paternité
            $table->text('rule')->nullable(); // rappel de la règle, affiché au demandeur
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('public_holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('label');
            $table->timestamps();
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique(); // CG-2026-0001
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained();
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('start_half')->default(false); // commence l'après-midi
            $table->boolean('end_half')->default(false); // se termine à midi
            $table->decimal('days', 6, 2);
            $table->unsignedSmallInteger('leave_year')->nullable(); // année contractuelle (0 = première)
            $table->date('expected_birth_date')->nullable(); // maternité
            $table->foreignId('interim_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->boolean('is_advance')->default(false); // congé pris par anticipation (Manuel §5.10.1)
            $table->boolean('late_notice')->default(false); // préavis non respecté
            $table->string('status')->default('SOUMISE');
            $table->foreignId('requested_by_id')->constrained('users');
            $table->foreignId('supervisor_decided_by_id')->nullable()->constrained('users');
            $table->timestamp('supervisor_decided_at')->nullable();
            $table->foreignId('approved_by_id')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'start_date']);
            $table->index('status');
        });

        Schema::create('leave_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained();
            $table->string('balance'); // ANNUEL ou RECUPERATION
            $table->unsignedSmallInteger('leave_year')->nullable();
            $table->string('kind');
            $table->decimal('days', 6, 2); // positif = crédit, négatif = débit
            $table->string('reference')->nullable(); // M3 = 3e mois de service, A2 = ancienneté année 2…
            $table->foreignId('leave_request_id')->nullable()->constrained();
            $table->text('note')->nullable();
            $table->foreignId('recorded_by_id')->nullable()->constrained('users');
            $table->date('effective_date');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['employee_id', 'balance', 'reference']);
        });

        $this->appendOnly('leave_ledger');
    }

    private function appendOnly(string $table): void
    {
        $driver = DB::getDriverName();
        $msg = "Table {$table} en ajout seul : modification et suppression interdites.";
        if ($driver === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $op) {
                DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($op)." BEFORE {$op} ON {$table} BEGIN SELECT RAISE(ABORT, '{$msg}'); END;");
            }
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            foreach (['UPDATE', 'DELETE'] as $op) {
                DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($op)." BEFORE {$op} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$msg}'");
            }
        } elseif ($driver === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION hr_append_only() RETURNS trigger AS \$\$
                BEGIN RAISE EXCEPTION 'Table % en ajout seul : modification et suppression interdites.', TG_TABLE_NAME; END;
                \$\$ LANGUAGE plpgsql;");
            DB::unprepared("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION hr_append_only();");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS leave_ledger_append_only ON leave_ledger');
        } else {
            DB::unprepared('DROP TRIGGER IF EXISTS leave_ledger_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS leave_ledger_no_delete');
        }
        Schema::dropIfExists('leave_ledger');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('public_holidays');
        Schema::dropIfExists('leave_types');
    }
};
