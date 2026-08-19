<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hợp đồng RenderPlan + tầng vận hành.
 *
 * video_render_plans / video_render_scenes dựng SẴN nhưng CHƯA ai đọc —
 * `video_sessions.renderplan_json` vẫn là nguồn sự thật cho tới khi Python đổi
 * theo. Tách hai việc: tạo bảng ($0, không rủi ro) và đổi hợp đồng API (phải làm
 * đồng thời hai repo).
 *
 * video_review_decisions và video_cost_entries mang CẢ project_id lẫn session_id,
 * đều nullable: duyệt một ô thiết kế là việc cấp dự án, duyệt một shot là việc
 * cấp lượt chạy. Cùng lý do, chi phí ảnh neo thuộc dự án còn chi phí clip thuộc lượt.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Bản kế hoạch đã đóng băng. `frozen_at` khác null ⇒ plan_json bất biến,
        // muốn đổi thì tạo revision mới.
        Schema::create('video_render_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('session_id');
            $table->foreign('session_id')->references('id')->on('video_sessions')->cascadeOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->string('schema_version', 30);
            $table->string('builder_version', 50)->nullable();
            $table->string('status', 20)->default('draft');           // draft|validated|frozen|superseded
            $table->unsignedSmallInteger('scene_count')->default(0);
            $table->string('aspect_ratio', 20)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('target_duration_ms')->nullable();
            $table->json('plan_json');
            $table->char('plan_hash', 64);
            $table->dateTime('validated_at')->nullable();
            $table->dateTime('frozen_at')->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'revision']);
            $table->unique(['session_id', 'plan_hash']);
        });

        Schema::create('video_render_scenes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('render_plan_id');
            $table->foreign('render_plan_id')->references('id')->on('video_render_plans')->cascadeOnDelete();
            $table->unsignedSmallInteger('scene_index');
            $table->string('scene_code', 60);
            $table->string('scene_type', 40)->nullable();
            $table->string('title', 255)->nullable();
            $table->text('purpose')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->uuid('continuity_from_scene_id')->nullable();
            $table->json('state_json')->nullable();
            $table->timestamps();

            $table->foreign('continuity_from_scene_id')->references('id')->on('video_render_scenes')->nullOnDelete();
            $table->unique(['render_plan_id', 'scene_index']);
        });

        // Lịch sử duyệt — CHỈ INSERT, cùng bản chất với video_renders.
        // Hôm nay `video_shots.status` + `review_note` ghi đè mỗi lần, nên render
        // lần 3 được duyệt thì không còn dấu vết vì sao lần 1 và 2 bị từ chối.
        Schema::create('video_review_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('entity_type', 40);                        // design_cell|artifact|reference|shot|render|final
            $table->uuid('entity_id');
            $table->string('decision', 30);                           // approved|rejected|revision_requested
            $table->unsignedInteger('revision')->default(1);
            $table->uuid('reviewer_id')->nullable();
            $table->text('reason')->nullable();                       // vì sao bị từ chối — thứ hôm nay mất ngay khi bấm duyệt
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('video_projects')->nullOnDelete();
            $table->foreign('session_id')->references('id')->on('video_sessions')->nullOnDelete();
            $table->foreign('reviewer_id')->references('id')->on('admins')->nullOnDelete();

            $table->index(['entity_type', 'entity_id']);
        });

        // Sổ cái gộp. Hôm nay tiền nằm ở 4 chỗ — planning_stages.cost_usd,
        // renders.cost_usd, sessions.cost_actual, claude_usage_logs — nên câu hỏi
        // "dự án này tốn tổng bao nhiêu" phải UNION bốn bảng.
        Schema::create('video_cost_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('entity_type', 40)->nullable();            // planning_stage|design_cell|render|audio|publish
            $table->uuid('entity_id')->nullable();
            $table->string('stage', 40)->nullable();
            $table->string('provider', 40);
            $table->string('model', 80)->nullable();
            $table->string('usage_type', 40);                         // token|image|video|api_call|storage
            $table->decimal('quantity', 18, 6)->nullable();
            $table->string('unit', 30)->nullable();
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('video_projects')->nullOnDelete();
            $table->foreign('session_id')->references('id')->on('video_sessions')->nullOnDelete();

            $table->index(['project_id', 'created_at']);
            $table->index(['session_id', 'created_at']);
            $table->index(['provider', 'model']);
        });

        // Nhật ký chuyển trạng thái. Dựng timeline hiện phải tự tay join 4 bảng.
        Schema::create('video_session_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('session_id');
            $table->foreign('session_id')->references('id')->on('video_sessions')->cascadeOnDelete();
            $table->string('event_type', 80);
            $table->string('entity_type', 40)->nullable();
            $table->uuid('entity_id')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'created_at']);
        });

        // Lỗi CÓ CẤU TRÚC, không phải một chuỗi "render failed". `retryable` để
        // Laravel quyết được: thử lại, đổi provider, hay dừng chờ người.
        Schema::create('video_pipeline_failures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('session_id')->nullable();
            $table->string('stage', 40);
            $table->string('entity_type', 40)->nullable();
            $table->uuid('entity_id')->nullable();
            $table->string('provider', 40)->nullable();
            $table->string('error_class', 100)->nullable();
            $table->string('error_code', 100)->nullable();
            $table->boolean('retryable')->default(false);
            $table->text('message')->nullable();
            $table->json('context_json')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('session_id')->references('id')->on('video_sessions')->nullOnDelete();
            $table->index(['session_id', 'created_at']);
        });

        // Fencing token dùng chung. `session_id` có mặt dù bảng là đa hình:
        // không có nó thì không trả lời được "lượt này đang có worker nào giữ việc"
        // nếu chưa biết trước danh sách entity id.
        Schema::create('video_worker_claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('session_id')->nullable();
            $table->string('entity_type', 40);
            $table->uuid('entity_id');
            $table->string('worker_id', 255);
            $table->uuid('claim_token');
            $table->unsignedBigInteger('generation')->default(1);
            $table->dateTime('claimed_at');
            $table->dateTime('lease_expires_at');
            $table->dateTime('released_at')->nullable();
            $table->timestamps();

            $table->foreign('session_id')->references('id')->on('video_sessions')->nullOnDelete();

            $table->unique(['entity_type', 'entity_id', 'generation']);
            $table->index('lease_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_worker_claims');
        Schema::dropIfExists('video_pipeline_failures');
        Schema::dropIfExists('video_session_events');
        Schema::dropIfExists('video_cost_entries');
        Schema::dropIfExists('video_review_decisions');
        Schema::dropIfExists('video_render_scenes');
        Schema::dropIfExists('video_render_plans');
    }
};
