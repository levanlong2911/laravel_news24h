<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('media_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('video_projects')->cascadeOnDelete();
            // render | thumbnail | translate | publish | rerender | voice | music
            $table->string('job_type', 30)->default('render');
            $table->unsignedTinyInteger('priority')->default(5);
            // payload is minimal — Python claims job → Laravel builds SceneGraph realtime
            $table->json('payload')->nullable();
            // pending → claimed → rendering → completed | failed
            $table->string('status', 30)->default('pending');
            $table->unsignedTinyInteger('attempt')->default(0);
            $table->string('worker_id', 120)->nullable();
            // Version tracking for A/B analytics
            $table->string('planner_version', 20)->default('1.0');
            $table->string('compiler_version', 20)->default('1.0');
            $table->string('workflow_version', 20)->default('1.0');
            $table->string('contract_version', 20)->default('1.0');
            // Performance & cost
            $table->unsignedInteger('planning_ms')->nullable();
            $table->unsignedInteger('render_ms')->nullable();
            $table->decimal('cost_usd', 10, 4)->nullable();
            $table->unsignedInteger('token_input')->nullable();
            $table->unsignedInteger('token_output')->nullable();
            // Lifecycle timestamps
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            // Completion artifacts reported by Python worker
            $table->json('outputs')->nullable();
            $table->timestamps();

            $table->index(['status', 'job_type', 'priority']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_jobs');
    }
};
