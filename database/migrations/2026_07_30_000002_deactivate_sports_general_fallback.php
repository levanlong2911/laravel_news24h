<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ngừng dùng framework 'sports_general'.
 *
 * Nó không do PromptSystemSeeder tạo — là bản ghi đời đầu (2026-04-16) còn sót,
 * và vì cũ nhất nên PromptBuilderService::buildFallback() luôn chọn đúng nó:
 *
 *     PromptFramework::where('is_active', true)->orderBy('created_at')->first()
 *
 * Tức mọi category chưa cấu hình CategoryContext đều rơi vào phase3 dài 218 ký tự
 * của nó — không FB_IMAGE_TEXT, không FB_POST_CONTENT, không hook type, không
 * quality gate. Trong khi FB_SCHEMA_APPEND vẫn bắt Claude trả về fb_image_text và
 * fb_post_content, nên hai field đó được bịa hoàn toàn tự do.
 *
 * Category duy nhất trỏ tới nó đã bị xoá, giờ 0 context tham chiếu. Tắt cờ để
 * fallback rơi về nhóm 8 framework đầy đủ luật.
 *
 * Máy cài mới không có bản ghi này → migration tự no-op.
 */
return new class extends Migration
{
    private const NAME = 'sports_general';

    public function up(): void
    {
        $framework = DB::table('prompt_frameworks')->where('name', self::NAME)->first();

        if (!$framework) {
            echo '  ' . self::NAME . " không tồn tại — bỏ qua\n";
            return;
        }

        // Còn context trỏ tới thì KHÔNG tắt: tắt sẽ đẩy category đó sang fallback
        // một cách âm thầm, đúng kiểu hỏng mà migration này sinh ra để dọn.
        $inUse = DB::table('category_contexts')->where('framework_id', $framework->id)->count();

        if ($inUse > 0) {
            throw new RuntimeException(sprintf(
                '%s đang được %d category_context sử dụng. Trỏ chúng sang framework khác trước, '
                . 'nếu không việc tắt cờ sẽ đẩy các category đó sang fallback mà không báo gì.',
                self::NAME,
                $inUse,
            ));
        }

        DB::table('prompt_frameworks')
            ->where('id', $framework->id)
            ->update(['is_active' => false]);

        $next = DB::table('prompt_frameworks')
            ->where('is_active', true)
            ->orderBy('created_at')
            ->value('name');

        echo '  ' . self::NAME . " → is_active = false\n";
        echo '  fallback mới: ' . ($next ?: '(KHÔNG CÒN framework active nào!)') . "\n";
    }

    public function down(): void
    {
        $affected = DB::table('prompt_frameworks')
            ->where('name', self::NAME)
            ->update(['is_active' => true]);

        echo '  ' . ($affected ? self::NAME . ' → is_active = true' : self::NAME . ' không tồn tại — bỏ qua') . "\n";
    }
};
