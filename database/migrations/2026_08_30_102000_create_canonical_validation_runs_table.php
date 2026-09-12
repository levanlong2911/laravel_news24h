<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canonical_validation_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('canonical_concept_revision_id');
            $table->uuid('canonical_concept_attempt_id')->nullable();
            $table->string('stage', 60);
            $table->string('status', 30);
            $table->json('errors')->nullable();
            $table->unsignedInteger('error_count')->default(0);
            $table->char('document_hash', 64);
            $table->string('validator_version', 80);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestampTz('validated_at');
            $table->timestampsTz();

            $table->index(
                ['canonical_concept_revision_id', 'stage', 'status'],
                'canonical_validation_revision_stage_status_idx'
            );

            $table
                ->foreign('canonical_concept_revision_id', 'canonical_validation_revision_fk')
                ->references('id')
                ->on('canonical_concept_revisions')
                ->cascadeOnDelete();

            $table
                ->foreign('canonical_concept_attempt_id', 'canonical_validation_attempt_fk')
                ->references('id')
                ->on('canonical_concept_attempts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canonical_validation_runs');
    }
};
