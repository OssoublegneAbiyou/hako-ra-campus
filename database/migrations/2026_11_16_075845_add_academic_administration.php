<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['academic_years', 'periods', 'groups', 'subjects', 'enrollments', 'teaching_assignments', 'institutions'] as $name) {
            Schema::table($name, function (Blueprint $t): void {
                $t->unsignedInteger('lock_version')->default(1);
            });
        }
        Schema::create('academic_period_types', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('institution_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('code', 40);
            $t->string('name');
            $t->timestamp('archived_at')->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestamps();
            $t->unique(['institution_id', 'code']);
            $t->unique(['owner_id', 'code']);
            $t->unique(['id', 'institution_id']);
            $t->unique(['id', 'owner_id']);
        });
        DB::statement('ALTER TABLE academic_period_types ADD CONSTRAINT period_type_scope CHECK ((institution_id IS NOT NULL AND owner_id IS NULL) OR (institution_id IS NULL AND owner_id IS NOT NULL))');
        Schema::table('periods', function (Blueprint $t): void {
            $t->unsignedBigInteger('period_type_id')->nullable();
            $t->foreign(['period_type_id', 'institution_id'])->references(['id', 'institution_id'])->on('academic_period_types')->restrictOnDelete();
        });
        Schema::create('personal_subjects', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $t->string('code', 40);
            $t->string('name');
            $t->timestamp('archived_at')->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestamps();
            $t->unique(['owner_id', 'code']);
        });
        Schema::create('personal_periods', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('parent_period_id')->nullable();
            $t->unsignedBigInteger('period_type_id')->nullable();
            $t->string('code', 40);
            $t->string('name');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('timezone', 64)->default('Africa/Abidjan');
            $t->decimal('weight', 10, 4)->default(1);
            $t->timestamp('archived_at')->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestamps();
            $t->unique(['owner_id', 'code']);
            $t->unique(['id', 'owner_id']);
            $t->index(['owner_id', 'starts_on', 'ends_on']);
            $t->foreign(['parent_period_id', 'owner_id'])->references(['id', 'owner_id'])->on('personal_periods')->restrictOnDelete();
            $t->foreign(['period_type_id', 'owner_id'])->references(['id', 'owner_id'])->on('academic_period_types')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE personal_periods ADD CONSTRAINT personal_period_dates CHECK (ends_on >= starts_on AND weight > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_periods');
        Schema::dropIfExists('personal_subjects');
        Schema::table('periods', function (Blueprint $t): void {
            $t->dropForeign(['period_type_id', 'institution_id']);
            $t->dropColumn('period_type_id');
        });
        Schema::dropIfExists('academic_period_types');
        foreach (['academic_years', 'periods', 'groups', 'subjects', 'enrollments', 'teaching_assignments', 'institutions'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->dropColumn('lock_version'));
        }
    }
};
