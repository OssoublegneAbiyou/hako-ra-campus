<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_series', function (Blueprint $table): void {
            $table->string('external_key', 100)->nullable();
            $table->unique(['institution_id', 'academic_year_id', 'external_key'], 'series_external_unique');
        });

        Schema::create('import_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('institution_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('academic_year_id');
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('author_membership_id')->constrained('memberships')->restrictOnDelete();
            $table->string('original_name');
            $table->string('stored_path')->nullable();
            $table->unsignedInteger('size_bytes');
            $table->char('file_hash', 64);
            $table->uuid('import_key');
            $table->string('status', 20)->default('uploaded');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->json('counts')->nullable();
            $table->char('plan_hash', 64)->nullable();
            $table->longText('parsed_rows')->nullable();
            $table->string('failure', 500)->nullable();
            $table->dateTime('analyzed_at')->nullable();
            $table->dateTime('applied_at')->nullable();
            $table->dateTime('raw_purged_at')->nullable();
            $table->timestamps();
            $table->unique(['institution_id', 'academic_year_id', 'file_hash'], 'imports_file_unique');
            $table->unique(['institution_id', 'academic_year_id', 'import_key'], 'imports_key_unique');
            $table->index(['status', 'created_at'], 'imports_status_index');
            $table->foreign(['academic_year_id', 'institution_id'], 'imports_year_fk')
                ->references(['id', 'institution_id'])->on('academic_years')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT imports_status_check CHECK (status IN ('uploaded','analyzing','invalid','valid','applying','applied','failed'))");

        Schema::create('import_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('import_batch_id')->constrained('import_batches')->restrictOnDelete();
            $table->string('sheet', 64);
            $table->unsignedInteger('line')->nullable();
            $table->string('column_name', 40)->nullable();
            $table->string('severity', 10);
            $table->string('message', 500);
            $table->string('cell_value', 120)->nullable();
            $table->index(['import_batch_id', 'severity', 'line'], 'import_issues_report_index');
        });
        DB::statement("ALTER TABLE import_issues ADD CONSTRAINT import_issues_severity_check CHECK (severity IN ('error','conflict','warning'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('import_issues');
        Schema::dropIfExists('import_batches');
        Schema::table('course_series', function (Blueprint $table): void {
            $table->dropUnique('series_external_unique');
            $table->dropColumn('external_key');
        });
    }
};
