<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Thu vien prompt chung (khong rieng superyacht). Revision, section va binding
 * da publish la bat bien; model chan sua/xoa, khong dua vao quyen DB.
 * Trang thai luu dang chuoi (khong ENUM) de them trang thai khong can sua bang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_prompt_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('namespace', 60);
            $table->string('key', 100);
            $table->string('name');
            $table->string('document_type', 30);
            $table->text('description')->nullable();
            $table->uuid('active_revision_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['namespace', 'key']);
        });

        Schema::create('ai_prompt_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('document_id');
            $table->string('version', 100);
            $table->string('status', 20)->default('draft');
            $table->string('format', 20);
            $table->longText('source_content');
            $table->char('source_sha256', 64);
            $table->string('source_filename')->nullable();
            $table->string('source_commit', 64)->nullable();
            $table->unsignedInteger('byte_size');
            $table->json('metadata')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('document_id')->references('id')->on('ai_prompt_documents');
            $table->unique(['document_id', 'version']);
            $table->unique(['document_id', 'source_sha256']);
        });

        Schema::create('ai_prompt_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('revision_id');
            $table->uuid('parent_id')->nullable();
            $table->string('section_key', 160);
            $table->string('heading')->nullable();
            $table->unsignedTinyInteger('heading_level')->default(0);
            $table->string('kind', 30);
            $table->unsignedSmallInteger('sort_order');
            $table->unsignedInteger('start_line');
            $table->unsignedInteger('end_line');
            $table->longText('body');
            $table->char('body_sha256', 64);
            $table->unsignedInteger('token_estimate')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('revision_id')->references('id')->on('ai_prompt_revisions');
            $table->unique(['revision_id', 'section_key']);
        });

        Schema::create('ai_prompt_bindings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('consumer_key', 100);
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft');
            $table->string('compiler_version', 40);
            $table->text('description')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['consumer_key', 'version']);
        });

        Schema::create('ai_prompt_binding_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('binding_id');
            $table->uuid('document_id');
            $table->string('section_key', 160);
            $table->string('slot_key', 60);
            $table->string('merge_mode', 10)->default('append');
            $table->boolean('allow_override')->default(false);
            $table->boolean('required')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('binding_id')->references('id')->on('ai_prompt_bindings');
            $table->foreign('document_id')->references('id')->on('ai_prompt_documents');
            $table->unique(['binding_id', 'document_id', 'section_key'], 'ai_prompt_binding_sections_unique');
        });

        Schema::create('ai_prompt_scopes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('scope_type', 20);
            $table->string('scope_key', 100);
            $table->string('name')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['scope_type', 'scope_key']);
        });

        Schema::create('ai_prompt_scope_bindings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('scope_id');
            $table->uuid('binding_id');
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('scope_id')->references('id')->on('ai_prompt_scopes');
            $table->foreign('binding_id')->references('id')->on('ai_prompt_bindings');
            $table->unique(['scope_id', 'binding_id']);
        });

        Schema::create('ai_prompt_run_pins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('execution_type', 40);
            $table->uuid('execution_id');
            $table->uuid('binding_id');
            $table->uuid('document_id');
            $table->uuid('revision_id');
            $table->string('alias', 100)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->char('composition_hash', 64);
            $table->string('compiler_version', 40);
            $table->timestamp('pinned_at');

            $table->foreign('binding_id')->references('id')->on('ai_prompt_bindings');
            $table->foreign('document_id')->references('id')->on('ai_prompt_documents');
            $table->foreign('revision_id')->references('id')->on('ai_prompt_revisions');
            $table->unique(['execution_type', 'execution_id', 'binding_id', 'document_id'], 'ai_prompt_run_pins_execution_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_prompt_run_pins');
        Schema::dropIfExists('ai_prompt_scope_bindings');
        Schema::dropIfExists('ai_prompt_scopes');
        Schema::dropIfExists('ai_prompt_binding_sections');
        Schema::dropIfExists('ai_prompt_bindings');
        Schema::dropIfExists('ai_prompt_sections');
        Schema::dropIfExists('ai_prompt_revisions');
        Schema::dropIfExists('ai_prompt_documents');
    }
};
