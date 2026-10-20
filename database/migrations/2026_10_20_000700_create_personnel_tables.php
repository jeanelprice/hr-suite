<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module Personnel, étape 1 : pièces jointes (Fondation B), organisation,
 * dossier du personnel et contrats. Référence : « Modèle de données du module
 * Personnel » (20/10/2026), Manuel des procédures RH du 28/02/2026.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Fondation B : pièce jointe universelle, versionnée, stockée hors du dossier public
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable');
            $table->string('category');
            $table->string('title')->nullable();
            $table->string('original_name');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->string('checksum', 64);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('supersedes_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->foreignId('uploaded_by_id')->constrained('users');
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        // Échelle salariale par catégorie et par étape (étape 1 = point d'entrée, Manuel §5.5)
        Schema::create('salary_grades', function (Blueprint $table) {
            $table->id();
            $table->string('category');
            $table->unsignedSmallInteger('step');
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('USD');
            $table->date('effective_from');
            $table->timestamps();
            $table->unique(['category', 'step', 'effective_from']);
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('salary_category')->nullable();
            $table->boolean('is_key')->default(false); // poste clé ou d'encadrement (jury élargi)
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('duty_stations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('province')->nullable();
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('matricule')->unique();
            // Identité (formulaire biographique, Manuel §2.4.5)
            $table->string('last_name');
            $table->string('middle_name')->nullable(); // post-nom
            $table->string('first_name');
            $table->string('gender', 1);
            $table->date('birth_date')->nullable();
            $table->string('birth_place')->nullable();
            $table->string('nationality')->default('Congolaise');
            $table->string('marital_status')->nullable();
            $table->string('father_name')->nullable();
            $table->string('mother_name')->nullable();
            $table->string('photo_path')->nullable();
            // Pièce d'identité
            $table->string('id_type')->nullable();
            $table->string('id_number')->nullable();
            $table->date('id_expires_at')->nullable();
            // Coordonnées
            $table->string('phone')->nullable();
            $table->string('phone2')->nullable();
            $table->string('personal_email')->nullable();
            $table->string('work_email')->nullable();
            $table->string('address_city')->nullable();
            $table->string('address_commune')->nullable();
            $table->string('address_quartier')->nullable();
            $table->string('address_avenue')->nullable();
            $table->string('address_number')->nullable();
            // Urgence (Manuel §2.4.6) ; les informations médicales sont chiffrées en base
            $table->string('emergency_name')->nullable();
            $table->string('emergency_relation')->nullable();
            $table->string('emergency_phone')->nullable();
            $table->text('medical_notes')->nullable();
            // Administratif ; le numéro de compte est chiffré en base
            $table->string('cnss_number')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('bank_name')->nullable();
            $table->text('bank_account')->nullable();
            // Formation
            $table->string('education_level')->nullable();
            $table->string('education_field')->nullable();
            // Situation
            $table->string('employment_category')->default('TEMPS_PLEIN');
            $table->date('hired_at'); // date de présentation au lieu d'affectation (Manuel §1.5)
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('duty_station_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supervisor_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('status')->default('EN_ESSAI');
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['last_name', 'first_name']);
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('head_employee_id')->nullable()->after('name')->constrained('employees')->nullOnDelete();
        });

        Schema::create('employee_diplomas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('institution')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->timestamps();
        });

        // Famille immédiate : conjoint, enfants, parents (Manuel §1.5)
        Schema::create('employee_family', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('relation');
            $table->string('full_name');
            $table->date('birth_date')->nullable();
            $table->boolean('is_dependant')->default(false);
            $table->timestamps();
        });

        // Liens de parenté déclarés avec un employé ou un membre du CA (Manuel §3.17, RG-27)
        Schema::create('employee_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            $table->string('related_name')->nullable(); // membre du CA ou autre personne
            $table->string('relation');
            $table->date('declared_at');
            $table->timestamps();
        });

        Schema::create('service_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('number')->unique();
            $table->date('issued_at');
            $table->date('expires_at')->nullable();
            $table->string('status')->default('ACTIVE');
            $table->date('closed_at')->nullable();
            $table->decimal('replacement_fee', 18, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('disciplinary_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('offence_level');
            $table->string('measure');
            $table->text('facts');
            $table->date('decided_at');
            $table->date('counts_until')->nullable(); // mise en demeure : 12 mois (Manuel §4.5.2)
            $table->foreignId('decided_by_id')->constrained('users');
            $table->string('appeal_status')->default('AUCUN');
            $table->text('appeal_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_exits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('reason');
            $table->date('exit_date');
            $table->text('debrief_notes')->nullable(); // compte rendu de fin de contrat (Manuel §2.7)
            $table->boolean('card_returned')->default(false);
            $table->boolean('equipment_returned')->default(false);
            $table->timestamp('clearance_issued_at')->nullable(); // certificat d'autorisation (Manuel §5.2)
            $table->foreignId('clearance_by_id')->nullable()->constrained('users');
            $table->foreignId('recorded_by_id')->constrained('users');
            $table->timestamps();
        });

        Schema::create('employee_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('field');
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->date('effective_date');
            $table->foreignId('recorded_by_id')->nullable()->constrained('users');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique(); // CT-2026-0001
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('status')->default('BROUILLON');
            $table->date('start_date');
            $table->date('end_date')->nullable(); // vide pour un CDI
            $table->date('probation_end_date')->nullable();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->string('salary_category')->nullable();
            $table->unsignedSmallInteger('salary_step')->nullable();
            $table->foreignId('duty_station_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('base_salary', 18, 2);
            $table->string('currency', 3)->default('USD');
            $table->unsignedSmallInteger('weekly_hours')->default(40);
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->date('signed_at')->nullable();
            $table->date('terminated_at')->nullable();
            $table->text('termination_reason')->nullable();
            $table->string('probation_outcome')->nullable();
            $table->date('probation_decided_at')->nullable();
            $table->foreignId('probation_decided_by_id')->nullable()->constrained('users');
            $table->foreignId('created_by_id')->constrained('users');
            $table->timestamps();
            $table->index(['status', 'end_date']);
        });

        Schema::create('contract_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->json('old_value')->nullable();
            $table->json('new_value');
            $table->date('effective_date');
            $table->text('reason');
            $table->foreignId('created_by_id')->constrained('users');
            $table->timestamps();
        });

        // Ventilation du coût d'un contrat sur les lignes budgétaires (100 % par période)
        Schema::create('contract_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_line_id')->constrained();
            $table->decimal('percentage', 5, 2);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->timestamps();
        });

        Schema::create('board_mandates', function (Blueprint $table) {
            $table->id();
            $table->string('full_name');
            $table->string('email')->nullable();
            $table->string('office');
            $table->date('start_date');
            $table->date('end_date');
            $table->foreignId('renewed_from_id')->nullable()->constrained('board_mandates')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_mandates');
        Schema::dropIfExists('contract_allocations');
        Schema::dropIfExists('contract_amendments');
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('employee_history');
        Schema::dropIfExists('employee_exits');
        Schema::dropIfExists('disciplinary_actions');
        Schema::dropIfExists('service_cards');
        Schema::dropIfExists('employee_relations');
        Schema::dropIfExists('employee_family');
        Schema::dropIfExists('employee_diplomas');
        Schema::table('departments', fn (Blueprint $table) => $table->dropConstrainedForeignId('head_employee_id'));
        Schema::dropIfExists('employees');
        Schema::dropIfExists('duty_stations');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('salary_grades');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('attachments');
    }
};
