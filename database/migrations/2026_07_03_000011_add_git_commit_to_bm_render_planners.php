<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bm_render_planners', function (Blueprint $table) {
            $table->char('git_commit', 40)->nullable()->after('fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('bm_render_planners', function (Blueprint $table) {
            $table->dropColumn('git_commit');
        });
    }
};
