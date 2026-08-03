<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gỡ khối {content_types_block} khỏi đầu phase3.
 *
 * Khối này chở CẢ 6 content type của framework, mỗi cái kèm trigger keywords,
 * tone profile và structure template đầy đủ — khoảng 3.700 ký tự, ~21% prompt
 * gửi Sonnet mỗi bài. Nhưng tới bước đó Sonnet không còn phải phân loại gì nữa:
 *
 *   Haiku chạy phase2      → PRIMARY TYPE
 *   HookEngine::resolve()  → detectedType
 *   structure_template của đúng type đó → inject riêng qua {structure_template}
 *
 * Ba phần của khối, giá trị khác hẳn nhau ở bước Sonnet:
 *   trigger_keywords   → tín hiệu phân loại, việc đó xong từ phase2
 *   structure_template → đã inject riêng, ở đây là bản trùng của cả 6 type
 *   tone_profile       → hữu ích, và trước nay không có đường nào khác tới Sonnet
 *
 * Nên phần thứ ba được chuyển sang PromptPayload::sonnetPrompt() dưới dạng hai
 * dòng CONTENT TYPE / TYPE TONE của đúng type đã detect. Không mất tín hiệu nào.
 *
 * phase2 giữ nguyên {content_types_block} — ở đó nó là đầu vào để phân loại,
 * đúng chỗ và bắt buộc (xem PromptBuilderService::REQUIRED_PLACEHOLDERS).
 */
return new class extends Migration
{
    private const OLD_HEADER = "\nCONTENT TYPES REFERENCE:\n{content_types_block}\n";

    public function up(): void
    {
        $touched = [];

        foreach (DB::table('prompt_frameworks')->get() as $fw) {
            if (!str_contains($fw->phase3_generate, self::OLD_HEADER)) {
                continue;
            }

            $phase3 = str_replace(self::OLD_HEADER, '', $fw->phase3_generate);

            DB::table('prompt_frameworks')
                ->where('id', $fw->id)
                ->update(['phase3_generate' => $phase3]);

            $touched[$fw->name] = strlen($fw->phase3_generate) - strlen($phase3);
        }

        foreach ($touched as $name => $saved) {
            echo "  {$name}: -{$saved} ký tự\n";
        }

        echo '  tổng: ' . count($touched) . ' framework, trung bình -'
            . ($touched ? (int) (array_sum($touched) / count($touched)) : 0) . " ký tự\n";
    }

    public function down(): void
    {
        // Chèn lại ngay trước đường kẻ đầu tiên (mở đầu ABSOLUTE RULES).
        foreach (DB::table('prompt_frameworks')->get() as $fw) {
            if (str_contains($fw->phase3_generate, '{content_types_block}')) {
                continue;
            }

            $sepPos = strpos($fw->phase3_generate, '══');
            if ($sepPos === false) {
                continue;
            }

            $phase3 = substr($fw->phase3_generate, 0, $sepPos)
                . ltrim(self::OLD_HEADER, "\n") . "\n"
                . substr($fw->phase3_generate, $sepPos);

            DB::table('prompt_frameworks')
                ->where('id', $fw->id)
                ->update(['phase3_generate' => $phase3]);
        }
    }
};
