<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Second facteur par code temporaire (application d'authentification). Un seul par compte. Le secret est chiffré par l'application ;
     * les codes de secours ne sont conservés que sous forme d'empreintes. Table ajoutée sans toucher à l'existant.
     */
    public function up(): void
    {
        Schema::create('second_factors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->text('secret');
            $table->dateTime('confirmed_at')->nullable();
            // Dernier pas de temps accepté : un code déjà utilisé ne peut pas resservir.
            $table->unsignedBigInteger('last_used_step')->nullable();
            $table->json('recovery_codes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('second_factors');
    }
};
