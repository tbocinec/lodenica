<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who reported the damage and who is fixing it. Both are plain names, not
 * user references: a repair is often done by someone with no account here
 * (external service, a member's mate with a welder), and the reporter can
 * be anonymous — the damage form is open to everyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('damages', function (Blueprint $table) {
            $table->string('reportedByName', 120)->nullable();
            $table->string('assigneeName', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('damages', function (Blueprint $table) {
            $table->dropColumn(['reportedByName', 'assigneeName']);
        });
    }
};
