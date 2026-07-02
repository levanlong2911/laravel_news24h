<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('video_projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('article_id')->constrained('articles')->cascadeOnDelete();
            // pending → transformation_planned → story_planned → scene_planned →
            // shot_planned → camera_planned → graph_built → generating → complete | failed
            $table->string('status', 40)->default('pending');
            $table->string('video_type', 30)->default('visual_image'); // visual_image | visual_video | informational
            $table->unsignedTinyInteger('duration')->default(15);      // target seconds
            $table->string('theme', 120)->nullable();
            $table->string('style', 50)->nullable();                    // cinematic | documentary | dynamic | elegant
            $table->string('color_palette', 50)->nullable();
            $table->string('pacing', 20)->nullable();                   // slow | medium | fast | dynamic
            $table->json('emotion_arc')->nullable();                    // ["hook","craftsmanship","reveal","wow"]
            $table->json('transformation_json')->nullable();            // full TransformationPlanner output
            $table->json('story_json')->nullable();                     // StoryPlanner output
            $table->json('scene_graph_json')->nullable();               // final SceneGraph for Python API
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique('article_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_projects');
    }
};
