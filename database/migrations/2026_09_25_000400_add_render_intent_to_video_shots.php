<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_UNIQUE = 'video_shots_session_id_shot_code_kind_unique';

    private const REVISION_UNIQUE = 'video_shots_session_revision_code_kind_unique';

    public function up(): void
    {
        Schema::table('video_shots', function (Blueprint $table): void {
            $table->uuid('current_render_id')->nullable()->after('video_render_id');
            $table->unsignedInteger('intent_version')->default(0)->after('current_render_id');
            $table->unsignedInteger('auto_select_version')->nullable()->after('intent_version');

            $table->foreign('current_render_id', 'video_shots_current_render_fk')
                ->references('id')->on('video_renders')->nullOnDelete();
            $table->dropUnique(self::LEGACY_UNIQUE);
            $table->unique(
                ['session_id', 'plan_revision', 'shot_code', 'kind'],
                self::REVISION_UNIQUE,
            );
        });
    }

    public function down(): void
    {
        $duplicate = DB::table('video_shots')
            ->select(['session_id', 'shot_code', 'kind'])
            ->groupBy(['session_id', 'shot_code', 'kind'])
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException(
                'Cannot restore the legacy video_shots unique key while multiple plan revisions exist.'
            );
        }

        Schema::table('video_shots', function (Blueprint $table): void {
            $table->dropForeign('video_shots_current_render_fk');
            $table->dropUnique(self::REVISION_UNIQUE);
            $table->unique(['session_id', 'shot_code', 'kind'], self::LEGACY_UNIQUE);
            $table->dropColumn(['current_render_id', 'intent_version', 'auto_select_version']);
        });
    }
};
