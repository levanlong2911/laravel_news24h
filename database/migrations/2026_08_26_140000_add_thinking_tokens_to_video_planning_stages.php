<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_planning_stages', function (Blueprint $table) {
            $table->unsignedInteger('thinking_tokens')->default(0)->after('tokens_out');
        });
    }

    public function down(): void
    {
        Schema::table('video_planning_stages', function (Blueprint $table) {
            $table->dropColumn('thinking_tokens');
        });
    }
};
