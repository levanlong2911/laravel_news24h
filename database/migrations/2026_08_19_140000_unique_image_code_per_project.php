<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_design_images', function (Blueprint $table) {
            $table->unique(['project_id', 'image_code'], 'video_design_images_project_image_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('video_design_images', function (Blueprint $table) {
            $table->dropUnique('video_design_images_project_image_code_unique');
        });
    }
};
