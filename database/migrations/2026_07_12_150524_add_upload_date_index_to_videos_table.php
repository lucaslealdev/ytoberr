<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            // Disambiguates videos uploaded on the same calendar date by the same channel,
            // since our episode numbering only has day granularity (counts down from 99,
            // mirroring how new videos are discovered newest-first in a channel listing).
            $table->unsignedTinyInteger('upload_date_index')->default(99)->after('published_at');
        });

        // Backfill: assign a distinct index to videos that already share a channel + calendar
        // date, so they stop colliding into the same Plex episode slot.
        $groups = DB::table('videos')
            ->select('channel_id', DB::raw('date(published_at) as upload_date'))
            ->groupBy('channel_id', 'upload_date')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $videoIds = DB::table('videos')
                ->where('channel_id', $group->channel_id)
                ->whereRaw('date(published_at) = ?', [$group->upload_date])
                ->orderBy('id')
                ->pluck('id');

            $index = 99;
            foreach ($videoIds as $videoId) {
                DB::table('videos')->where('id', $videoId)->update(['upload_date_index' => $index]);
                $index--;
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn('upload_date_index');
        });
    }
};
