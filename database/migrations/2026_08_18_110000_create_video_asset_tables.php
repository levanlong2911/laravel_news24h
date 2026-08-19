<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tầng TÀI SẢN THIẾT KẾ — thuộc DỰ ÁN, không thuộc lượt chạy.
 *
 * Vì sao phạm vi là project: đo được trên DB thật — 6 ảnh chain đã render nhưng
 * chỉ có 3 prompt_sha256 khác nhau (f959d36ad3f6 · a12ff2a7265f · 169cc4bd9205),
 * mỗi cái đúng hai lần ở hai session. Một nửa số tiền trả cho ảnh đã có. Ảnh neo
 * mô tả CON TÀU, không mô tả lượt dựng video.
 *
 * Chuỗi bảng: identities (danh tính) → design_cells (ô thiết kế) → artifacts (file)
 * và reference_assets (ảnh dùng cho shot) → shot_references (bảng nối).
 *
 * Trạng thái để `string` kèm chú thích chứ KHÔNG dùng ENUM của MySQL: thêm một
 * giá trị vào ENUM là ALTER TABLE khoá bảng, còn thêm một case vào PHP enum thì
 * không. Đây cũng là quy ước sẵn có của repo (xem VideoShotStatus).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Danh tính thị giác của chủ thể — 14 khe do Sonnet Concept sinh ra.
        // Phải BẤT BIẾN xuyên mọi ảnh, nên nó sống ở project và khoá được.
        Schema::create('video_visual_identities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->foreign('project_id')->references('id')->on('video_projects')->cascadeOnDelete();
            $table->string('identity_type', 40)->default('subject');  // subject|environment|camera
            $table->string('name', 255)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->json('identity_json');                            // design_length_m, visible_deck_tiers, bow_profile...
            $table->char('identity_hash', 64);
            $table->dateTime('locked_at')->nullable();                // khoá rồi thì mọi ảnh sau phải theo
            $table->timestamps();

            $table->unique(['project_id', 'identity_type', 'version']);
        });

        // SỔ ĐĂNG KÝ FILE DUY NHẤT. Không bảng nào khác giữ storage_path —
        // đổi nơi lưu (local → S3) chỉ sửa một chỗ.
        //
        // Sáu khoá ngoại đều nullable vì một artifact có thể thuộc nhiều cấp:
        // ảnh neo thuộc project, khung phim thuộc shot, bản ghép thuộc session.
        Schema::create('video_artifacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->uuid('scene_id')->nullable();
            $table->uuid('shot_id')->nullable();
            $table->uuid('render_id')->nullable();
            $table->uuid('design_cell_id')->nullable();               // FK thêm ở cuối file — vòng tròn với design_cells
            $table->string('artifact_type', 20);                      // image|video|audio|json|text|subtitle|manifest
            $table->string('role', 40)->nullable();
            $table->string('storage_disk', 40)->default('local');
            $table->string('storage_path', 500);
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->char('sha256', 64);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('video_projects')->nullOnDelete();
            $table->foreign('session_id')->references('id')->on('video_sessions')->nullOnDelete();
            $table->foreign('shot_id')->references('id')->on('video_shots')->nullOnDelete();
            $table->foreign('render_id')->references('id')->on('video_renders')->nullOnDelete();

            $table->index(['project_id', 'artifact_type']);
            $table->index('sha256');
        });

        // Ô trong bảng thiết kế: (view × state × representation).
        // CHỨA SLOT, KHÔNG CHỨA FILE — file nằm ở video_artifacts.
        //
        // KHÔNG có requires_state ở đây. Đo trên 12 hàng chain thật: cả 12 đều
        // requires_state = NULL, chuỗi nối nhau bằng source_shot_code. Nên bên
        // SẢN XUẤT trạng thái chỉ cần proves_state + source_cell_id; requires_state
        // thuộc bên TIÊU THỤ là video_shots / video_renders.
        Schema::create('video_design_cells', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('identity_id')->nullable();
            $table->string('cell_code', 60);                          // hall|keel|shell|master_anchor|view_side...
            $table->string('cell_type', 40);                          // construction_state|identity_anchor|view_reference|environment_anchor|camera_anchor|geometry_reference
            $table->string('state', 40)->nullable();                  // blueprint|construction|finished
            $table->unsignedSmallInteger('slot_index')->nullable();
            $table->string('proves_state', 60)->nullable();           // hull_preassembly|keel_framework|hull_shell — khớp CHUỖI CHÍNH XÁC với anchor.py
            $table->uuid('source_cell_id')->nullable();               // mắt này dựng từ ảnh của mắt nào
            $table->json('prompt_spec_json')->nullable();
            $table->char('prompt_sha256', 64)->nullable();
            $table->uuid('selected_artifact_id')->nullable();
            $table->string('status', 20)->default('candidate');       // candidate|approved|superseded
            $table->unsignedInteger('revision')->default(1);
            $table->dateTime('approved_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('video_projects')->cascadeOnDelete();
            $table->foreign('identity_id')->references('id')->on('video_visual_identities')->nullOnDelete();
            $table->foreign('source_cell_id')->references('id')->on('video_design_cells')->nullOnDelete();
            $table->foreign('selected_artifact_id')->references('id')->on('video_artifacts')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('admins')->nullOnDelete();

            // ĐÂY LÀ CHỖ CHẶN TIỀN LẶP: prompt giống nhau thì lần thứ hai là một
            // phép tra, không phải một lần render.
            $table->unique(['project_id', 'prompt_sha256']);
            $table->index(['project_id', 'proves_state', 'status']);
        });

        // Ảnh đã duyệt, sẵn sàng cho shot mượn. Tách khỏi design_cells vì cell là
        // "ô cần gì" còn reference là "ảnh nào đang phục vụ vai trò nào".
        Schema::create('video_reference_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->uuid('identity_id')->nullable();
            $table->uuid('artifact_id');
            $table->string('reference_type', 40);                     // identity|environment|camera|geometry|continuity|style
            $table->string('role', 40)->nullable();
            $table->string('status', 20)->default('active');          // active|superseded
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('video_projects')->cascadeOnDelete();
            $table->foreign('identity_id')->references('id')->on('video_visual_identities')->nullOnDelete();
            $table->foreign('artifact_id')->references('id')->on('video_artifacts')->cascadeOnDelete();

            $table->index(['project_id', 'reference_type', 'status']);
        });

        // Bảng nối shot ↔ ảnh nguồn. KHÔNG thêm reference_1_id/reference_2_id vào
        // video_shots — GPT Image nhận nhiều ảnh với vai trò khác nhau, số lượng
        // không cố định.
        Schema::create('video_shot_references', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shot_id');
            $table->uuid('reference_asset_id');
            $table->string('role', 40);                               // identity_anchor|environment_anchor|geometry_reference|continuity_reference|start_frame
            $table->unsignedSmallInteger('priority')->default(100);
            $table->decimal('weight', 5, 4)->nullable();
            $table->timestamps();

            $table->foreign('shot_id')->references('id')->on('video_shots')->cascadeOnDelete();
            $table->foreign('reference_asset_id')->references('id')->on('video_reference_assets')->cascadeOnDelete();

            $table->unique(['shot_id', 'reference_asset_id', 'role']);
        });

        // Khoá ngoại vòng tròn design_cells ↔ artifacts: thêm sau khi cả hai bảng
        // đã tồn tại. Chiều còn lại (design_cells.selected_artifact_id) đã khai ở trên.
        Schema::table('video_artifacts', function (Blueprint $table) {
            $table->foreign('design_cell_id')->references('id')->on('video_design_cells')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('video_artifacts', function (Blueprint $table) {
            $table->dropForeign(['design_cell_id']);
        });

        Schema::dropIfExists('video_shot_references');
        Schema::dropIfExists('video_reference_assets');
        Schema::dropIfExists('video_design_cells');
        Schema::dropIfExists('video_artifacts');
        Schema::dropIfExists('video_visual_identities');
    }
};
