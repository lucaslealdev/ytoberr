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
        Schema::table('channels', function (Blueprint $table) {
            // Off = the channel is kept in the library but never checked for new videos, never
            // downloaded from, and its videos are never checked for bans/removal. Meant for
            // channels that were deleted on YouTube, whose videos would otherwise all look gone.
            $table->boolean('tracking_enabled')->default(true)->after('check_interval_hours');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('tracking_enabled');
        });
    }
};
