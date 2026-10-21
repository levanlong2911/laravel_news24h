<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_phases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('workflow_code', 64);
            $table->string('phase_code', 64);
            $table->unsignedInteger('revision');
            $table->unique(['workflow_code', 'phase_code', 'revision']);

            $table->string('kind', 24);
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);

            $table->longText('prompt_content');
            $table->char('content_sha256', 64);

            $table->string('needs')->nullable();
            $table->string('parallel_with')->nullable();
            $table->string('stop')->nullable();

            $table->string('status', 12)->default('draft');
            $table->boolean('is_current')->default(false);
            $table->string('current_key', 140)->nullable()
                ->storedAs("IF(is_current, CONCAT(workflow_code, '|', phase_code), NULL)");
            $table->unique('current_key');
            $table->uuid('based_on_id')->nullable();
            $table->foreign('based_on_id')->references('id')->on('ai_phases')->restrictOnDelete();

            $table->string('source_file')->nullable();
            $table->string('source_section_key', 191)->nullable();

            $table->text('change_note')->nullable();
            $table->string('created_by', 100)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->index(['workflow_code', 'sort_order']);
        });

        Schema::create('ai_json_schemas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('schema_key', 64);
            $table->string('version', 32);
            $table->unique(['schema_key', 'version']);

            $table->longText('schema_content');
            $table->char('schema_sha256', 64);

            $table->string('source_file')->nullable();
            $table->string('json_schema_id')->nullable()->index();
            $table->string('json_schema_dialect')->nullable();
            $table->json('dependencies')->nullable();

            $table->string('status', 12)->default('draft');
            $table->text('change_note')->nullable();
            $table->string('created_by', 100)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('published_at')->nullable();
        });

        Schema::create('ai_phase_schemas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('phase_id');
            $table->foreign('phase_id')->references('id')->on('ai_phases')->restrictOnDelete();
            $table->uuid('schema_id');
            $table->foreign('schema_id')->references('id')->on('ai_json_schemas')->restrictOnDelete();

            $table->string('role', 12);
            $table->string('slot_key', 64);
            $table->unique(['phase_id', 'role', 'slot_key']);

            $table->string('data_pointer')->nullable();
            $table->string('validation_mode', 12)->default('whole');
            $table->boolean('is_required')->default(true);
        });

        DB::statement("ALTER TABLE ai_phases ADD CONSTRAINT ai_phases_status_check CHECK (status IN ('draft', 'published', 'retired'))");
        DB::statement("ALTER TABLE ai_phases ADD CONSTRAINT ai_phases_current_published_check CHECK (is_current = 0 OR status = 'published')");
        DB::statement("ALTER TABLE ai_json_schemas ADD CONSTRAINT ai_json_schemas_status_check CHECK (status IN ('draft', 'published', 'retired'))");
        DB::statement("ALTER TABLE ai_phase_schemas ADD CONSTRAINT ai_phase_schemas_role_check CHECK (role IN ('input', 'output', 'lock', 'review'))");
        DB::statement("ALTER TABLE ai_phase_schemas ADD CONSTRAINT ai_phase_schemas_validation_mode_check CHECK (validation_mode IN ('whole', 'each_item'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_phase_schemas');
        Schema::dropIfExists('ai_json_schemas');
        Schema::dropIfExists('ai_phases');
    }
};
