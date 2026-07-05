<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bm_planner_registry', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();       // 'CameraMotivationPlanner'
            $table->string('file_path');             // relative to app_path(), for re-fingerprinting
            $table->char('fingerprint', 64)->nullable(); // sha256 of file at seed time
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bm_planner_registry');
    }
};
