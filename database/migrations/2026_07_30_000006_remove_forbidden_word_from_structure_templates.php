<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gỡ từ cấm "journey" khỏi structure_template.
 *
 * phase3 liệt kê "journey" trong FORBIDDEN PHRASES kèm chỉ thị "instant rewrite
 * if found". Nhưng 4 structure_template lại chứa đúng từ đó, và chúng được inject
 * thẳng vào prompt qua {structure_template} ở STEP 2 — cùng lúc còn nằm trong
 * {content_types_block} ở đầu phase3.
 *
 * Kết quả: Claude nhận lệnh viết một mục tên "THE JOURNEY" từ một prompt vừa cấm
 * dùng chữ "journey". Model buộc phải chọn một trong hai — và dù chọn gì thì cũng
 * đang vi phạm nửa còn lại.
 *
 * Đây là loại lỗi chỉ lộ ra khi đối chiếu hai bảng với nhau: danh sách từ cấm nằm
 * ở prompt_frameworks.phase3_generate, còn vi phạm nằm ở
 * framework_content_types.structure_template. Đọc riêng từng file thì không thấy.
 *
 * Chỉ đổi tên nhãn mục, giữ nguyên phần mô tả phía sau dấu gạch.
 */
return new class extends Migration
{
    /** @var array<string, string> nguyên văn cũ => nguyên văn mới */
    private const REPLACEMENTS = [
        '② CAREER CONTEXT — Journey to this moment'
            => '② CAREER CONTEXT — What led to this moment',

        '③ THE JOURNEY — Recovery, training, mental transformation'
            => '③ THE CLIMB BACK — Recovery, training, mental transformation',

        '③ THE JOURNEY — Key moments, bikes, roads'
            => '③ THE ROAD SO FAR — Key moments, bikes, roads',

        '② CONTEXT — The journey that led here'
            => '② CONTEXT — What led here',
    ];

    public function up(): void
    {
        $this->apply(self::REPLACEMENTS);
    }

    public function down(): void
    {
        $this->apply(array_flip(self::REPLACEMENTS));
    }

    private function apply(array $map): void
    {
        $touched = 0;

        foreach (DB::table('framework_content_types')->get() as $type) {
            $template = strtr($type->structure_template, $map);

            if ($template !== $type->structure_template) {
                DB::table('framework_content_types')
                    ->where('id', $type->id)
                    ->update(['structure_template' => $template]);

                $touched++;
            }
        }

        echo "  đã sửa {$touched} structure_template\n";

        $this->reportRemaining();
    }

    /**
     * Đối chiếu lại toàn bộ structure_template với danh sách từ cấm lấy trực tiếp
     * từ phase3 — để migration này tự tố cáo nếu còn sót, thay vì im lặng.
     */
    private function reportRemaining(): void
    {
        $phase3 = DB::table('prompt_frameworks')->value('phase3_generate');

        if (!$phase3) {
            return;
        }

        $start = strpos($phase3, 'FORBIDDEN PHRASES');
        $end   = strpos($phase3, 'Never infer causes');

        if ($start === false || $end === false) {
            return;
        }

        preg_match_all('/"([^"]+)"/u', substr($phase3, $start, $end - $start), $m);

        $remaining = [];

        foreach (DB::table('framework_content_types')->get() as $type) {
            foreach ($m[1] as $phrase) {
                if (stripos($type->structure_template, $phrase) !== false) {
                    $remaining[] = "{$type->type_code} → \"{$phrase}\"";
                }
            }
        }

        echo '  còn sót: ' . (implode(', ', array_unique($remaining)) ?: 'không') . "\n";
    }
};
