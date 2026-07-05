<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Materialized statistics table for instruction analytics.
 *
 * Incremented at annotation time — never recomputed from scratch.
 * benchmark:stats uses this table instead of aggregating instruction_instances.
 *
 * Keyed by (catalog_id, scene_category, beat) to support fine-grained
 * queries: "slow_orbit success rate in athletic_action at payoff beat".
 *
 * scene_category is intentionally denormalized here (stats table) for
 * query performance — this is different from bm_renders where it was
 * redundant with fixture_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bm_instruction_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_id')->constrained('bm_instruction_catalog');
            $table->string('scene_category', 64);
            $table->string('beat', 32);

            $table->unsignedInteger('attempts')->default(0);  // annotated instances
            $table->unsignedInteger('successes')->default(0); // observed = 1
            $table->unsignedBigInteger('total_char_length')->default(0);
            $table->unsignedBigInteger('total_token_cost')->default(0);

            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['catalog_id', 'scene_category', 'beat']);
            $table->index(['catalog_id', 'attempts']); // for ROI sort
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bm_instruction_stats');
    }
};
