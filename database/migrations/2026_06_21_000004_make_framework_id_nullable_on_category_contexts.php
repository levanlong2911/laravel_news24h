<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// A category can now be video-pipeline-only (e.g. Archaeology, seeded by
// VideoTopicPromptFrameworkSeeder with no article-writing framework
// configured yet) -- framework_id being required NOT NULL blocked creating
// such a CategoryContext at all. ArticlePipelineService already
// null-coalesces $context?->framework?->contentTypes ?? collect(), so the
// article pipeline already tolerates this safely. Raw SQL because
// Schema::table()->change() requires doctrine/dbal, which isn't installed.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE category_contexts MODIFY framework_id CHAR(36) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE category_contexts MODIFY framework_id CHAR(36) NOT NULL');
    }
};
