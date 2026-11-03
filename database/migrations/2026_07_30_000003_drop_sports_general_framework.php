<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Xoá hẳn framework 'sports_general'.
 *
 * Migration 000002 mới tắt cờ is_active. Bản ghi vẫn nằm đó cùng 5 content type
 * và 1 version snapshot, không ai dùng, chỉ chờ có người bật lại nhầm.
 *
 * Nó không do PromptSystemSeeder tạo (bản ghi đời đầu 2026-04-16 còn sót), nên
 * máy cài mới vốn không có → migration tự no-op.
 *
 * Cascade theo FK:
 *   framework_content_types  ON DELETE CASCADE  → 5 bản ghi
 *   prompt_versions          ON DELETE CASCADE  → 1 snapshot
 *   category_contexts        ON DELETE RESTRICT → chặn nếu còn ai tham chiếu
 *
 * KHÔNG đảo ngược được — xem down().
 */
return new class extends Migration
{
    private const NAME = 'sports_general';

    public function up(): void
    {
        $id = DB::table('prompt_frameworks')->where('name', self::NAME)->value('id');

        if (!$id) {
            echo '  ' . self::NAME . " không tồn tại — bỏ qua\n";
            return;
        }

        // FK là RESTRICT nên DB sẽ tự chặn, nhưng báo lỗi của MySQL không nói được
        // category nào đang vướng. Kiểm tra trước để thông báo có ích hơn.
        $inUse = DB::table('category_contexts')->where('framework_id', $id)->count();

        if ($inUse > 0) {
            throw new RuntimeException(sprintf(
                '%s vẫn còn %d category_context tham chiếu — trỏ chúng sang framework khác trước khi xoá.',
                self::NAME,
                $inUse,
            ));
        }

        $types    = DB::table('framework_content_types')->where('framework_id', $id)->count();
        $versions = DB::table('prompt_versions')->where('framework_id', $id)->count();

        DB::table('prompt_frameworks')->where('id', $id)->delete();

        echo '  đã xoá ' . self::NAME . " ({$id})\n";
        echo "  cascade: {$types} content type, {$versions} version snapshot\n";
    }

    public function down(): void
    {
        // Cố tình không khôi phục. Đây là bản ghi rác đời đầu — phase3 chỉ 218 ký tự,
        // thiếu toàn bộ mục FB, và vì là framework active cũ nhất nên buildFallback()
        // luôn chọn nó. Dựng lại đồng nghĩa dựng lại đúng cái lỗi vừa dọn.
        throw new RuntimeException(
            'Không khôi phục được ' . self::NAME . ' — migration này một chiều. '
            . 'Cần lại thì tạo framework mới qua admin UI với phase3 đầy đủ.'
        );
    }
};
