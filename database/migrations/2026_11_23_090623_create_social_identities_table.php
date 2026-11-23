<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Comptes externes reliés à un compte Campus (aujourd'hui : Google). Campus ne garde que l'identifiant stable remis par le
     * fournisseur et l'adresse vue au moment du rattachement : ni jeton d'accès, ni jeton de renouvellement. Table ajoutée sans toucher à l'existant.
     */
    public function up(): void
    {
        Schema::create('social_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('subject', 191);
            $table->string('email')->nullable();
            $table->timestamps();
            // Un compte externe ne mène qu'à un compte Campus, et un compte Campus n'a qu'un compte par fournisseur.
            $table->unique(['provider', 'subject'], 'social_identities_provider_subject_unique');
            $table->unique(['user_id', 'provider'], 'social_identities_user_provider_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_identities');
    }
};
