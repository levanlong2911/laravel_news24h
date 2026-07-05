<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bm_render_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('render_id')->unique()->constrained('bm_renders')->cascadeOnDelete();

            // Subject consistency (split per user design)
            $table->unsignedTinyInteger('identity_consistency')->nullable();
            $table->unsignedTinyInteger('appearance_consistency')->nullable();
            $table->unsignedTinyInteger('geometry_consistency')->nullable();
            $table->unsignedTinyInteger('temporal_consistency')->nullable();

            // Camera
            $table->unsignedTinyInteger('camera_obey')->nullable();
            $table->unsignedTinyInteger('camera_continuity')->nullable();

            // Other metrics
            $table->unsignedTinyInteger('reveal_quality')->nullable();
            $table->unsignedTinyInteger('motion_realism')->nullable();
            $table->unsignedTinyInteger('physics')->nullable();
            $table->unsignedTinyInteger('emotion')->nullable();
            $table->unsignedTinyInteger('cinematic_feel')->nullable();
            $table->unsignedTinyInteger('eye_guidance')->nullable();

            $table->unsignedTinyInteger('overall')->nullable();
            $table->string('scored_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bm_render_scores');
    }
};
