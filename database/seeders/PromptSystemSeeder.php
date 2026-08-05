<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Dựng toàn bộ hạ tầng prompt cho DB trống — điểm vào cho MÁY CÀI MỚI.
 *
 * Tạo:
 *   8  prompt_frameworks         (mỗi nhóm nội dung một cái)
 *   48 framework_content_types   (6 × 8)
 *   15 categories                (khớp theo slug)
 *   15 category_contexts         (mỗi category một cái, trỏ đúng framework)
 *
 * ── Vì sao chia hai tầng ────────────────────────────────────────────────────
 *
 * File này từng làm cả hai việc. Tách ra vì hai tầng có tuổi thọ khác hẳn nhau,
 * và gộp chúng khiến tầng an toàn không gọi riêng được:
 *
 *   PromptFrameworkSeeder        8 framework + 48 content type
 *                                → hạ tầng, mọi môi trường phải giống nhau
 *                                → gọi được trên môi trường đã sống
 *
 *   PromptCategoryContextSeeder  15 category + 15 context
 *                                → biên tập, mỗi môi trường một khác
 *                                → CHỈ dành cho máy cài mới
 *
 * Đối chiếu ngày 2026-08-05 giữa DB dev và bản seed sạch: framework khớp 8/8 và
 * content type khớp 48/48, nhưng category thì 6 cái mỗi bên bên kia không có.
 * Gọi cả gói lên một môi trường đã sống vì thế sẽ chèn thêm những category mà
 * môi trường đó đã cố ý không dùng — firstOrCreate không sửa gì, nhưng nó tạo.
 *
 * ── Gọi cái nào ─────────────────────────────────────────────────────────────
 *
 *   máy cài mới     php artisan db:seed --class=PromptSystemSeeder
 *   thiếu framework php artisan db:seed --class=PromptFrameworkSeeder
 *
 * Không có trường hợp nào cần gọi PromptCategoryContextSeeder một mình ngoài
 * việc dựng lại category trên DB đã có sẵn framework.
 *
 * ── Hợp đồng chung ──────────────────────────────────────────────────────────
 *
 * Cả hai tầng đều CHỈ TẠO, KHÔNG SỬA (firstOrCreate). Chạy lại bao nhiêu lần
 * cũng an toàn. Chi tiết vì sao điều đó là bắt buộc chứ không phải tiện ích —
 * cùng với luật "viết migration sửa prompt thì phải sửa cả seeder" — nằm ở
 * docblock của PromptFrameworkSeeder.
 */
class PromptSystemSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PromptFrameworkSeeder::class,
            PromptCategoryContextSeeder::class,
        ]);
    }
}
