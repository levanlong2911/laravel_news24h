<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Di trú chuỗi dựng dần: `video_shots(kind=chain)` → `video_design_cells`.
 *
 * VÌ SAO CHUYỂN: hall/keel/shell là TRẠNG THÁI HÌNH ẢNH của con tàu, không phải
 * cảnh trong video. Chúng dùng lại được qua nhiều lượt dựng; `motion` thì không.
 * Trạng thái thuộc dự án, chuyển động kể chuyện thuộc lượt chạy.
 *
 * CHUYỂN NGỮ NGHĨA, KHÔNG DỜI HÀNG. 12 hàng `video_shots` GIỮ NGUYÊN làm lịch sử —
 * chúng mang `prompt_sha256` đã trả tiền và dấu vết render thật. Pipeline mới
 * không sinh `kind=chain` nữa; hàng cũ chỉ để tra cứu.
 *
 * ĐIỀU ĐO ĐƯỢC KHI VIẾT MIGRATION NÀY: 6 render có `proves_state` chia thành 3
 * `prompt_sha256`, và mỗi sha xuất hiện ở HAI PROJECT KHÁC NHAU — không phải hai
 * lượt trong cùng một project. Nguyên nhân: prompt chuỗi hôm nay dựng từ câu cố
 * định trong `config/video.php` (`identity.permanent.visual_identity`), không
 * dùng danh tính riêng của project. Nên `UNIQUE(project_id, prompt_sha256)`
 * KHÔNG chặn được ca lặp này — nó chỉ chặn lặp giữa các lượt của CÙNG project.
 * Ghi lại ở đây để người sau khỏi tưởng ràng buộc đó bao hết mọi kiểu lặp.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('video_renders as r')
            ->join('video_shots as s', 's.id', '=', 'r.shot_id')
            ->join('video_sessions as se', 'se.id', '=', 's.session_id')
            ->where('s.kind', 'chain')
            ->whereNotNull('r.proves_state')
            ->whereNotNull('r.artifact_path')
            ->orderBy('se.project_id')
            ->get([
                'se.project_id', 'se.id as session_id', 's.id as shot_id', 's.beat',
                's.shot_code', 's.render_plan',
                'r.id as render_id', 'r.artifact_path', 'r.prompt_sha256', 'r.proves_state',
                'r.provider', 'r.model', 'r.width', 'r.height', 'r.cost_usd', 'r.sent_prompt',
            ]);

        $now = now();

        // Nối chuỗi bằng CHÍNH dữ liệu gốc: `render_plan.source_shot_code` trỏ
        // sang `shot_code` của mắt trước. KHÔNG sắp theo `created_at` — ba shot
        // của một session được tạo trong cùng transaction nên trùng dấu thời gian,
        // và lần chạy đầu đã cho ra chuỗi sai hall → shell → keel.
        $cellIdByShotCode = [];

        foreach ($rows as $row) {
            // Sổ đăng ký file. `sha256` là băm của CHÍNH TỆP, không phải băm prompt —
            // hai thứ khác nhau và nhầm lẫn ở đây sẽ làm mọi phép chống trùng file sai.
            $absolute = public_path(ltrim($row->artifact_path, '/'));
            $artifactId = (string) Str::uuid();

            DB::table('video_artifacts')->insert([
                'id' => $artifactId,
                'project_id' => $row->project_id,
                'session_id' => $row->session_id,
                'shot_id' => $row->shot_id,
                'render_id' => $row->render_id,
                'artifact_type' => 'image',
                'role' => 'construction_state',
                'storage_disk' => 'public',
                'storage_path' => $row->artifact_path,
                'mime_type' => 'image/jpeg',
                'file_size' => is_file($absolute) ? filesize($absolute) : null,
                'sha256' => is_file($absolute) ? hash_file('sha256', $absolute) : str_repeat('0', 64),
                'width' => $row->width,
                'height' => $row->height,
                'metadata_json' => json_encode(['migrated_from' => 'video_shots.kind=chain']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Chuỗi nối bằng CON TRỎ (`source_cell_id`), không bằng `requires_state` —
            // 12 hàng chain gốc đều có `requires_state = NULL`, chúng nối nhau qua
            // `source_shot_code`. Giữ đúng cơ chế đó.
            $cellId = (string) Str::uuid();
            $cellIdByShotCode[$row->project_id.'|'.$row->shot_code] = $cellId;

            DB::table('video_design_cells')->insert([
                'id' => $cellId,
                'project_id' => $row->project_id,
                'cell_code' => $row->beat,                      // hall | keel | shell
                'cell_type' => 'construction_state',
                'state' => 'construction',
                'proves_state' => $row->proves_state,           // hull_preassembly | keel_framework | hull_shell
                'source_cell_id' => null,   // nối ở lượt hai, khi mọi cell đã có id
                'prompt_spec_json' => json_encode(['prompt' => $row->sent_prompt]),
                'prompt_sha256' => $row->prompt_sha256,
                'selected_artifact_id' => $artifactId,
                // `approved` vì chúng ĐÃ render thật và đã tốn tiền — coi là candidate
                // sẽ khiến resolver bỏ qua và bắt render lại đúng cái vừa có.
                'status' => 'approved',
                'revision' => 1,
                'approved_at' => $now,
                'metadata_json' => json_encode(['migrated_from_shot' => $row->shot_id]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('video_artifacts')->where('id', $artifactId)->update(['design_cell_id' => $cellId]);
        }

        // Lượt hai: mắt sau trỏ về mắt trước theo đúng `source_shot_code` đã ghi.
        foreach ($rows as $row) {
            $source = (json_decode($row->render_plan ?? '{}', true))['source_shot_code'] ?? null;

            if ($source === null) {
                continue;
            }

            DB::table('video_design_cells')
                ->where('id', $cellIdByShotCode[$row->project_id.'|'.$row->shot_code])
                ->update(['source_cell_id' => $cellIdByShotCode[$row->project_id.'|'.$source] ?? null]);
        }
    }

    public function down(): void
    {
        DB::table('video_design_cells')->where('cell_type', 'construction_state')->delete();
        DB::table('video_artifacts')->where('role', 'construction_state')->delete();
    }
};
