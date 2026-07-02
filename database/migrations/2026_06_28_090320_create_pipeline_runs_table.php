<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pipeline_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('video_projects')->cascadeOnDelete();
            // stage: transformation | story | scene_shot | prompt_compiler | scene_graph | thumbnail | publish
            $table->string('stage', 50);
            $table->string('stage_version', 20)->default('1.0');
            $table->string('contract_version', 20)->default('1.0');
            $table->string('workflow_version', 20)->default('1.0');
            // SHA256(input_json + stage_version + contract_version + workflow_version)
            // Same hash = cacheable output — ProviderResolver can skip Claude call
            $table->string('input_hash', 64)->nullable()->index();
            $table->string('output_hash', 64)->nullable();
            $table->json('input_json')->nullable();
            $table->json('output_json')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->decimal('cost_usd', 10, 6)->nullable();
            $table->unsignedInteger('token_input')->nullable();
            $table->unsignedInteger('token_output')->nullable();
            $table->string('status', 20)->default('completed'); // completed | failed | cached
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'stage']);
            $table->index(['stage', 'input_hash']); // cache lookup: stage + hash → hit?
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_runs');
    }
};
