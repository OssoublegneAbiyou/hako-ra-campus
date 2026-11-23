<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un favori au plus par personne et par fiche, quel que soit le nombre d'appareils.
        Schema::create('opportunity_favorites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('opportunity_id')->constrained('opportunities')->restrictOnDelete();
            $table->dateTime('created_at');
            $table->unique(['user_id', 'opportunity_id'], 'opportunity_favorites_unique');
            $table->index(['opportunity_id'], 'opportunity_favorites_opportunity_index');
        });

        // Démarche privée : suivi personnel d'une candidature. Aucun fichier, aucune pièce d'identité, aucun document.
        Schema::create('opportunity_applications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('opportunity_id')->constrained('opportunities')->restrictOnDelete();
            $table->string('status', 12)->default('to_study');
            $table->date('personal_deadline_on')->nullable();
            $table->string('note', 500)->nullable();
            // Résumé daté minimal de la fiche, pour comprendre la démarche après l'archivage de la fiche. Jamais le texte de la source.
            $table->json('reference');
            $table->dateTime('status_changed_at');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['user_id', 'opportunity_id'], 'opportunity_applications_unique');
            $table->index(['opportunity_id', 'status'], 'opportunity_applications_opportunity_index');
        });
        DB::statement("ALTER TABLE opportunity_applications ADD CONSTRAINT opportunity_applications_status_check CHECK (status IN ('to_study','preparing','sent','waiting','accepted','rejected','abandoned'))");

        Schema::create('opportunity_application_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('opportunity_application_id')->constrained('opportunity_applications')->restrictOnDelete();
            $table->string('label', 160);
            $table->boolean('done')->default(false);
            $table->unsignedSmallInteger('position');
            $table->uuid('client_key');
            $table->timestamps();
            $table->unique(['opportunity_application_id', 'client_key'], 'opportunity_application_items_client_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunity_application_items');
        Schema::dropIfExists('opportunity_applications');
        Schema::dropIfExists('opportunity_favorites');
    }
};
