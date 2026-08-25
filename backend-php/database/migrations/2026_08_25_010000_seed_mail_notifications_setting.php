<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the `mail_notifications` row with every notification switched ON,
 * so deploying the admin switches changes no behaviour. Idempotent: an
 * existing row (an admin already turned something off) is left alone.
 */
return new class extends Migration
{
    private const KEY = 'mail_notifications';

    public function up(): void
    {
        if (DB::table('settings')->where('key', self::KEY)->exists()) {
            return;
        }

        DB::table('settings')->insert([
            'key' => self::KEY,
            'value' => json_encode([
                'password_reset' => true,
                'account_invitation' => true,
                'membership_approved' => true,
                'pending_member_admin' => true,
            ], JSON_THROW_ON_ERROR),
            'updatedAt' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', self::KEY)->delete();
    }
};
