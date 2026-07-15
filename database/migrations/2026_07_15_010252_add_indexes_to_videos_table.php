<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Unlike MySQL, SQLite does not automatically index foreign key columns, so
     * `channel_id` (constrained() in the original create_videos_table migration) has
     * been an unindexed FK all along. It's read constantly: a video's own channel
     * relation, the "more from this channel"/"suggested videos" lookups on the video
     * show page, and the channel-listing "sort by most recent video" join.
     *
     * `status` never got an index either, despite being the single most-filtered
     * column in the app (every queue view, the dashboard, and every "completed
     * videos" listing filters on it). Each of those queries pairs it with a
     * different sort column though (created_at, updated_at, published_at,
     * downloaded_at), so no one compound index can serve all of them equally well.
     *
     * We add a compound index on ['status', 'created_at'] rather than a plain
     * index on 'status' alone: a leading column in a compound index still satisfies
     * plain equality lookups on that column by itself (leftmost-prefix rule), so
     * this loses nothing for the queries that sort by something else (they still
     * get an indexed filter, just with a separate sort step for their own column).
     * What it gains is a fully indexed filter+sort for videos:download's
     * `where('status', 'pending')->orderBy('created_at', 'asc')->first()` query,
     * which runs on its own schedule every two minutes, forever, making it the
     * single hottest query against this table.
     */
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->index('channel_id');
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropIndex(['channel_id']);
            $table->dropIndex(['status', 'created_at']);
        });
    }
};
