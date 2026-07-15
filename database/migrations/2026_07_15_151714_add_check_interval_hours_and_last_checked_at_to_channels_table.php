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
            // Null means "use the global default" (Channel::DEFAULT_CHECK_INTERVAL_HOURS),
            // rather than baking the default into every existing row.
            $table->unsignedSmallInteger('check_interval_hours')->nullable()->after('cutoff_date');
            $table->timestamp('last_checked_at')->nullable()->after('check_interval_hours');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['check_interval_hours', 'last_checked_at']);
        });
    }
};
