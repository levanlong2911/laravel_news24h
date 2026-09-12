<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_design_images', function (Blueprint $table) {
            $table->string('environment_key', 60)->nullable()->after('proves_state');

            $table->index(['project_id', 'environment_key'], 'video_design_images_environment_index');
        });
    }

    public function down(): void
    {
        if (DB::table('video_design_images')->whereNotNull('environment_key')->exists()) {
            throw new RuntimeException(
                'video_design_images con anh boi canh. Rollback se xoa khoa phan biet cua chung.'
            );
        }

        Schema::table('video_design_images', function (Blueprint $table) {
            $table->dropIndex('video_design_images_environment_index');
            $table->dropColumn('environment_key');
        });
    }
};
