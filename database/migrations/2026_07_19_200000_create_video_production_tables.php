<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ADR v1.1 — Shot là business object; prompt chỉ là compiled artifact.
// Approval gate nằm trong Laravel: draft → approved/needs_revision/rejected → queued → rendered.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);                    // Daybreak...
            $table->string('subject_id', 64)->nullable();   // design asset subject
            $table->string('design_ref', 255)->nullable();  // work/design/<subject>/
            $table->timestamps();
        });

        Schema::create('video_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->foreign('project_id')->references('id')->on('video_projects')->cascadeOnDelete();
            $table->string('code', 64)->unique();           // daybreak_s2...
            $table->json('renderplan_json')->nullable();    // snapshot RenderPlan (nguồn sự thật)
            $table->string('status', 20)->default('draft'); // draft|reviewing|rendering|done
            $table->decimal('cost_estimate_total', 8, 3)->default(0);
            $table->decimal('cost_actual', 8, 3)->default(0);
            $table->timestamps();
        });

        Schema::create('video_shots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('session_id');
            $table->foreign('session_id')->references('id')->on('video_sessions')->cascadeOnDelete();
            $table->string('beat', 40);                     // 3_construction (cột, chưa cần bảng — Rule 2)
            $table->string('shot_code', 60);                // b3_s1_wide...
            $table->string('shot_type', 20);                // establish|medium|close|insert|hero|drone
            $table->string('kind', 10);                     // frame|motion
            $table->json('spec_json');                      // Specification (INPUT — compile lại được)
            $table->text('compiled_prompt');                // OUTPUT của Composer
            $table->text('negative_prompt')->nullable();
            $table->json('render_plan')->nullable();        // {provider,duration,cost_estimate,source_cell,renderer,priority}
            $table->string('status', 20)->default('draft'); // draft|approved|needs_revision|rejected|queued|rendering|rendered|failed
            $table->text('review_note')->nullable();        // lý do needs_revision
            $table->string('preview_path', 255)->nullable();// ảnh nguồn/cell để review nhanh
            $table->string('artifact_path', 255)->nullable();// kết quả render
            $table->decimal('cost_estimate', 8, 3)->default(0);
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['session_id', 'shot_code', 'kind']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_shots');
        Schema::dropIfExists('video_sessions');
        Schema::dropIfExists('video_projects');
    }
};
