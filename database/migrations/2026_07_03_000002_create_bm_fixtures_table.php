<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bm_fixtures', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();   // 'nfl_quarterback_throw'
            $table->string('name');              // 'NFL Quarterback Throw'
            $table->string('scene_category');   // 'athletic_action'
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bm_fixtures');
    }
};
