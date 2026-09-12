<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('render_qa_findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('qa_run_id')->index();
            $table->string('target_id', 100);
            $table->string('semantic_key', 500);
            $table->string('primitive', 80);
            $table->unsignedSmallInteger('priority');
            $table->string('severity', 20);
            $table->string('status', 40);
            $table->decimal('confidence', 5, 4);
            $table->json('expected_value')->nullable();
            $table->json('observed_value')->nullable();
            $table->text('evidence')->nullable();
            $table->string('failure_type', 80)->nullable();
            $table->json('source_constraint_ids');
            $table->json('source_instruction_ids');
            $table->json('source_paths');
            $table->timestampsTz();

            $table->unique(['qa_run_id', 'target_id'], 'qa_target_uq');
            $table->foreign('qa_run_id')
                ->references('id')
                ->on('render_qa_runs')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('render_qa_findings');
    }
};
