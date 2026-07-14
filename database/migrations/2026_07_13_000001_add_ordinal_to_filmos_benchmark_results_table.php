<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C.8A persistence fix: BenchmarkResult::$ordinal is the join identity between
 * benchmark outcomes and narrative knowledge (frozen with C.8A). Without this
 * column the ordinal is lost on save → hydrated as null → OutcomeJoiner skips
 * every persisted row.
 *
 * Additive and nullable: existing rows are legacy (ordinal unknown, unjoinable
 * by design). No backfill, no index — no query pattern needs one yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('filmos_benchmark_results', function (Blueprint $table) {
            $table->unsignedSmallInteger('ordinal')->nullable()->after('goal_id');
        });
    }

    public function down(): void
    {
        Schema::table('filmos_benchmark_results', function (Blueprint $table) {
            $table->dropColumn('ordinal');
        });
    }
};
