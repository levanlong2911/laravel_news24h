<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add the simple index FIRST so the FK on session_id stays covered
        // when we drop the compound index below.
        Schema::table('bm_renders', function (Blueprint $table) {
            $table->index('session_id');
        });

        Schema::table('bm_renders', function (Blueprint $table) {
            $table->dropIndex(['session_id', 'scene_category']);
            $table->dropColumn('scene_category');
        });
    }

    public function down(): void
    {
        Schema::table('bm_renders', function (Blueprint $table) {
            $table->dropIndex(['session_id']);
            $table->string('scene_category')->after('fixture_id')->default('');
            $table->index(['session_id', 'scene_category']);
        });
    }
};
