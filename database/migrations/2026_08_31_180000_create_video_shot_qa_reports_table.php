<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_shot_qa_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('video_shot_id');
            $table->string('canonical_hash', 64);
            $table->string('identity_lock_hash', 64);
            $table->string('render_plan_hash', 64);
            $table->string('scene_projection_hash', 64);
            $table->string('scene_state_graph_hash', 64);
            $table->string('motion_spec_hash', 64);
            $table->string('render_request_hash', 64);
            $table->string('artifact_hash', 64);
            $table->string('report_hash', 64)->unique();
            $table->string('status', 32);
            $table->string('failure_signature', 64)->nullable();
            $table->json('report_json');
            $table->timestamps();

            $table->index(['video_shot_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_shot_qa_reports');
    }
};
