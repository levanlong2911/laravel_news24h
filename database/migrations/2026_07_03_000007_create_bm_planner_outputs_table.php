<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bm_planner_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('render_id')->constrained('bm_renders')->cascadeOnDelete();
            $table->foreignId('planner_id')->constrained('bm_planner_registry');
            $table->string('beat');
            $table->text('raw_text');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['render_id', 'planner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bm_planner_outputs');
    }
};
