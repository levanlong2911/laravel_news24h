<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_planning_stages', function (Blueprint $table) {
            $table->string('provider_model', 60)->nullable()->after('model');
        });
    }

    public function down(): void
    {
        Schema::table('video_planning_stages', function (Blueprint $table) {
            $table->dropColumn('provider_model');
        });
    }
};
