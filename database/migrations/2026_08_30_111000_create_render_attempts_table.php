<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('render_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('render_id');
            $table->unsignedInteger('attempt_no');
            $table->string('status', 40);
            $table->string('provider_key', 80);
            $table->string('model_key', 190);
            $table->char('request_hash', 64);
            $table->char('base_semantic_hash', 64);
            $table->uuid('claim_token');
            $table->unsignedInteger('claim_generation')->default(0);
            $table->string('worker_id', 190);
            $table->string('provider_request_id', 255)->nullable();
            $table->string('failure_class', 80)->nullable();
            $table->string('error_code', 190)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('image_input_tokens')->nullable();
            $table->unsignedBigInteger('text_input_tokens')->nullable();
            $table->decimal('provider_cost_usd', 16, 8)->nullable();
            $table->uuid('cost_entry_id')->nullable();
            $table->json('artifact_manifest')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['render_id', 'attempt_no'], 'render_attempt_no_uq');
            $table->index(['render_id', 'status'], 'render_attempt_status_idx');
            $table->foreign('render_id')->references('id')->on('video_renders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('render_attempts');
    }
};

