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
            $table->string('status')->default('pending'); // pending, downloading, completed, failed
            $table->integer('retries')->default(0);
            $table->text('last_error')->nullable();
            $table->boolean('prevent_download')->default(false);
            $table->string('unavailable_reason')->nullable();
            $table->timestamp('downloaded_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn(['status', 'retries', 'last_error', 'prevent_download', 'unavailable_reason', 'downloaded_at']);
        });
    }
};
