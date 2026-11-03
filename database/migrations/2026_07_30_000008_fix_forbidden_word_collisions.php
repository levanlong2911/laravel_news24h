<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gỡ ba chỗ dữ liệu vi phạm chính danh sách từ cấm của phase3.
 *
 * 2026_07_30_000006 đã mô tả đúng loại lỗi này — "từ cấm nằm ở
 * prompt_frameworks.phase3_generate, còn vi phạm nằm ở
 * framework_content_types.structure_template; đọc riêng từng bảng thì không
 * thấy" — rồi chỉ chữa đúng một bảng, vì đó là chỗ tình cờ phát hiện được.
 *
 * Nhưng phase3 hút dữ liệu từ BỐN nguồn, không phải một. Quét chéo cả bốn trên
 * DB ngày 2026-08-04 lòi ra ba vi phạm nữa:
 *
 * 1. framework_content_types.type_name — "breakthrough"
 *    knowledge_discovery cấm "breakthrough" trong DOMAIN LAWS, nhưng chính nó
 *    có content type tên "Scientific Breakthrough". PromptPayload::sonnetPrompt()
 *    bơm type_name vào thành dòng "CONTENT TYPE:", nên mọi bài dạng này nhận
 *    một prompt vừa cấm chữ đó vừa dán nhãn bằng chữ đó.
 *
 * 2. category_contexts.tone_notes — "extraordinary"
 *    Context superyacht mô tả giọng là "World of extraordinary discretion and
 *    excess." Chuỗi này đi vào phase3 qua {tone_notes}, mà "extraordinary" nằm
 *    trong FORBIDDEN PHRASES toàn cục kèm chỉ thị "instant rewrite if found".
 *
 *    Không thay bằng một tính từ khác cùng nhóm (exceptional / remarkable):
 *    danh sách cấm đang loại dần đúng nhóm tính từ thổi phồng đó, nên đổi ngang
 *    chỉ là dời ngày phát sinh lỗi. Viết lại thành danh từ:
 *    "World of discretion, craftsmanship, and quiet excess."
 *
 *    Cụm này thêm tín hiệu "craftsmanship" vốn không có trong bản gốc — đó là
 *    thay đổi giọng có chủ ý, hợp DOMAIN LAWS của luxury_assets ("Understatement
 *    is credibility", "Specs speak: no superlatives needed").
 *
 * 3. category_contexts.hook_style — "journey"
 *    Context moto-harley bảo mở bài bằng "the quiet dignity of the journey",
 *    trong khi "journey" là từ cấm. Chuỗi này đi vào phase3 qua {hook_style}.
 *    "long road" hợp ngữ cảnh Harley hơn và không mất ý.
 *
 *    Bản ghi này chưa có trên DB đang chạy (lifestyle_living hiện 0 context),
 *    nhưng PromptSystemSeeder có tạo nó — nên máy cài mới sẽ dính. Sửa cả hai
 *    nơi mới hết bệnh; xem hợp đồng ở đầu PromptSystemSeeder.
 *
 * Idempotent: mỗi bước tự bỏ qua nếu nội dung đã đúng, hoặc nếu bản ghi không
 * tồn tại trên môi trường này.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->renameContentType('knowledge_discovery', 'breakthrough', 'Scientific Breakthrough', 'Scientific Discovery');

        $this->patchContext(
            'superyacht',
            'tone_notes',
            'World of extraordinary discretion and excess.',
            'World of discretion, craftsmanship, and quiet excess.',
        );

        $this->patchContext('moto-harley', 'hook_style', 'the journey', 'the long road');

        $this->reportRemaining();
    }

    /**
     * Đổi tên hiển thị của một content type. type_code giữ nguyên — nó là khoá,
     * và nó không bao giờ được bơm vào phase3.
     */
    private function renameContentType(string $framework, string $typeCode, string $from, string $to): void
    {
        $frameworkId = DB::table('prompt_frameworks')->where('name', $framework)->value('id');

        if (!$frameworkId) {
            echo "  {$framework}: không có trên môi trường này, bỏ qua\n";
            return;
        }

        $affected = DB::table('framework_content_types')
            ->where('framework_id', $frameworkId)
            ->where('type_code', $typeCode)
            ->where('type_name', $from)
            ->update(['type_name' => $to]);

        echo $affected
            ? "  {$framework}/{$typeCode}: type_name → '{$to}'\n"
            : "  {$framework}/{$typeCode}: type_name đã đúng hoặc không tồn tại\n";
    }

    /**
     * Thay một cụm trong tone_notes / hook_style của context thuộc category $slug.
     */
    private function patchContext(string $slug, string $column, string $from, string $to): void
    {
        $categoryId = DB::table('categories')->where('slug', $slug)->value('id');

        if (!$categoryId) {
            echo "  {$slug}: category không tồn tại, bỏ qua\n";
            return;
        }

        $context = DB::table('category_contexts')->where('category_id', $categoryId)->first();

        if (!$context) {
            echo "  {$slug}: chưa có context, bỏ qua\n";
            return;
        }

        $value = (string) $context->{$column};
        $patched = str_replace($from, $to, $value);

        if ($patched === $value) {
            echo "  {$slug}/{$column}: đã đúng, không đụng\n";
            return;
        }

        DB::table('category_contexts')->where('id', $context->id)->update([$column => $patched]);

        echo "  {$slug}/{$column}: '{$from}' → '{$to}'\n";
    }

    /**
     * Nhắc chạy lệnh kiểm tra thật thay vì tin migration đã xong việc.
     *
     * Migration chỉ biết ba chỗ nó vừa sửa. 000004 sắp thêm DOMAIN LAWS cho
     * nfl_sports và travel_mobility — hai bộ luật đó mang từ cấm MỚI, có thể va
     * vào chính content type của chúng. Chỉ quét lại toàn bộ mới biết.
     */
    private function reportRemaining(): void
    {
        echo "  → chạy 'php artisan prompt:check' để xác nhận không còn va chạm nào\n";
    }

    /**
     * Không đảo ngược: khôi phục lại đúng những chữ mà phase3 đang cấm là vô
     * nghĩa. Muốn đổi cách diễn đạt thì sửa qua admin UI hoặc migration mới.
     */
    public function down(): void
    {
        echo "  fix forbidden word collisions: không đảo ngược (xem docblock)\n";
    }
};
