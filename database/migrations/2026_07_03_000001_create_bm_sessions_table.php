<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bm_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();            // 'sprint3-baseline'
            $table->string('name');                       // 'Sprint 3 Baseline'
            $table->string('sprint');                     // 'sprint3'
            $table->text('description')->nullable();
            $table->foreignId('control_session_id')
                ->nullable()
                ->constrained('bm_sessions')
                ->nullOnDelete();
            $table->char('git_commit', 40)->nullable();   // full project commit hash
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bm_sessions');
    }
};
