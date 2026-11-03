<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gỡ ba mâu thuẫn nội bộ trong phase3.
 *
 * 1. ĐỘ DÀI TỐI THIỂU CHỌI LUẬT CẤM ĐỘN CHỮ
 *    ABSOLUTE RULES viết "Write until done. Stop. Never pad to hit a length target."
 *    nhưng STEP 2 lại bắt "Length: 120 - 280 characters per <p>". Câu nào tự nhiên
 *    dừng ở 90 ký tự thì buộc phải độn cho đủ 120 — đúng thứ vừa cấm. Bỏ cận dưới,
 *    giữ cận trên.
 *
 * 2. QUALITY GATE NÓI "no CTA" TRONG KHI THÂN BÀI BẮT BUỘC SOFT CTA
 *    STEP 3 yêu cầu "Step 3 — Soft CTA: end with a sentence that makes the reader
 *    feel they need the outcome", và chỉ cấm CTA tường minh ("Find out", "Read more").
 *    Dòng gate rút gọn thành "no CTA" — đọc theo nghĩa đen là cấm luôn cái vừa bắt
 *    buộc. Đổi thành "no explicit CTA".
 *
 * 3. HAI DÒNG GATE FB POST TRÙNG NHAU (chỉ có trên production)
 *    Migration 074940 dùng regex /\[ \] FB post:[^\n]+/u — khớp CẢ dòng độ dài lẫn
 *    dòng format, rồi thay cả hai bằng cùng một chuỗi. Kết quả: dòng kiểm tra format
 *    ("no bullet points, no URL, no hashtag...") bị ghi đè mất, còn lại hai dòng y hệt.
 *
 *    Máy local không dính vì các migration đó no-op trên bảng rỗng. Không tự sửa thì
 *    deploy xong production vẫn mang lỗi này — 2026_07_30_000001 không đụng tới nó,
 *    vì str_replace của nó tìm chuỗi CŨ mà production đã ở chuỗi mới rồi.
 *
 *    Cách xử lý: xoá sạch mọi dòng "[ ] FB post:" rồi chèn lại đúng hai dòng chuẩn.
 *    Hai môi trường đang ở hai trạng thái khác nhau, chỉ có quy về một mối mới cho
 *    ra kết quả giống nhau.
 */
return new class extends Migration
{
    private const LENGTH_OLD = '• Length: 120 - 280 characters per <p>, HTML <p> tags only';
    private const LENGTH_NEW = '• Length: up to 280 characters per <p>, HTML <p> tags only — no minimum, never pad to reach a target';

    private const GATE_ANCHOR = '[ ] Final sentence forward-looking, not philosophical';

    private const GATE_POST_LENGTH = '[ ] FB post: 150-250 chars, named + specific stake + pressure + withheld outcome, no generic phrases, no explicit CTA';
    private const GATE_POST_FORMAT = '[ ] FB post: no bullet points, no URL, no hashtag, no emoji, conflict named in first line';

    public function up(): void
    {
        $touched = [];

        foreach (DB::table('prompt_frameworks')->get() as $fw) {
            $phase3 = str_replace(self::LENGTH_OLD, self::LENGTH_NEW, $fw->phase3_generate);
            $phase3 = $this->rebuildPostGateLines($phase3);

            if ($phase3 !== $fw->phase3_generate) {
                DB::table('prompt_frameworks')->where('id', $fw->id)->update(['phase3_generate' => $phase3]);
                $touched[] = $fw->name;
            }
        }

        echo '  đã sửa: ' . (implode(', ', $touched) ?: '(không có gì để sửa)') . "\n";
    }

    public function down(): void
    {
        foreach (DB::table('prompt_frameworks')->get() as $fw) {
            $phase3 = str_replace(self::LENGTH_NEW, self::LENGTH_OLD, $fw->phase3_generate);

            // Trả lại đúng chữ "no CTA" của bản cũ. Dòng format thì giữ nguyên —
            // khôi phục nó về trạng thái "bị ghi đè mất" là vô nghĩa.
            $phase3 = str_replace(
                self::GATE_POST_LENGTH,
                str_replace('no explicit CTA', 'no CTA', self::GATE_POST_LENGTH),
                $phase3
            );

            if ($phase3 !== $fw->phase3_generate) {
                DB::table('prompt_frameworks')->where('id', $fw->id)->update(['phase3_generate' => $phase3]);
            }
        }
    }

    /**
     * Dựng lại khối gate của FB post về đúng hai dòng chuẩn.
     */
    private function rebuildPostGateLines(string $phase3): string
    {
        if (!str_contains($phase3, self::GATE_ANCHOR)) {
            return $phase3;
        }

        $kept = [];

        foreach (explode("\n", $phase3) as $line) {
            if (str_starts_with(trim($line), '[ ] FB post:')) {
                continue;
            }

            $kept[] = $line;

            if (trim($line) === self::GATE_ANCHOR) {
                $kept[] = self::GATE_POST_LENGTH;
                $kept[] = self::GATE_POST_FORMAT;
            }
        }

        return implode("\n", $kept);
    }
};
