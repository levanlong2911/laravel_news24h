<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canonical_concept_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('video_project_id');
            $table->uuid('video_session_id')->nullable();
            $table->unsignedInteger('revision');
            $table->string('status', 40);
            $table->string('object_type', 120);
            $table->string('profile_key', 120);
            $table->string('profile_version', 40);
            $table->string('canonical_schema_version', 40);
            $table->char('effective_schema_hash', 64)->nullable();
            $table->string('concept_model', 160);
            $table->string('concept_prompt_version', 80);
            $table->string('semantic_validator_version', 80)->nullable();
            $table->string('normalizer_version', 80)->nullable();
            $table->string('canonicalizer_version', 80)->nullable();
            $table->longText('canonical_json')->nullable();
            $table->char('canonical_hash', 64)->nullable();
            $table->longText('latest_raw_json')->nullable();
            $table->json('latest_validation_errors')->nullable();
            $table->unsignedTinyInteger('repair_count')->default(0);
            $table->longText('concept_input_json');
            $table->char('concept_input_hash', 64);
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestampTz('frozen_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->text('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestampsTz();

            $table->unique(['video_project_id', 'revision'], 'canonical_revision_project_rev_uq');
            $table->unique(['video_project_id', 'canonical_hash'], 'canonical_revision_project_hash_uq');
            $table->index(['video_project_id', 'status'], 'canonical_revision_project_status_idx');
            $table->index(['video_session_id', 'status'], 'canonical_revision_session_status_idx');
            $table->index('canonical_hash', 'canonical_revision_hash_idx');

            $table->foreign('video_project_id')->references('id')->on('video_projects')->cascadeOnDelete();
            $table->foreign('video_session_id')->references('id')->on('video_sessions')->nullOnDelete();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('canonical_concept_revisions');
    }
};
