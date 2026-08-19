<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Đưa 4 bảng đang chạy về đúng hình dạng của lược đồ mới — CHỈ THÊM, KHÔNG XOÁ.
 *
 * Vì sao không xoá cột nào: `renderplan_json` được đọc ở 21 chỗ VÀ là hợp đồng
 * API với Python (`listComposing()` gửi nó đi, `repairAfterDatabaseRoundTrip()`
 * sửa shape trước khi gửi). `cost_actual`, `planning_claim_token`, `beat` cũng
 * đang gánh việc thật. Xoá bây giờ là hỏng production ngay; xoá đúng lúc là khi
 * code đọc chúng đã chuyển sang bảng mới.
 *
 * Sau migration này lược đồ ĐỦ để luồng mới chạy, còn luồng cũ vẫn nguyên vẹn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_sessions', function (Blueprint $table) {
            $table->string('current_stage', 40)->nullable()->after('status');
            $table->string('pipeline_version', 50)->nullable()->after('current_stage');
            $table->uuid('frozen_render_plan_id')->nullable()->after('renderplan_json');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->json('metadata_json')->nullable();

            $table->foreign('frozen_render_plan_id')->references('id')->on('video_render_plans')->nullOnDelete();
        });

        Schema::table('video_shots', function (Blueprint $table) {
            // Laravel dựng hàng shot từ plan đã đóng băng; Python claim rồi mới
            // biên dịch prompt. `scene_id` là khoá cấu trúc mới, `beat` giữ song
            // song vì resolveTimelineClips() còn keyBy('beat').
            $table->uuid('scene_id')->nullable()->after('session_id');
            $table->unsignedSmallInteger('shot_index')->nullable()->after('scene_id');
            $table->string('viewpoint', 40)->nullable()->after('kind');
            $table->unsignedInteger('duration_ms')->nullable();

            // Bên TIÊU THỤ của hợp đồng trạng thái. Bên sản xuất là
            // video_design_cells.proves_state — khớp CHUỖI CHÍNH XÁC (anchor.py:93).
            $table->string('requires_state', 60)->nullable();

            $table->json('camera_json')->nullable();
            $table->json('motion_json')->nullable();
            $table->json('subject_state_json')->nullable();
            $table->json('environment_state_json')->nullable();
            $table->json('visual_constraints_json')->nullable();
            $table->json('metadata_json')->nullable();

            $table->foreign('scene_id')->references('id')->on('video_render_scenes')->nullOnDelete();
            $table->index(['session_id', 'requires_state']);
        });

        // Shot draft do Laravel tạo CHƯA có prompt — Python điền sau. Cột đang là
        // `text NOT NULL` nên không chèn được hàng draft.
        DB::statement('ALTER TABLE video_shots MODIFY compiled_prompt LONGTEXT NULL');
        DB::statement('ALTER TABLE video_shots MODIFY negative_prompt LONGTEXT NULL');

        Schema::table('video_renders', function (Blueprint $table) {
            // 4 cột chịu lực (idempotency_key, requires_state, proves_state,
            // source_render_id, prompt_sha256) ĐÃ CÓ SẴN — không đụng.
            // UNIQUE(shot_id, idempotency_key) cũng đã có.
            $table->string('job_type', 20)->nullable()->after('model');   // image|video|edit|upscale
            $table->char('source_prompt_sha256', 64)->nullable()->after('prompt_sha256');
            $table->string('provider_request_id', 255)->nullable();
            $table->json('request_json')->nullable();
            $table->json('response_json')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();

            $table->index('prompt_sha256');
        });

        Schema::table('video_finals', function (Blueprint $table) {
            // KHÔNG thêm `manifest_json`: `plan_json` đã chính là manifest đã chốt
            // ("Chốt một lần — retry trả lại đúng plan đã dùng"). Thêm cột thứ hai
            // cùng nghĩa là tự tạo ra câu hỏi cái nào đúng.
            $table->unsignedInteger('revision')->default(1)->after('session_id');
            $table->char('manifest_hash', 64)->nullable()->after('plan_json');
            $table->dateTime('frozen_at')->nullable()->after('manifest_hash');
        });

        Schema::table('video_final_renders', function (Blueprint $table) {
            $table->uuid('artifact_id')->nullable()->after('render_id');
            $table->json('transition_json')->nullable();

            $table->foreign('artifact_id')->references('id')->on('video_artifacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('video_final_renders', function (Blueprint $table) {
            $table->dropForeign(['artifact_id']);
            $table->dropColumn(['artifact_id', 'transition_json']);
        });

        Schema::table('video_finals', function (Blueprint $table) {
            $table->dropColumn(['revision', 'manifest_hash', 'frozen_at']);
        });

        Schema::table('video_renders', function (Blueprint $table) {
            $table->dropIndex(['prompt_sha256']);
            $table->dropColumn([
                'job_type', 'source_prompt_sha256', 'provider_request_id',
                'request_json', 'response_json', 'started_at', 'completed_at',
            ]);
        });

        DB::statement('ALTER TABLE video_shots MODIFY negative_prompt TEXT NULL');
        DB::statement('ALTER TABLE video_shots MODIFY compiled_prompt TEXT NOT NULL');

        Schema::table('video_shots', function (Blueprint $table) {
            $table->dropForeign(['scene_id']);
            $table->dropIndex(['session_id', 'requires_state']);
            $table->dropColumn([
                'scene_id', 'shot_index', 'viewpoint', 'duration_ms', 'requires_state',
                'camera_json', 'motion_json', 'subject_state_json',
                'environment_state_json', 'visual_constraints_json', 'metadata_json',
            ]);
        });

        Schema::table('video_sessions', function (Blueprint $table) {
            $table->dropForeign(['frozen_render_plan_id']);
            $table->dropColumn([
                'current_stage', 'pipeline_version', 'frozen_render_plan_id',
                'started_at', 'finished_at', 'failed_at', 'metadata_json',
            ]);
        });
    }
};
