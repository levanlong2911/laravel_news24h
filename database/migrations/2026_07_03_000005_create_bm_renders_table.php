<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bm_renders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('session_id')->constrained('bm_sessions');
            $table->foreignId('fixture_id')->constrained('bm_fixtures');
            $table->string('scene_category');               // denormalized for query speed
            $table->string('model');                         // 'kling-2.1'
            $table->string('resolution')->default('1080p');
            $table->unsignedTinyInteger('duration_seconds');
            $table->unsignedTinyInteger('fps')->default(24);
            $table->string('seed')->nullable();
            $table->unsignedSmallInteger('char_count');
            $table->string('prompt_version');               // 'sprint3_v1'
            $table->string('artifact_path');                // 'renders/sprint3/athletic/nfl/{uuid}/'
            $table->timestamp('rendered_at')->nullable();
            $table->timestamp('annotated_at')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'scene_category']);
            $table->index('fixture_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bm_renders');
    }
};
