<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Quy mọi prompt về dấu xuống dòng LF.
 *
 * Đánh số 000000 là có chủ ý: phải chạy TRƯỚC 000004–000007. Bốn migration đó
 * sửa prompt bằng str_replace nhiều dòng, và một hàng dùng CRLF thì không chuỗi
 * nhiều dòng nào khớp được — chúng sẽ lặng lẽ bỏ qua đúng hàng đó rồi báo thành
 * công. Chạy sau thì normalize xong cũng vô nghĩa, patch đã trượt mất rồi.
 *
 * ── Vì sao có hàng CRLF ─────────────────────────────────────────────────────
 *
 * Đo trên DB 2026-08-04: 12/13 framework dùng LF thuần, riêng travel_mobility
 * có 150 CRLF / 44 LF. Nó là framework đã được sửa qua form admin.
 *
 * Theo đặc tả HTML, <textarea> luôn gửi lên dạng CRLF. Không có chỗ nào trong
 * đường lưu chuẩn hoá lại, nên mỗi lần admin sửa một framework là framework đó
 * chuyển sang CRLF và từ đó miễn nhiễm với mọi migration khớp chuỗi.
 *
 * Migration này dọn hiện trạng. Chặn tái phát là việc của
 * PromptFrameworkObserver::saving() — thiếu một trong hai thì bệnh quay lại.
 *
 * ── Phạm vi ─────────────────────────────────────────────────────────────────
 *
 * Chỉ các cột đang là đích của str_replace nhiều dòng:
 *   prompt_frameworks         system_prompt, phase1_analyze, phase2_diagnose,
 *                             phase3_generate
 *   framework_content_types   structure_template
 *
 * category_contexts.tone_notes / hook_style cố ý không đụng: chúng là văn bản
 * một dòng, chưa migration nào khớp chuỗi vào đó.
 */
return new class extends Migration
{
    private const TARGETS = [
        'prompt_frameworks'       => ['system_prompt', 'phase1_analyze', 'phase2_diagnose', 'phase3_generate'],
        'framework_content_types' => ['structure_template'],
    ];

    public function up(): void
    {
        foreach (self::TARGETS as $table => $columns) {
            $touched = 0;

            foreach (DB::table($table)->get() as $row) {
                $changes = [];

                foreach ($columns as $column) {
                    $value = $row->{$column};

                    if (!is_string($value)) {
                        continue;
                    }

                    // "\r\n" trước rồi "\r" lẻ — đảo thứ tự sẽ biến CRLF thành hai LF.
                    $normalized = str_replace(["\r\n", "\r"], "\n", $value);

                    if ($normalized !== $value) {
                        $changes[$column] = $normalized;
                    }
                }

                if ($changes) {
                    DB::table($table)->where('id', $row->id)->update($changes);
                    $touched++;
                }
            }

            echo "  {$table}: đã chuẩn hoá {$touched} bản ghi\n";
        }
    }

    /**
     * Không đảo ngược được — và cũng không nên.
     *
     * Sau khi normalize, không còn cách nào biết hàng nào vốn dùng CRLF. Đổi
     * ngược toàn bộ về CRLF sẽ phá đúng những hàng vốn đã đúng, và làm mọi
     * migration sau đó trượt lần nữa. LF là trạng thái đúng, không phải một
     * lựa chọn để lùi lại.
     */
    public function down(): void
    {
        echo "  normalize line endings: không đảo ngược (xem docblock)\n";
    }
};
