<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bm_instruction_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('render_id')->constrained('bm_renders')->cascadeOnDelete();
            $table->foreignId('catalog_id')->constrained('bm_instruction_catalog');
            $table->string('beat');
            $table->string('variant_text');                  // exact text sent to Kling
            $table->unsignedSmallInteger('char_length');
            $table->unsignedSmallInteger('estimated_token_cost'); // ceil(char_length / 4)

            // Annotation fields — null = not yet annotated
            $table->tinyInteger('observed')->nullable();     // 1=yes, 0=no
            $table->string('confidence')->nullable();        // 'high' | 'medium' | 'low'
            $table->string('annotated_by')->nullable();
            $table->timestamp('annotated_at')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('render_id');
            $table->index(['catalog_id', 'observed']);       // for aggregate analytics
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bm_instruction_instances');
    }
};
