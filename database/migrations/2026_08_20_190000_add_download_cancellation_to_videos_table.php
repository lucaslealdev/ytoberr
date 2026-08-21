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
            // The OS process group ID of the currently-running yt-dlp download (see
            // YtDlpWrapper::runCommand), so the "Cancel" button on the Processes page can
            // signal it directly. Null whenever the video isn't actively downloading.
            $table->unsignedInteger('download_pid')->nullable()->after('progress_percent');

            // Set by ProcessesController::cancelDownload() when the user asks to cancel the
            // in-progress download. DownloadNextVideo checks this once the killed process exits
            // so it can record the outcome as a user cancellation instead of a real failure
            // (and, importantly, without counting it toward the video's retry limit).
            $table->timestamp('cancel_requested_at')->nullable()->after('download_pid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn(['download_pid', 'cancel_requested_at']);
        });
    }
};
