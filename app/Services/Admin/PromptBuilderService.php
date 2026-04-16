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
    private const DEFAULT_SCHEMA = <<<JSON
{
  "title": "...",
  "meta_description": "...",
  "content": "...",
  "faq": []
}
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
            'tone_notes'  => $context->tone_notes,
            'hook_style'  => $context->hook_style,
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
     */
    private function buildOutputSchema(string $categoryId): string
    {
        return Cache::remember(
            'prompt_schema_' . $categoryId,
            3600,
            fn () => CategoryOutputField::buildSchemaBlock($categoryId)
        );
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

        return new PromptPayload(
            system:            $framework->system_prompt,
            phase1:            $framework->phase1_analyze,
            phase2:            $framework->phase2_diagnose,
            phase3:            $framework->phase3_generate,
            outputSchema:      self::DEFAULT_SCHEMA,
            contentTypesBlock: '(fallback — no category context)',
            contextId:         null,
            frameworkVersion:  $framework->version,
        );
    }
}
