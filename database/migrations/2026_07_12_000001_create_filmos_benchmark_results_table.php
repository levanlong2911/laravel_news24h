<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('filmos_benchmark_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('schema_version')->default(1);

            // Tracing & identity
            $table->string('trace_id');
            $table->string('provider', 100);
            $table->string('planner_name', 100);
            $table->string('goal_id', 100);

            // Provider result fields (flattened from ProviderResult)
            $table->string('request_id')->default('');
            $table->text('asset_url')->default('');
            $table->decimal('duration', 10, 4)->default(0); // rendered video seconds

            // Benchmark metrics
            $table->decimal('cost', 10, 4)->default(0);
            $table->decimal('latency_seconds', 10, 4)->default(0);
            $table->decimal('quality_score', 5, 4)->default(0); // 0.0–1.0, human-annotated in C.6
            $table->decimal('roi', 10, 4)->default(0);          // quality / cost, computed in C.6
            $table->decimal('score', 5, 4)->default(0);          // 0.0–1.0, overall, computed in C.6

            // Overflow for arbitrary extra fields (BenchmarkResult::$attributes)
            $table->json('attributes')->nullable();

            // Append-only — no updated_at
            $table->timestamp('created_at')->useCurrent();

            // Query patterns for C.6 learning
            $table->index('trace_id');
            $table->index(['provider', 'created_at']);
            $table->index('goal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('filmos_benchmark_results');
    }
};
