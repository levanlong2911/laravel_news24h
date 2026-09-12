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
        $this->dropCheckConstraintIfExists('video_renders_one_owner');

        Schema::table('video_renders', function (Blueprint $table): void {
            if (! Schema::hasColumn('video_renders', 'video_session_id')) {
                $table->uuid('video_session_id')->nullable()->after('design_image_id');
            }

            if (! Schema::hasColumn('video_renders', 'asset_id')) {
                $table->string('asset_id', 190)->nullable()->after('video_session_id');
            }

            if (! Schema::hasColumn('video_renders', 'request_hash')) {
                $table->char('request_hash', 64)->nullable()->index();
            }

            if (! Schema::hasColumn('video_renders', 'render_request_json')) {
                $table->longText('render_request_json')->nullable();
            }

            if (! Schema::hasColumn('video_renders', 'execution_status')) {
                $table->string('execution_status', 40)->default('queued')->index();
            }

            if (! Schema::hasColumn('video_renders', 'claim_token')) {
                $table->uuid('claim_token')->nullable()->unique();
            }

            if (! Schema::hasColumn('video_renders', 'claim_generation')) {
                $table->unsignedInteger('claim_generation')->default(0);
            }

            if (! Schema::hasColumn('video_renders', 'claimed_by')) {
                $table->string('claimed_by', 190)->nullable();
            }

            if (! Schema::hasColumn('video_renders', 'claimed_at')) {
                $table->timestampTz('claimed_at')->nullable();
            }

            if (! Schema::hasColumn('video_renders', 'lease_expires_at')) {
                $table->timestampTz('lease_expires_at')->nullable()->index();
            }

            if (! Schema::hasColumn('video_renders', 'heartbeat_at')) {
                $table->timestampTz('heartbeat_at')->nullable();
            }

            if (! Schema::hasColumn('video_renders', 'attempt_count')) {
                $table->unsignedInteger('attempt_count')->default(0);
            }

            if (! Schema::hasColumn('video_renders', 'max_attempts')) {
                $table->unsignedInteger('max_attempts')->default(3);
            }

            if (! Schema::hasColumn('video_renders', 'next_retry_at')) {
                $table->timestampTz('next_retry_at')->nullable()->index();
            }

            if (! Schema::hasColumn('video_renders', 'canonical_concept_revision_id')) {
                $table->uuid('canonical_concept_revision_id')->nullable();
            }

            if (! Schema::hasColumn('video_renders', 'canonical_hash')) {
                $table->char('canonical_hash', 64)->nullable();
            }

            if (! Schema::hasColumn('video_renders', 'projection_hash')) {
                $table->char('projection_hash', 64)->nullable();
            }

            if (! Schema::hasColumn('video_renders', 'constraint_set_hash')) {
                $table->char('constraint_set_hash', 64)->nullable();
            }

            if (! Schema::hasColumn('video_renders', 'prompt_spec_hash')) {
                $table->char('prompt_spec_hash', 64)->nullable();
            }

            if (! Schema::hasColumn('video_renders', 'provider_prompt_plan_hash')) {
                $table->char('provider_prompt_plan_hash', 64)->nullable();
            }

            if (! Schema::hasColumn('video_renders', 'prompt_hash')) {
                $table->char('prompt_hash', 64)->nullable();
            }

            if (! Schema::hasColumn('video_renders', 'artifact_manifest')) {
                $table->json('artifact_manifest')->nullable();
            }

            if (! Schema::hasColumn('video_renders', 'primary_artifact_hash')) {
                $table->char('primary_artifact_hash', 64)->nullable();
            }

            if (! Schema::hasColumn('video_renders', 'failure_class')) {
                $table->string('failure_class', 80)->nullable();
            }

            if (! Schema::hasColumn('video_renders', 'failure_code')) {
                $table->string('failure_code', 190)->nullable();
            }

            if (! Schema::hasColumn('video_renders', 'failure_message')) {
                $table->text('failure_message')->nullable();
            }

            if (! Schema::hasColumn('video_renders', 'execution_version')) {
                $table->unsignedInteger('execution_version')->default(0);
            }

            if (! Schema::hasColumn('video_renders', 'execution_started_at')) {
                $table->timestampTz('execution_started_at')->nullable();
            }

            if (! Schema::hasColumn('video_renders', 'execution_completed_at')) {
                $table->timestampTz('execution_completed_at')->nullable();
            }
        });

        if (! Schema::hasColumn('video_renders', 'idempotency_key')) {
            return;
        }

        DB::statement('ALTER TABLE video_renders MODIFY idempotency_key VARCHAR(190) NULL');

        Schema::table('video_renders', function (Blueprint $table): void {
            if (! $this->indexExists('video_render_session_idempotency_uq')) {
                $table->unique(['video_session_id', 'idempotency_key'], 'video_render_session_idempotency_uq');
            }

            if (! $this->foreignKeyExists('video_renders_video_session_id_foreign')) {
                $table->foreign('video_session_id')->references('id')->on('video_sessions')->nullOnDelete();
            }
        });

        DB::statement("ALTER TABLE video_renders ADD CONSTRAINT video_renders_one_owner
            CHECK (
                (
                    (shot_id IS NOT NULL)
                    + (design_image_id IS NOT NULL)
                    + (video_session_id IS NOT NULL) = 1
                )
                OR (
                    shot_id IS NULL
                    AND design_image_id IS NULL
                    AND video_session_id IS NULL
                    AND created_at < '2026-08-30 11:00:00'
                )
            )");
    }

    public function down(): void
    {
        $this->dropCheckConstraintIfExists('video_renders_one_owner');
        DB::statement('ALTER TABLE video_renders ADD CONSTRAINT video_renders_one_owner
            CHECK ((shot_id IS NULL) <> (design_image_id IS NULL))');

        Schema::table('video_renders', function (Blueprint $table): void {
            if ($this->indexExists('video_render_session_idempotency_uq')) {
                $table->dropUnique('video_render_session_idempotency_uq');
            }

            if ($this->foreignKeyExists('video_renders_video_session_id_foreign')) {
                $table->dropForeign(['video_session_id']);
            }

            $columns = [
                'video_session_id',
                'asset_id',
                'request_hash',
                'render_request_json',
                'execution_status',
                'claim_token',
                'claim_generation',
                'claimed_by',
                'claimed_at',
                'lease_expires_at',
                'heartbeat_at',
                'attempt_count',
                'max_attempts',
                'next_retry_at',
                'canonical_concept_revision_id',
                'canonical_hash',
                'projection_hash',
                'constraint_set_hash',
                'prompt_spec_hash',
                'provider_prompt_plan_hash',
                'prompt_hash',
                'artifact_manifest',
                'primary_artifact_hash',
                'failure_class',
                'failure_code',
                'failure_message',
                'execution_version',
                'execution_started_at',
                'execution_completed_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('video_renders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function dropCheckConstraintIfExists(string $name): void
    {
        if (! $this->checkConstraintExists($name)) {
            return;
        }

        DB::statement("ALTER TABLE video_renders DROP CONSTRAINT {$name}");
    }

    private function checkConstraintExists(string $name): bool
    {
        return DB::selectOne(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.CHECK_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND CONSTRAINT_NAME = ?',
            [$name],
        ) !== null;
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
            ['video_renders', $name],
        ) !== null;
    }

    private function foreignKeyExists(string $name): bool
    {
        return DB::selectOne(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
             LIMIT 1',
            ['video_renders', $name],
        ) !== null;
    }
};
