<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_sessions', function (Blueprint $table) {
            $table->unsignedInteger('plan_revision')->default(0)->after('renderplan_json');
        });

        Schema::table('video_shots', function (Blueprint $table) {
            $table->unsignedInteger('plan_revision')->default(0)->after('render_plan');
        });
    }

    public function down(): void
    {
        Schema::table('video_shots', function (Blueprint $table) {
            $table->dropColumn('plan_revision');
        });

        Schema::table('video_sessions', function (Blueprint $table) {
            $table->dropColumn('plan_revision');
        });
    }
};
