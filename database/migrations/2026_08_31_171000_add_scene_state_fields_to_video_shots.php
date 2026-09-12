<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_shots', function (Blueprint $table) {
            if (! Schema::hasColumn('video_shots', 'scene_id')) {
                $table->string('scene_id', 120)->nullable()->index();
            }

            if (! Schema::hasColumn('video_shots', 'scene_sequence_index')) {
                $table->unsignedInteger('scene_sequence_index')->nullable()->index();
            }

            if (! Schema::hasColumn('video_shots', 'from_state_id')) {
                $table->string('from_state_id', 120)->nullable();
            }

            if (! Schema::hasColumn('video_shots', 'to_state_id')) {
                $table->string('to_state_id', 120)->nullable();
            }

            if (! Schema::hasColumn('video_shots', 'transition_id')) {
                $table->string('transition_id', 120)->nullable();
            }

            if (! Schema::hasColumn('video_shots', 'scene_status')) {
                $table->string('scene_status', 40)->nullable()->index();
            }

            if (! Schema::hasColumn('video_shots', 'scene_image_render_id')) {
                $table->uuid('scene_image_render_id')->nullable()->index();
            }

            if (! Schema::hasColumn('video_shots', 'video_render_id')) {
                $table->uuid('video_render_id')->nullable()->index();
            }

            if (! Schema::hasColumn('video_shots', 'scene_projection_hash')) {
                $table->char('scene_projection_hash', 64)->nullable();
            }

            if (! Schema::hasColumn('video_shots', 'motion_spec_hash')) {
                $table->char('motion_spec_hash', 64)->nullable();
            }

            if (! Schema::hasColumn('video_shots', 'state_committed_at')) {
                $table->timestamp('state_committed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('video_shots', function (Blueprint $table) {
            $columns = [
                'scene_id',
                'scene_sequence_index',
                'from_state_id',
                'to_state_id',
                'transition_id',
                'scene_status',
                'scene_image_render_id',
                'video_render_id',
                'scene_projection_hash',
                'motion_spec_hash',
                'state_committed_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('video_shots', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
