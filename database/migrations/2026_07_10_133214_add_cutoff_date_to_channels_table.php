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
            $table->date('cutoff_date')->nullable();
        });

        // Backfill existing records with today's date
        \Illuminate\Support\Facades\DB::table('channels')->update([
            'cutoff_date' => now()->toDateString()
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('cutoff_date');
        });
    }
};
