<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projets, objectifs statutaires et bailleurs.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Objectifs statutaires (Article 8 des statuts)
        Schema::create('statutory_objectives', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('label');
            $table->timestamps();
        });

        Schema::create('donors', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('contact')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        Schema::create('grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donor_id')->constrained();
            $table->string('reference')->unique();
            $table->string('title');
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('USD');
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->string('status')->default('BROUILLON'); // RG-13
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('grant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('zone');
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('project_statutory_objective', function (Blueprint $table) {
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('statutory_objective_id')->constrained()->cascadeOnDelete();
            $table->primary(['project_id', 'statutory_objective_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_statutory_objective');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('grants');
        Schema::dropIfExists('donors');
        Schema::dropIfExists('statutory_objectives');
    }
};
