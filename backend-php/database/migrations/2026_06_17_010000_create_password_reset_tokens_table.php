<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standard Laravel password-reset token store, used by our custom
 * PasswordResetService for two flows that share the same mechanism:
 *   - "forgot password" (user requests a reset link)
 *   - account invitation (bulk CSV import / admin-created account whose
 *     owner sets their first password via the emailed link)
 *
 * One row per email (primary key). The token is stored HASHED; `created_at`
 * drives expiry. Portable across Postgres and SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');               // sha256 hash of the emailed token
            $table->timestamp('created_at')->nullable();
            $table->timestamp('expires_at')->nullable(); // per-row TTL (reset vs invite differ)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
