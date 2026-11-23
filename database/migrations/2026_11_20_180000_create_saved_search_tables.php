<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Recherche enregistrée : des filtres du catalogue, une fréquence de synthèse, des canaux. Privée, propre à son auteur.
        Schema::create('saved_searches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->json('filters');
            $table->char('filters_hash', 64);
            $table->string('frequency', 10)->default('daily');
            $table->boolean('email')->default(false);
            $table->boolean('push')->default(false);
            $table->boolean('active')->default(true);
            // Seules les fiches publiées ou mises à jour après cet instant sont annoncées : aucune rafale à la création ni à la reprise.
            $table->dateTime('baseline_at');
            $table->dateTime('last_run_at')->nullable();
            $table->dateTime('last_digest_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['user_id', 'filters_hash'], 'saved_searches_filters_unique');
            $table->index(['active', 'last_run_at'], 'saved_searches_due_index');
        });
        DB::statement("ALTER TABLE saved_searches ADD CONSTRAINT saved_searches_frequency_check CHECK (frequency IN ('daily','weekly'))");

        // Une ligne par recherche, fiche et révision pertinente : c'est elle qui interdit d'annoncer deux fois la même chose.
        Schema::create('saved_search_matches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('saved_search_id')->constrained('saved_searches')->restrictOnDelete();
            $table->foreignUuid('opportunity_id')->constrained('opportunities')->restrictOnDelete();
            $table->string('revision_key', 40);
            $table->string('kind', 10);
            $table->string('detail', 255)->nullable();
            $table->string('digest_key', 190);
            $table->dateTime('created_at');
            $table->unique(['saved_search_id', 'opportunity_id', 'revision_key'], 'saved_search_matches_unique');
            $table->index(['saved_search_id', 'id'], 'saved_search_matches_recent_index');
        });
        DB::statement("ALTER TABLE saved_search_matches ADD CONSTRAINT saved_search_matches_kind_check CHECK (kind IN ('new','updated'))");

        Schema::table('notification_preferences', function (Blueprint $table): void {
            // Rappels d'échéance des fiches suivies, en jours avant la clôture ; 0 désactive le rappel.
            $table->unsignedTinyInteger('favorite_first_days')->default(7);
            $table->unsignedTinyInteger('favorite_second_days')->default(1);
        });
        DB::statement('ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_favorite_check CHECK (favorite_first_days <= 30 AND favorite_second_days <= 30)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notification_preferences DROP CHECK notification_preferences_favorite_check');
        Schema::table('notification_preferences', fn (Blueprint $table) => $table->dropColumn(['favorite_first_days', 'favorite_second_days']));
        Schema::dropIfExists('saved_search_matches');
        Schema::dropIfExists('saved_searches');
    }
};
