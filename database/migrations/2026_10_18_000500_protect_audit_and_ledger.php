<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RG-20 et RG-01 : le journal d'audit et le grand livre budgétaire sont en ajout seul.
 * La base refuse toute modification ou suppression d'une ligne, même faite
 * directement par un développeur ou un outil d'administration.
 */
return new class extends Migration
{
    private array $tables = ['audit_logs', 'budget_movements'];

    public function up(): void
    {
        $driver = DB::getDriverName();

        foreach ($this->tables as $table) {
            if ($driver === 'sqlite') {
                foreach (['UPDATE', 'DELETE'] as $op) {
                    DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($op)." BEFORE {$op} ON {$table}
                        BEGIN SELECT RAISE(ABORT, 'Table {$table} en ajout seul : modification et suppression interdites.'); END;");
                }
            } elseif ($driver === 'mysql' || $driver === 'mariadb') {
                foreach (['UPDATE', 'DELETE'] as $op) {
                    DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($op)." BEFORE {$op} ON {$table} FOR EACH ROW
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Table {$table} en ajout seul : modification et suppression interdites.'");
                }
            } elseif ($driver === 'pgsql') {
                DB::unprepared("CREATE OR REPLACE FUNCTION hr_append_only() RETURNS trigger AS \$\$
                    BEGIN RAISE EXCEPTION 'Table % en ajout seul : modification et suppression interdites.', TG_TABLE_NAME; END;
                    \$\$ LANGUAGE plpgsql;");
                DB::unprepared("CREATE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table}
                    FOR EACH ROW EXECUTE FUNCTION hr_append_only();");
            }
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        foreach ($this->tables as $table) {
            if ($driver === 'pgsql') {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_append_only ON {$table}");
            } else {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_update");
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_delete");
            }
        }
    }
};
