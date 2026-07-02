<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('video_scenes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('video_projects')->cascadeOnDelete();
            $table->unsignedTinyInteger('scene_number');
            $table->string('title', 120);
            $table->string('emotion', 60)->nullable();
            $table->string('goal', 250)->nullable();
            $table->decimal('duration', 5, 2)->nullable();
            $table->json('objects')->nullable();               // key visual objects in scene
            $table->string('location', 120)->nullable();
            $table->string('lighting', 60)->nullable();
            $table->string('color_grade', 60)->nullable();
            // pending → shot_planned → camera_planned → complete
            $table->string('status', 30)->default('pending');
            $table->timestamps();

            $table->unique(['project_id', 'scene_number']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_scenes');
    }
};
