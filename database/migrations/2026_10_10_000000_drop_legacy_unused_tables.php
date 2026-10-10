<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const CANONICAL_TABLES = [
        'canonical_validation_runs',
        'canonical_decisions',
        'canonical_concept_events',
        'canonical_concept_attempts',
        'canonical_concept_revisions',
    ];

    /** @var list<string> */
    private const LEGACY_TABLES = [
        'article_facts',
        'bm_fixtures',
        'bm_instruction_catalog',
        'bm_instruction_instances',
        'bm_instruction_stats',
        'bm_planner_outputs',
        'bm_planner_registry',
        'bm_render_planners',
        'bm_render_scores',
        'bm_renders',
        'bm_sessions',
        'claude_request_ledger',
        'feed_items',
        'feed_sources',
        'filmos_benchmark_results',
        'media_jobs',
        'pipeline_runs',
        'rss_items',
        'story_plans',
    ];

    private const SESSION_REVISION_FOREIGN_KEY = 'video_sessions_canonical_revision_fk';

    public function up(): void
    {
        $foreignKey = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'video_sessions')
            ->where('CONSTRAINT_NAME', self::SESSION_REVISION_FOREIGN_KEY)
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists();

        if ($foreignKey) {
            DB::statement('ALTER TABLE video_sessions DROP FOREIGN KEY '.self::SESSION_REVISION_FOREIGN_KEY);
        }

        Schema::disableForeignKeyConstraints();

        try {
            foreach ([...self::CANONICAL_TABLES, ...self::LEGACY_TABLES] as $table) {
                Schema::dropIfExists($table);
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    public function down(): void
    {
        throw new RuntimeException('The legacy tables were dropped without a backup and cannot be restored by a migration.');
    }
};
