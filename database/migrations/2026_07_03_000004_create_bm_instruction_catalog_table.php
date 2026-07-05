<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bm_instruction_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();           // 'slow_orbit'
            $table->foreignId('planner_id')->constrained('bm_planner_registry');
            $table->string('category');                  // 'camera' | 'subject' | 'environment' | 'physics' | 'emotion'
            $table->text('description')->nullable();
            $table->string('introduced_in');             // 'sprint1'
            $table->string('deprecated_in')->nullable(); // null = still active
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bm_instruction_catalog');
    }
};
