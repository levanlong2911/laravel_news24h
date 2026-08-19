<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_planning_stages', function (Blueprint $table) {
            $table->timestamp('lease_expires_at')->nullable()->after('claimed_at');
        });
    }

    public function down(): void
    {
        Schema::table('video_planning_stages', function (Blueprint $table) {
            $table->dropColumn('lease_expires_at');
        });
    }
};
