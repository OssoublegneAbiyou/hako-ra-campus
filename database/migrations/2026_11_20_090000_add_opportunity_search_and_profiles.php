<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table): void {
            // Critères d'éligibilité publiés par l'organisateur, distincts du pays où se déroule l'opportunité (colonne countries).
            $table->json('eligible_nationalities')->nullable();
            $table->json('eligible_residences')->nullable();
            // Domaines en minuscules sans accents, pour la recherche et le filtre.
            $table->string('normalized_fields', 700)->default('');
            $table->index(['status', 'published_at'], 'opportunities_published_index');
        });
        foreach (DB::table('opportunities')->get(['id', 'fields']) as $row) {
            $fields = array_map(fn ($field) => trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii((string) $field)))), (array) json_decode((string) $row->fields, true));
            DB::table('opportunities')->where('id', $row->id)->update(['normalized_fields' => mb_substr(implode(' | ', array_filter($fields)), 0, 700)]);
        }

        // Critères déclarés par la personne pour comparer avec les fiches. Tout est facultatif et n'appartient qu'à elle.
        Schema::create('opportunity_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('level', 15)->nullable();
            $table->json('fields')->nullable();
            $table->json('languages')->nullable();
            $table->char('residence_country', 2)->nullable();
            $table->char('nationality', 2)->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE opportunity_profiles ADD CONSTRAINT opportunity_profiles_level_check CHECK (level IS NULL OR level IN ('secondary','bachelor','master','doctorate','professional'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunity_profiles');
        Schema::table('opportunities', function (Blueprint $table): void {
            $table->dropIndex('opportunities_published_index');
            $table->dropColumn(['eligible_nationalities', 'eligible_residences', 'normalized_fields']);
        });
    }
};
