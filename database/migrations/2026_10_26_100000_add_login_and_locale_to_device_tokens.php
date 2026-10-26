<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 (decisions #3 and #5):
 * - personal_access_token_id: the login this phone's push token belongs to. Logging out, a
 *   password reset (all logins revoked) or an expired login deletes that row, and the push token
 *   goes with it (cascade), so a logged-out phone never receives someone's notifications.
 * - locale: the phone's language (en / fil), for the push text. Pushes are written on the
 *   server, often by the scheduler, when there is no request to read Accept-Language from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->foreignId('personal_access_token_id')->nullable()->after('user_id')
                ->constrained()->cascadeOnDelete();
            $table->string('locale', 5)->default('fil')->after('platform');
        });
    }

    public function down(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('personal_access_token_id');
            $table->dropColumn('locale');
        });
    }
};
