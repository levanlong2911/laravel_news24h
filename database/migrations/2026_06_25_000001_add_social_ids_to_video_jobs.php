<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->string('tiktok_post_id', 100)->nullable()->after('facebook_post_id');
            $table->string('instagram_post_id', 100)->nullable()->after('tiktok_post_id');
        });
    }

    public function down(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->dropColumn(['tiktok_post_id', 'instagram_post_id']);
        });
    }
};
