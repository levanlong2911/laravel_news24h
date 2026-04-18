<?php

namespace App\Services\Admin;

use App\Models\CategoryContext;
use App\Models\CategoryOutputField;
use App\Models\FrameworkContentType;
use App\Models\PromptFramework;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PromptBuilderService
{
    // Default output schema khi category chưa config CategoryOutputField
    private const DEFAULT_SCHEMA = <<<'JSON'
{
  "title": "...",
  "meta_description": "...",
  "content": "...",
  "faq": []
}
JSON;

    /**
     * Universal Facebook fields — appended to EVERY output schema regardless of category.
     *
     * fb_image_text: 1-2 câu ngắn để overlay lên ảnh bìa (Canva, tool làm ảnh).
     *   Viết như câu văn tự nhiên — không prefix "BREAKING:", không format label.
     *   Câu hay nhất / fact nổi bật nhất của bài. Đọc được độc lập, không cần context.
     *   Ideal: 80–150 ký tự.
     *
     * fb_quote: Câu trích dẫn trực tiếp từ nhân vật trong bài (nếu có).
     *   Chỉ dùng khi bài có direct quote thật sự đáng dùng.
     *   Không bịa — nếu không có quote hay thì để chuỗi rỗng "".
     *   Ideal: 40–150 ký tự, bao gồm attribution nếu có chỗ.
     *
     * fb_post_content: Caption sẵn sàng paste lên Facebook.
     *   Facebook hiện ~200 ký tự trước "Xem thêm" trên mobile — 2 dòng đầu PHẢI đủ hấp dẫn.
     *   Cấu trúc cứng:
     *     Dòng 1: Hook mạnh nhất — 1 câu, ≤90 ký tự, trigger cảm xúc hoặc tò mò
     *     Dòng 2: Amplify — 1 câu, ≤110 ký tự, đẩy sức hút lên
     *     [dòng trống]
     *     Dòng 3-5: Chi tiết ngắn từ bài (người đọc thấy sau khi bấm "Xem thêm")
     *     [dòng trống]
     *     Dòng cuối: CTA tự nhiên, phù hợp nội dung — hỏi ý kiến, gợi tag bạn bè, hoặc
     *                reaction prompt. KHÔNG generic ("đọc thêm", "click vào link").
     *   Không có URL. Emoji dùng tiết kiệm, đúng chỗ. 250–450 ký tự tổng.
     *   Viết cùng ngôn ngữ với phần content bài viết.
     */
    private const FB_SCHEMA_APPEND = <<<'JSON'
,
  "fb_image_text": "...",
  "fb_quote": "...",
  "fb_post_content": "..."
JSON;

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Build PromptPayload cho category.
     * Fallback về default framework nếu chưa config context.
     */
    public function build(string $categoryId): PromptPayload
    {
        $context = CategoryContext::forCategory($categoryId);

        if (!$context) {
            Log::info("[PromptBuilder] No context for category {$categoryId}, using fallback");
            return $this->buildFallback();
        }

        $framework = $context->framework;

        if (!$framework || !$framework->is_active) {
            Log::warning("[PromptBuilder] Framework inactive for category {$categoryId}, using fallback");
            return $this->buildFallback();
        }

        $contentTypesBlock = $this->buildContentTypesBlock($framework, $context);
        $outputSchema      = $this->buildOutputSchema($categoryId);

        $phase1 = $this->inject($framework->phase1_analyze, [
            'domain'      => $context->domain,
            'audience'    => $context->audience,
            'terminology' => implode(', ', $context->terminology ?? []),
        ]);

        $phase2 = $this->inject($framework->phase2_diagnose, [
            'content_types_block' => $contentTypesBlock,
        ]);

        $phase3 = $this->inject($framework->phase3_generate, [
            'domain'              => $context->domain,
            'audience'            => $context->audience,
            'terminology'         => implode(', ', $context->terminology ?? []),
            'content_types_block' => $contentTypesBlock,
            'tone_notes'          => $context->tone_notes,
            'hook_style'          => $context->hook_style,
        ]);

        Log::debug("[PromptBuilder] Built payload", [
            'category_id' => $categoryId,
            'framework'   => $framework->name,
            'context_id'  => $context->id,
        ]);

        return new PromptPayload(
            system:            $framework->system_prompt,
            phase1:            $phase1,
            phase2:            $phase2,
            phase3:            $phase3,
            outputSchema:      $outputSchema,
            contentTypesBlock: $contentTypesBlock,
            contextId:         $context->id,
            frameworkVersion:  $framework->version,
        );
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Build content_types_block để inject vào phase2.
     * Merge default triggers của framework với custom_type_triggers của category.
     */
    private function buildContentTypesBlock(PromptFramework $framework, CategoryContext $context): string
    {
        $customTriggers = $context->custom_type_triggers ?? [];

        $block = $framework->contentTypes->map(function (FrameworkContentType $type) use ($customTriggers) {
            // Merge: category override có ưu tiên cao hơn
            $triggers = $type->trigger_keywords ?? [];
            if (!empty($customTriggers[$type->type_code])) {
                $triggers = array_unique(array_merge($triggers, $customTriggers[$type->type_code]));
            }

            return sprintf(
                "TYPE %d → %s\nTriggers: %s\nTone: %s\n\n%s",
                $type->sort_order,
                strtoupper($type->type_name),
                implode(', ', $triggers),
                implode(' · ', $type->tone_profile ?? []),
                $type->structure_template
            );
        })->implode("\n\n" . str_repeat('═', 43) . "\n\n");

        return $block ?: '(no content types configured)';
    }

    /**
     * Build output schema động từ CategoryOutputField.
     * Cache 1 giờ — schema hiếm khi thay đổi, tránh query mỗi job.
     * Fallback về DEFAULT_SCHEMA nếu chưa config.
     *
     * FB fields (fb_image_text, fb_quote, fb_post_content) luôn được append
     * vào cuối — universal, áp dụng cho mọi category.
     */
    private function buildOutputSchema(string $categoryId): string
    {
        $base = Cache::remember(
            'prompt_schema_' . $categoryId,
            3600,
            fn () => CategoryOutputField::buildSchemaBlock($categoryId)
        );

        // Inject FB fields trước dấu "}" đóng cuối cùng
        // Đảm bảo hoạt động đúng dù base schema có trailing whitespace hay không
        return rtrim($base, " \t\n\r") === '}'
            ? rtrim($base, " \t\n\r\0\x0B}") . self::FB_SCHEMA_APPEND . "\n}"
            : preg_replace('/\}\s*$/', self::FB_SCHEMA_APPEND . "\n}", $base);
    }

    /**
     * Replace {placeholder} trong template với giá trị thực.
     * Sau inject, kiểm tra unresolved placeholders — log warning + xóa để Claude không nhận literal.
     */
    private function inject(string $template, array $vars): string
    {
        foreach ($vars as $key => $value) {
            $template = str_replace("{{$key}}", (string) $value, $template);
        }

        $count = preg_match_all('/\{[a-z_]+\}/', $template, $matches);
        if ($count > 0) {
            Log::warning('[PromptBuilder] Unresolved placeholders detected', [
                'placeholders' => $matches[0],
            ]);
            // Replace với empty string — Claude không nhận literal {placeholder}
            $template = preg_replace('/\{[a-z_]+\}/', '', $template);
        }

        return $template;
    }

    /**
     * Fallback khi category chưa có context config.
     * Dùng framework đầu tiên đang active.
     */
    private function buildFallback(): PromptPayload
    {
        $framework = PromptFramework::where('is_active', true)
            ->orderBy('created_at')
            ->first();

        if (!$framework) {
            throw new \RuntimeException('[PromptBuilder] No active prompt framework found. Run seeder first.');
        }

        $fallbackSchema = preg_replace('/\}\s*$/', self::FB_SCHEMA_APPEND . "\n}", self::DEFAULT_SCHEMA);

        return new PromptPayload(
            system:            $framework->system_prompt,
            phase1:            $framework->phase1_analyze,
            phase2:            $framework->phase2_diagnose,
            phase3:            $framework->phase3_generate,
            outputSchema:      $fallbackSchema,
            contentTypesBlock: '(fallback — no category context)',
            contextId:         null,
            frameworkVersion:  $framework->version,
        );
    }
}
