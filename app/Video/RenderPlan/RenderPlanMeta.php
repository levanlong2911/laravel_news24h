<?php

namespace App\Video\RenderPlan;

/**
 * Metadata cấp video, đến từ NGOÀI pipeline semantic (job config), không phải
 * từ bài báo. plan_id và generated_at nhận từ ngoài để Assembler deterministic —
 * test truyền giá trị cố định, production truyền uuid + now.
 */
final class RenderPlanMeta
{
    public function __construct(
        public readonly string $planId,
        public readonly string $articleId,
        public readonly string $title,
        public readonly string $language,
        public readonly string $generatedAt,
    ) {
    }
}
