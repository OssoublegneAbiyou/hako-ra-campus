<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $t): void {
            $t->uuid('subject_uuid')->nullable();
            $t->index(['subject_type', 'subject_uuid']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $t): void {
            $t->dropIndex(['subject_type', 'subject_uuid']);
            $t->dropColumn('subject_uuid');
        });
    }
};
