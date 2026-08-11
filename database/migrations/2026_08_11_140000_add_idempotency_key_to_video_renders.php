<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_renders', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->nullable()->after('attempt_no');
            $table->unique(['shot_id', 'idempotency_key'], 'video_renders_shot_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('video_renders', function (Blueprint $table) {
            $table->dropUnique('video_renders_shot_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
