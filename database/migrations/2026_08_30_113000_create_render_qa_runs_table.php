<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('render_qa_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('render_id')->index();
            $table->unsignedInteger('qa_generation');
            $table->string('status', 40);
            $table->string('decision', 40)->nullable();
            $table->string('repair_decision', 40)->nullable();
            $table->uuid('canonical_revision_id');
            $table->char('canonical_hash', 64);
            $table->char('constraint_set_hash', 64);
            $table->char('prompt_spec_hash', 64);
            $table->char('request_hash', 64);
            $table->string('artifact_id', 190);
            $table->char('artifact_hash', 64);
            $table->string('vision_provider', 80);
            $table->string('vision_model', 190);
            $table->char('qa_report_hash', 64)->nullable();
            $table->unsignedInteger('hard_fail_count')->default(0);
            $table->unsignedInteger('soft_fail_count')->default(0);
            $table->unsignedInteger('uncertain_count')->default(0);
            $table->unsignedInteger('not_visible_count')->default(0);
            $table->json('report_json')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['render_id', 'qa_generation'], 'render_qa_generation_uq');
            $table->foreign('render_id')
                ->references('id')
                ->on('video_renders')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('render_qa_runs');
    }
};
