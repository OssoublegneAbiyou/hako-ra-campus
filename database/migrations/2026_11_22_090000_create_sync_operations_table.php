<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registre des opérations synchronisées depuis un appareil. Il ne contient aucun contenu saisi :
     * seulement l'identité de l'opération, son empreinte et le résultat déjà rendu, pour pouvoir le rendre à nouveau.
     */
    public function up(): void
    {
        Schema::create('sync_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('operation_id');
            $table->string('type', 30);
            $table->string('context', 20);
            $table->char('fingerprint', 64);
            $table->uuid('target_id')->nullable();
            $table->unsignedInteger('base_version');
            $table->string('outcome', 20);
            $table->string('code', 40)->nullable();
            $table->uuid('result_id')->nullable();
            $table->unsignedInteger('result_version')->nullable();
            $table->string('result_status', 20)->nullable();
            $table->json('errors')->nullable();
            $table->dateTime('created_at');
            // L'unicité d'idempotence est liée au compte et à l'opération : la même clé chez un autre compte est une autre opération.
            $table->unique(['user_id', 'operation_id'], 'sync_operations_user_operation_unique');
            $table->index('created_at', 'sync_operations_created_index');
        });
        DB::statement("ALTER TABLE sync_operations ADD CONSTRAINT sync_operations_state_check CHECK (
            type IN ('task.create','task.update','check_in.save') AND context = 'personal'
            AND outcome IN ('applied','conflict','rejected')
            AND (outcome <> 'applied' OR (result_id IS NOT NULL AND result_version IS NOT NULL)))");
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_operations');
    }
};
