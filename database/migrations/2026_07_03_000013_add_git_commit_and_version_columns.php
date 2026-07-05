<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B. git_commit belongs to render (render-level metadata), not to each planner snapshot row.
 * C. planner_version added to registry + snapshot for human-readable provenance.
 */
return new class extends Migration
{
    public function up(): void
    {
        // B: git_commit on render, not on each planner snapshot row
        Schema::table('bm_renders', function (Blueprint $table) {
            $table->char('git_commit', 40)->nullable()->after('artifact_path');
        });

        // C: semantic version on planner registry
        Schema::table('bm_planner_registry', function (Blueprint $table) {
            $table->string('version', 16)->nullable()->after('fingerprint'); // e.g. '2.0', '1.3.1'
        });

        // C: planner_version snapshot + remove git_commit from planner rows
        Schema::table('bm_render_planners', function (Blueprint $table) {
            $table->string('planner_version', 16)->nullable()->after('fingerprint');
            $table->dropColumn('git_commit');
        });
    }

    public function down(): void
    {
        Schema::table('bm_render_planners', function (Blueprint $table) {
            $table->dropColumn('planner_version');
            $table->char('git_commit', 40)->nullable()->after('fingerprint');
        });
        Schema::table('bm_planner_registry', function (Blueprint $table) {
            $table->dropColumn('version');
        });
        Schema::table('bm_renders', function (Blueprint $table) {
            $table->dropColumn('git_commit');
        });
    }
};
