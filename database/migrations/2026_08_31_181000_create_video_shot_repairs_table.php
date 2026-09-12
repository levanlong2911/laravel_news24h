<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('video_shot_repairs')) {
            Schema::create('video_shot_repairs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('video_shot_id');
                $table->uuid('parent_render_execution_id');
                $table->uuid('child_render_execution_id')->nullable();
                $table->string('qa_report_hash', 64);
                $table->string('repair_plan_hash', 64);
                $table->unsignedTinyInteger('repair_index');
                $table->string('failure_signature', 64);
                $table->string('mode', 32);
                $table->string('status', 32);
                $table->json('repair_plan_json');
                $table->timestamps();
            });
        }

        Schema::table('video_shot_repairs', function (Blueprint $table) {
            if (! $this->indexExists('video_shot_repairs_plan_hash_uq')) {
                $table->unique(
                    'repair_plan_hash',
                    'video_shot_repairs_plan_hash_uq',
                );
            }

            if (! $this->indexExists('video_shot_repairs_parent_repair_uq')) {
                $table->unique(
                    ['parent_render_execution_id', 'repair_index'],
                    'video_shot_repairs_parent_repair_uq',
                );
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_shot_repairs');
    }

    private function indexExists(string $name): bool
    {
        return DB::selectOne(
            'SELECT INDEX_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?
             LIMIT 1',
            ['video_shot_repairs', $name],
        ) !== null;
    }
};
