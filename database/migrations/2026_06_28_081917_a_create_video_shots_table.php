<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('video_shots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('scene_id')->constrained('video_scenes')->cascadeOnDelete();
            $table->unsignedTinyInteger('shot_number');

            // Cinematic DSL — structured fields for easy filtering/display
            $table->string('shot_type', 60)->nullable();        // wide | close | macro | orbit | tracking | aerial
            $table->string('camera_angle', 60)->nullable();     // eye_level | 45_front_left | low_angle | birds_eye
            $table->string('lens', 20)->nullable();             // 24mm | 50mm | 85mm | 135mm
            $table->string('camera_movement', 60)->nullable();  // static | slow_push_in | dolly_right | orbit_cw
            $table->string('subject_actor', 80)->nullable();
            $table->string('subject_action', 120)->nullable();
            $table->string('subject_object', 120)->nullable();
            $table->string('lighting', 60)->nullable();
            $table->string('emotion', 60)->nullable();
            $table->decimal('estimated_duration', 4, 2)->nullable();
            $table->string('media_type', 30)->default('image_with_motion'); // image_with_motion | ai_video
            $table->boolean('needs_ai_video')->default(false);

            // Full DSL JSON (canonical) + compiled AI prompt
            $table->json('cinematic_dsl')->nullable();
            $table->text('compiled_prompt')->nullable();

            // pending → camera_planned → prompt_compiled → asset_ready | failed
            $table->string('status', 30)->default('pending');
            $table->timestamps();

            $table->unique(['scene_id', 'shot_number']);
            $table->index(['scene_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_shots');
    }
};
