<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_series', fn (Blueprint $t) => $t->date('materialized_until')->nullable());
    }

    public function down(): void
    {
        Schema::table('course_series', fn (Blueprint $t) => $t->dropColumn('materialized_until'));
    }
};
