<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> workflow_code => category slug */
    private const WORKFLOW_CATEGORIES = ['superyacht_v6_3' => 'yacht'];

    private const SCHEMA_NAMESPACE = 'superyacht';

    private const SCHEMA_BASE_URI = 'https://superyacht.local/schemas/';

    public function up(): void
    {
        $categories = $this->categoriesOfExistingPhases();
        $this->assertSchemasBelongToNamespace();

        Schema::table('ai_phases', function (Blueprint $table) {
            $table->uuid('category_id')->nullable()->after('id');
        });

        foreach ($categories as $workflow => $categoryId) {
            DB::table('ai_phases')->where('workflow_code', $workflow)->update(['category_id' => $categoryId]);
        }

        if (DB::table('ai_phases')->whereNull('category_id')->exists()) {
            throw new RuntimeException('ai_phases still has rows without a category after the backfill.');
        }

        DB::statement('ALTER TABLE ai_phases MODIFY category_id CHAR(36) NOT NULL');

        Schema::table('ai_phases', function (Blueprint $table) {
            $table->dropUnique(['workflow_code', 'phase_code', 'revision']);
            $table->unique(['category_id', 'workflow_code', 'phase_code', 'revision']);
            $table->foreign('category_id')->references('id')->on('categories')->restrictOnDelete();
            $table->dropIndex(['workflow_code', 'sort_order']);
            $table->index(['category_id', 'workflow_code', 'sort_order']);
            $table->dropUnique(['current_key']);
        });

        Schema::table('ai_phases', function (Blueprint $table) {
            $table->dropColumn('current_key');
        });

        Schema::table('ai_phases', function (Blueprint $table) {
            $table->string('current_key', 170)->nullable()
                ->storedAs("IF(is_current, CONCAT(category_id, '|', workflow_code, '|', phase_code), NULL)")
                ->after('is_current');
            $table->unique('current_key');
        });

        Schema::table('ai_json_schemas', function (Blueprint $table) {
            $table->string('namespace', 64)->nullable()->after('id');
        });

        DB::table('ai_json_schemas')->update(['namespace' => self::SCHEMA_NAMESPACE]);
        DB::statement('ALTER TABLE ai_json_schemas MODIFY namespace VARCHAR(64) NOT NULL');

        Schema::table('ai_json_schemas', function (Blueprint $table) {
            $table->dropUnique(['schema_key', 'version']);
            $table->unique(['namespace', 'schema_key', 'version']);
        });
    }

    public function down(): void
    {
        $this->assertRestorableWithoutCategoryAndNamespace();

        Schema::table('ai_json_schemas', function (Blueprint $table) {
            $table->dropUnique(['namespace', 'schema_key', 'version']);
            $table->unique(['schema_key', 'version']);
            $table->dropColumn('namespace');
        });

        Schema::table('ai_phases', function (Blueprint $table) {
            $table->dropUnique(['current_key']);
        });

        Schema::table('ai_phases', function (Blueprint $table) {
            $table->dropColumn('current_key');
        });

        Schema::table('ai_phases', function (Blueprint $table) {
            $table->string('current_key', 140)->nullable()
                ->storedAs("IF(is_current, CONCAT(workflow_code, '|', phase_code), NULL)")
                ->after('is_current');
            $table->unique('current_key');
            $table->dropForeign(['category_id']);
            $table->dropUnique(['category_id', 'workflow_code', 'phase_code', 'revision']);
            $table->unique(['workflow_code', 'phase_code', 'revision']);
            $table->dropIndex(['category_id', 'workflow_code', 'sort_order']);
            $table->index(['workflow_code', 'sort_order']);
            $table->dropColumn('category_id');
        });
    }

    private function assertRestorableWithoutCategoryAndNamespace(): void
    {
        $collisions = [];
        $checks = [
            'ai_json_schemas (schema_key, version)' => DB::table('ai_json_schemas')
                ->select('schema_key', 'version')->groupBy('schema_key', 'version')->havingRaw('COUNT(*) > 1')->get()
                ->map(fn ($row) => "{$row->schema_key}@{$row->version}"),
            'ai_phases (workflow_code, phase_code, revision)' => DB::table('ai_phases')
                ->select('workflow_code', 'phase_code', 'revision')->groupBy('workflow_code', 'phase_code', 'revision')->havingRaw('COUNT(*) > 1')->get()
                ->map(fn ($row) => "{$row->workflow_code}/{$row->phase_code}#{$row->revision}"),
            'ai_phases current (workflow_code, phase_code)' => DB::table('ai_phases')->where('is_current', true)
                ->select('workflow_code', 'phase_code')->groupBy('workflow_code', 'phase_code')->havingRaw('COUNT(*) > 1')->get()
                ->map(fn ($row) => "{$row->workflow_code}/{$row->phase_code}"),
        ];

        foreach ($checks as $key => $rows) {
            if ($rows->isNotEmpty()) {
                $collisions[] = $key.': '.$rows->take(5)->implode(', ').($rows->count() > 5 ? ' …' : '');
            }
        }

        if ($collisions !== []) {
            throw new RuntimeException('Rollback refused: rows of several categories or namespaces would break the old unique keys. Nothing was changed. '.implode('; ', $collisions));
        }
    }

    /** @return array<string, string> workflow_code => category id */
    private function categoriesOfExistingPhases(): array
    {
        $categories = [];

        foreach (DB::table('ai_phases')->distinct()->pluck('workflow_code') as $workflow) {
            $slug = self::WORKFLOW_CATEGORIES[$workflow]
                ?? throw new RuntimeException("ai_phases holds workflow {$workflow}, which has no category mapping. Nothing was changed.");
            $ids = DB::table('categories')->where('slug', $slug)->pluck('id');

            if ($ids->count() !== 1) {
                throw new RuntimeException("Category slug {$slug} matches {$ids->count()} rows instead of exactly one. Nothing was changed.");
            }

            $categories[$workflow] = (string) $ids->first();
        }

        return $categories;
    }

    private function assertSchemasBelongToNamespace(): void
    {
        $foreign = DB::table('ai_json_schemas')
            ->where(fn ($query) => $query->whereNull('json_schema_id')->orWhere('json_schema_id', 'not like', self::SCHEMA_BASE_URI.'%'))
            ->pluck('schema_key');

        if ($foreign->isNotEmpty()) {
            throw new RuntimeException('Schemas outside '.self::SCHEMA_BASE_URI.' cannot be assigned to namespace '.self::SCHEMA_NAMESPACE.': '.$foreign->implode(', ').'. Nothing was changed.');
        }
    }
};
