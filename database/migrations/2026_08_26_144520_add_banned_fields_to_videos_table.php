<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            // Set by BannedVideoCheckService once a downloaded video's thumbnail comes back
            // 404 (removed/banned from YouTube), as opposed to prevent_download/
            // unavailable_reason, which cover a video never successfully downloaded in the
            // first place.
            $table->boolean('is_banned')->default(false)->after('unavailable_reason');

            // Last time this video's live thumbnail was checked by the banned-videos check,
            // regardless of outcome. Drives both the batch-selection query (oldest/never
            // checked first) and detecting when a run has covered the whole library.
            $table->timestamp('banned_checked_at')->nullable()->after('is_banned');

            // Serves both BannedVideoCheckService's eligibility query (status='completed' AND
            // is_banned=false) and the Videos page's "banned only" filter (status='completed'
            // AND is_banned=true) — same reasoning as the existing ['status', 'created_at']
            // index below: a leading 'status' still satisfies the plain equality lookups every
            // other view does on it alone.
            $table->index(['status', 'is_banned']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropIndex(['status', 'is_banned']);
            $table->dropColumn(['is_banned', 'banned_checked_at']);
        });
    }
};
