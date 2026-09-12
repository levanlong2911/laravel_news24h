<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canonical_concept_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('canonical_concept_revision_id');
            $table->unsignedTinyInteger('attempt_number');
            $table->string('attempt_type', 30);
            $table->string('status', 30);
            $table->string('provider', 60);
            $table->string('model', 160);
            $table->string('prompt_version', 80);
            $table->char('input_hash', 64);
            $table->char('schema_hash', 64);
            $table->longText('raw_output')->nullable();
            $table->char('raw_output_hash', 64)->nullable();
            $table->string('provider_request_id', 255)->nullable();
            $table->string('stop_reason', 80)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('thinking_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->text('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['canonical_concept_revision_id', 'attempt_number'],
                'canonical_attempt_revision_number_uq'
            );
            $table->index(
                ['canonical_concept_revision_id', 'attempt_type', 'status'],
                'canonical_attempt_revision_type_status_idx'
            );

            $table
                ->foreign('canonical_concept_revision_id', 'canonical_attempt_revision_fk')
                ->references('id')
                ->on('canonical_concept_revisions')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canonical_concept_attempts');
    }
};
