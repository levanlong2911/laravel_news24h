<?php

namespace App\Video\RenderPlan;

/**
 * Metadata cấp video, đến từ NGOÀI pipeline semantic (job config), không phải
 * từ bài báo. plan_id và generated_at nhận từ ngoài để Assembler deterministic —
 * test truyền giá trị cố định, production truyền uuid + now.
 */
final class RenderPlanMeta
{
    /**
     * @param string $category Slug category CMS của bài (bảng `categories`) — rỗng
     *        khi bài chưa gán category. Compiler Python dùng nó để nạp đúng hồ sơ
     *        tri thức ngành (`design/data/category_profiles/<slug>.json`): xưởng
     *        đóng tàu, nhà máy ô tô và xưởng đua có góc máy, vai thợ, đồ bảo hộ
     *        khác nhau, mà RenderPlan trước đây KHÔNG mang tín hiệu nào để phân
     *        biệt — Python buộc phải đoán.
     *
     *        Vì sao là SLUG chứ không phải tên ngành: category là dữ liệu động
     *        trong CMS (27 hàng lúc thêm field này, chỉ 4 có mặt trong
     *        config('video.creation_arc.categories')). Gửi slug thì thêm chủ đề
     *        mới = thêm category + thêm 1 file JSON, không sửa dòng code nào.
     *        Không có hồ sơ tương ứng thì Python KHÔNG bơm gì — không suy diễn.
     */
    public function __construct(
        public readonly string $planId,
        public readonly string $articleId,
        public readonly string $title,
        public readonly string $language,
        public readonly string $generatedAt,
        public readonly string $category = '',
    ) {
    }
}
