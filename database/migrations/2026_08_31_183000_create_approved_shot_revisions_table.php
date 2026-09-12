<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approved_shot_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('video_shot_id')->index();
            $table->string('shot_code', 120);
            $table->unsignedInteger('revision');
            $table->string('canonical_hash', 64);
            $table->string('identity_lock_hash', 64);
            $table->string('render_plan_hash', 64);
            $table->string('scene_projection_hash', 64);
            $table->string('scene_state_graph_hash', 64);
            $table->string('motion_spec_hash', 64);
            $table->string('render_request_hash', 64);
            $table->string('artifact_hash', 64);
            $table->string('qa_report_hash', 64);
            $table->string('provider', 80)->nullable();
            $table->string('provider_model', 120)->nullable();
            $table->string('status', 32);
            $table->timestamp('frozen_at');
            $table->timestamps();

            $table->unique(['video_shot_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approved_shot_revisions');
    }
};
