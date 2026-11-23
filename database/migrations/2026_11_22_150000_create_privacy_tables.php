<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Droits des personnes et conservation : prise de connaissance des notices, demandes suivies, exports privés à durée limitée,
     * et état « effacé » d'un compte. Migration additive : aucune donnée existante n'est modifiée.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Dernière activité connue, à la journée près : sert à la seule revue d'inactivité, jamais à une suppression automatique.
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('erased_at')->nullable();
        });
        DB::statement('ALTER TABLE users DROP CHECK users_status_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active','suspended','erased'))");
        // Un compte effacé n'a plus ni adresse ni identifiant ; tout autre compte doit toujours en avoir un.
        DB::statement('ALTER TABLE users DROP CHECK users_login_method_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_login_method_check CHECK (email IS NOT NULL OR login_identifier IS NOT NULL OR status = 'erased')");

        Schema::create('privacy_acknowledgements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('notice_version', 20);
            $table->char('notice_hash', 64);
            $table->dateTime('created_at');
            $table->unique(['user_id', 'notice_version'], 'privacy_ack_user_version_unique');
        });

        Schema::create('privacy_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 20);
            $table->string('status', 20)->default('received');
            $table->text('message')->nullable();
            $table->text('response')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('identity_verified_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at'], 'privacy_requests_user_index');
            $table->index(['status', 'created_at'], 'privacy_requests_status_index');
        });
        DB::statement("ALTER TABLE privacy_requests ADD CONSTRAINT privacy_requests_state_check CHECK (
            type IN ('access','rectification','objection','erasure')
            AND status IN ('received','in_progress','completed','refused','cancelled')
            AND ((status IN ('received','in_progress')) = (closed_at IS NULL)))");

        Schema::create('data_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('privacy_request_id')->nullable()->constrained('privacy_requests')->restrictOnDelete();
            $table->string('stored_name', 80);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->dateTime('expires_at');
            $table->unsignedInteger('downloads')->default(0);
            $table->dateTime('last_downloaded_at')->nullable();
            $table->dateTime('file_removed_at')->nullable();
            $table->dateTime('created_at');
            $table->index(['user_id', 'created_at'], 'data_exports_user_index');
            $table->index('expires_at', 'data_exports_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_exports');
        Schema::dropIfExists('privacy_requests');
        Schema::dropIfExists('privacy_acknowledgements');
        DB::statement('ALTER TABLE users DROP CHECK users_login_method_check');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_login_method_check CHECK (email IS NOT NULL OR login_identifier IS NOT NULL)');
        DB::statement('ALTER TABLE users DROP CHECK users_status_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active','suspended'))");
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['last_seen_at', 'erased_at']));
    }
};
