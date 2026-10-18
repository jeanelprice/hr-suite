<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fondations transverses : utilisateurs, habilitations (C), délégations,
 * validations, journal d'audit, échéances (D) et paramètres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('STAFF_TERRAIN')->after('email');
            $table->boolean('is_active')->default(true)->after('role');
            // Supérieur hiérarchique direct : pilote les alertes (RG-08) en attendant le module RH
            $table->foreignId('supervisor_id')->nullable()->after('is_active')->constrained('users')->nullOnDelete();
            $table->timestamp('last_login_at')->nullable();
        });

        // Habilitation individuelle à un module, octroyée par la Direction Exécutive
        Schema::create('module_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('module');
            $table->string('level');
            $table->timestamp('expires_at')->nullable(); // accès temporaire (Fondation D)
            $table->foreignId('granted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'module']);
        });

        // Délégation temporaire de validation (RG-14)
        Schema::create('delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delegator_id')->constrained('users');
            $table->foreignId('delegate_id')->constrained('users');
            $table->json('modules');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('reason');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        // Circuit de validation générique (ségrégation : demandeur ≠ décideur)
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->morphs('approvable');
            $table->foreignId('requested_by_id')->constrained('users');
            $table->foreignId('decided_by_id')->nullable()->constrained('users');
            $table->string('decision')->default('EN_ATTENTE');
            $table->text('comment')->nullable();
            $table->boolean('via_delegation')->default(false);
            $table->timestamp('due_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        // Journal d'audit en ajout seul et chaîné (RG-20)
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at', 6);
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->string('action');
            $table->string('entity_type');
            $table->string('entity_id');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('prev_hash', 64)->nullable();
            $table->string('hash', 64)->unique();
            $table->index(['entity_type', 'entity_id']);
        });

        // Échéances surveillées (Fondation D, RG-19)
        Schema::create('deadlines', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->morphs('subject');
            $table->foreignId('assignee_id')->nullable()->constrained('users');
            $table->timestamp('due_at');
            $table->string('status')->default('OUVERTE');
            $table->timestamp('last_reminded_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'due_at']);
        });

        // Paramètres modifiables sans développement
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('deadlines');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('delegations');
        Schema::dropIfExists('module_accesses');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supervisor_id');
            $table->dropColumn(['role', 'is_active', 'last_login_at']);
        });
    }
};
