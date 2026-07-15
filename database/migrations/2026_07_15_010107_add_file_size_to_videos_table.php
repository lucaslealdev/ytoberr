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
            // Cached size in bytes of the downloaded file, captured once at download time so
            // fileSize()/totalDownloadedBytes() don't need a filesystem stat on every page render.
            // Null for videos downloaded before this column existed, until backfilled.
            $table->unsignedBigInteger('file_size')->nullable()->after('file_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn('file_size');
        });
    }
};
