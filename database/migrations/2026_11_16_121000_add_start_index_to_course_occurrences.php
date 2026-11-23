<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index de recherche des chevauchements : sans lui, chaque contrôle de conflit relit tous les cours de l'établissement.
     */
    public function up(): void
    {
        Schema::table('course_occurrences', function (Blueprint $table): void {
            $table->index(['institution_id', 'starts_at'], 'occurrence_institution_start');
        });
    }

    public function down(): void
    {
        Schema::table('course_occurrences', function (Blueprint $table): void {
            $table->dropIndex('occurrence_institution_start');
        });
    }
};
