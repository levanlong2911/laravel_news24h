<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Table 1: 7 base frameworks ────────────────────────────────────────
        Schema::create('prompt_frameworks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 60)->unique();           // team_sports, individual_sports ...
            $table->string('group_description', 255);
            $table->text('system_prompt');                  // Claude system instruction
            $table->text('phase1_analyze');                 // placeholders: {domain} {audience} {terminology}
            $table->text('phase2_diagnose');                // placeholder: {content_types_block}
            $table->text('phase3_generate');                // placeholders: {tone_notes} {hook_style} {output_schema}
            $table->unsignedSmallInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ── Table 2: Content types per framework (tách khỏi JSON) ────────────
        Schema::create('framework_content_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('framework_id');
            $table->foreign('framework_id')->references('id')->on('prompt_frameworks')->cascadeOnDelete();
            $table->string('type_code', 30);                // victory, defeat, injury, spotlight, trade, drama
            $table->string('type_name', 60);
            $table->json('trigger_keywords');               // ["win","champion","record broken"]
            $table->json('tone_profile');                   // ["cinematic","triumphant","earned"]
            $table->text('structure_template');             // ① HOOK ② JOURNEY ③ PERFORMANCE ...
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['framework_id', 'type_code']);
        });

        // ── Table 3: Category contexts — structured columns (không JSON blob) ─
        Schema::create('category_contexts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('category_id');
            $table->foreign('category_id')->references('id')->on('categories')->cascadeOnDelete();
            $table->uuid('framework_id');
            $table->foreign('framework_id')->references('id')->on('prompt_frameworks');
            $table->string('domain', 60);                   // "NFL", "Formula 1", "Golf"
            $table->text('audience');                       // "NFL fans, sports bettors, fantasy players"
            $table->json('terminology');                    // ["salary cap","draft pick","IR","snap count"]
            $table->text('tone_notes');                     // giọng văn đặc thù của category
            $table->text('hook_style');                     // cách mở bài đặc thù
            $table->json('custom_type_triggers')->nullable(); // override trigger keywords mặc định
            $table->float('performance_score')->default(0);   // avg viral_score của bài generated
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('category_id');
        });

        // ── Table 4: Dynamic output schema per category ───────────────────────
        Schema::create('category_output_fields', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('category_id');
            $table->foreign('category_id')->references('id')->on('categories')->cascadeOnDelete();
            $table->string('field_key', 50);                // title, content, specs_table, disclaimer
            $table->enum('field_type', ['string', 'text', 'array', 'boolean', 'object']);
            $table->boolean('is_required')->default(true);
            $table->text('description');                    // giúp Claude hiểu cần điền gì
            $table->text('example_value')->nullable();      // ví dụ mẫu cho Claude
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['category_id', 'field_key']);
        });

        // ── Table 5: Version history — auto backup khi sửa framework ─────────
        Schema::create('prompt_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('framework_id');
            $table->foreign('framework_id')->references('id')->on('prompt_frameworks')->cascadeOnDelete();
            $table->json('snapshot');                       // full backup toàn bộ framework + content_types
            $table->string('changed_by', 100)->nullable();
            $table->text('change_note')->nullable();
            $table->timestamps();
        });

        // ── Table 6: Performance metrics (simple first, expand later) ─────────
        Schema::create('prompt_metrics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('context_id');
            $table->foreign('context_id')->references('id')->on('category_contexts')->cascadeOnDelete();
            $table->uuid('article_id');
            $table->foreign('article_id')->references('id')->on('articles')->cascadeOnDelete();
            $table->string('content_type_detected', 30)->nullable(); // type_code Phase 2 chọn
            $table->unsignedSmallInteger('viral_score')->default(0); // expand sau: shares, clicks, ctr
            $table->string('model_used', 30)->default('sonnet');
            $table->timestamps();
            // TODO expand: word_count, processing_time_ms, user_engagement, shares, ctr
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_metrics');
        Schema::dropIfExists('prompt_versions');
        Schema::dropIfExists('category_output_fields');
        Schema::dropIfExists('category_contexts');
        Schema::dropIfExists('framework_content_types');
        Schema::dropIfExists('prompt_frameworks');
    }
};
