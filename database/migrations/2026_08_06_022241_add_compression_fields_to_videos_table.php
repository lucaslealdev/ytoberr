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
            $table->string('video_codec')->nullable()->after('file_size');
            $table->string('compression_status')->nullable()->after('video_codec');
            $table->unsignedTinyInteger('compression_progress_percent')->nullable()->after('compression_status');
            $table->text('compression_error')->nullable()->after('compression_progress_percent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn(['video_codec', 'compression_status', 'compression_progress_percent', 'compression_error']);
        });
    }
};
