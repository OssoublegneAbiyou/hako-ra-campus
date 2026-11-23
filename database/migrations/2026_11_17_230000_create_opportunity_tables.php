<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('category', 20);
            $table->string('title', 200);
            $table->string('organizer', 200);
            $table->string('campaign', 60);
            $table->string('source_url', 2000);
            $table->string('application_url', 2000)->nullable();
            $table->text('summary')->nullable();
            $table->text('conditions')->nullable();
            $table->json('countries');
            $table->string('modality', 15)->default('unspecified');
            $table->json('levels');
            $table->json('fields');
            $table->json('languages');
            $table->string('funding', 15)->default('unspecified');
            $table->string('fees', 15)->default('unspecified');
            $table->string('fees_detail', 300)->nullable();
            // Précision de la date limite telle que publiée par l'organisateur : instant avec fuseau, date seule, clôture continue, non communiquée.
            $table->string('deadline_kind', 10)->default('unknown');
            $table->dateTime('deadline_at')->nullable();
            $table->string('deadline_timezone', 64)->nullable();
            $table->date('deadline_date')->nullable();
            // Bornes techniques de l'état du catalogue, jamais présentées comme une heure officielle.
            $table->dateTime('last_day_starts_at')->nullable();
            $table->dateTime('closes_at')->nullable();
            $table->json('previous_deadline')->nullable();
            $table->string('status', 12)->default('draft');
            $table->dateTime('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('suspended_at')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->uuid('previous_edition_id')->nullable();
            $table->string('normalized_title', 200);
            $table->string('normalized_organizer', 200);
            $table->char('source_hash', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['normalized_title', 'normalized_organizer', 'campaign'], 'opportunities_identity_unique');
            $table->index(['source_hash', 'campaign'], 'opportunities_source_index');
            $table->index(['status', 'closes_at'], 'opportunities_open_index');
            $table->index(['status', 'updated_at'], 'opportunities_status_index');
        });
        Schema::table('opportunities', function (Blueprint $table): void {
            $table->foreign('previous_edition_id')->references('id')->on('opportunities')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE opportunities ADD CONSTRAINT opportunities_values_check CHECK (
            category IN ('scholarship','competition','internship','training')
            AND modality IN ('onsite','remote','hybrid','unspecified')
            AND funding IN ('full','partial','none','unspecified') AND fees IN ('free','paid','unspecified')
            AND status IN ('draft','review','published','suspended','archived'))");
        DB::statement("ALTER TABLE opportunities ADD CONSTRAINT opportunities_deadline_check CHECK (
            (deadline_kind = 'instant' AND deadline_at IS NOT NULL AND deadline_timezone IS NOT NULL AND deadline_date IS NULL AND closes_at IS NOT NULL)
            OR (deadline_kind = 'date' AND deadline_date IS NOT NULL AND deadline_at IS NULL AND deadline_timezone IS NULL AND closes_at IS NOT NULL AND last_day_starts_at IS NOT NULL)
            OR (deadline_kind IN ('rolling','unknown') AND deadline_at IS NULL AND deadline_timezone IS NULL AND deadline_date IS NULL AND closes_at IS NULL AND last_day_starts_at IS NULL))");
        DB::statement("ALTER TABLE opportunities ADD CONSTRAINT opportunities_publication_check CHECK (
            status NOT IN ('published','suspended') OR (verified_at IS NOT NULL AND verified_by IS NOT NULL AND published_at IS NOT NULL AND conditions IS NOT NULL))");

        // Historique complet d'une fiche : chaque création, modification, vérification et changement d'état, avec son auteur.
        Schema::create('opportunity_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('opportunity_id')->constrained('opportunities')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('action', 30);
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('note', 500)->nullable();
            $table->json('snapshot');
            $table->dateTime('created_at');
            $table->unique(['opportunity_id', 'version'], 'opportunity_revisions_version_unique');
        });

        Schema::create('opportunity_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('opportunity_id')->constrained('opportunities')->restrictOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 20);
            $table->string('message', 500)->nullable();
            $table->string('status', 15)->default('new');
            $table->unsignedTinyInteger('open_slot')->nullable()->default(1);
            $table->foreignId('assignee_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('decision', 20)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
            $table->unique(['opportunity_id', 'reporter_id', 'open_slot'], 'opportunity_reports_open_unique');
            $table->index(['status', 'created_at'], 'opportunity_reports_queue_index');
            $table->index(['reporter_id', 'created_at'], 'opportunity_reports_reporter_index');
        });
        DB::statement("ALTER TABLE opportunity_reports ADD CONSTRAINT opportunity_reports_check CHECK (
            reason IN ('broken_link','wrong_date','closed','authenticity','other')
            AND status IN ('new','in_progress','resolved','rejected')
            AND ((status IN ('new','in_progress') AND open_slot IS NOT NULL AND open_slot = 1 AND decision IS NULL)
                OR (status IN ('resolved','rejected') AND open_slot IS NULL AND decision IS NOT NULL AND decided_by IS NOT NULL AND decided_at IS NOT NULL))
            AND (decision IS NULL OR decision IN ('corrected','suspended','archived','no_action')))");

        // Trace minimale du traitement d'un signalement. Le commentaire est interne à l'équipe éditoriale.
        Schema::create('opportunity_report_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_report_id')->constrained('opportunity_reports')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 20);
            $table->string('from_status', 15)->nullable();
            $table->string('to_status', 15)->nullable();
            $table->string('internal_comment', 1000)->nullable();
            $table->dateTime('created_at');
            $table->index(['opportunity_report_id', 'id'], 'opportunity_report_events_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunity_report_events');
        Schema::dropIfExists('opportunity_reports');
        Schema::dropIfExists('opportunity_revisions');
        Schema::table('opportunities', fn (Blueprint $table) => $table->dropForeign(['previous_edition_id']));
        Schema::dropIfExists('opportunities');
    }
};
