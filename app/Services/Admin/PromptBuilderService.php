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
    /**
     * Placeholder được phép trong từng field của PromptFramework.
     *
     * Key   = field
     * Value = placeholder mà pipeline giải được cho field đó
     *
     * system_prompt cố ý rỗng: nó KHÔNG đi qua inject() (build() truyền thẳng
     * vào PromptPayload), nên mọi {placeholder} viết ở đó tới Claude dạng literal.
     *
     * structure_template hợp lệ ở phase3 dù inject() không giải nó — nó là
     * deferred, PromptPayload::sonnetPrompt() mới thay giá trị thật vào.
     */
    public const PLACEHOLDERS = [
        'system_prompt'   => [],
        'phase1_analyze'  => ['domain', 'audience', 'terminology'],
        'phase2_diagnose' => ['content_types_block'],
        'phase3_generate' => [
            'domain', 'audience', 'terminology',
            'content_types_block', 'tone_notes', 'hook_style',
            'structure_template',
        ],
    ];

    /**
     * Placeholder BẮT BUỘC phải có mặt, nếu không giá trị đã tính ra sẽ bị vứt đi lặng lẽ.
     *
     * Đây là loại lỗi không để lại dấu vết nào — khác với placeholder gõ sai
     * (còn sót lại {domian} để mà phát hiện), thiếu placeholder thì chỉ đơn giản
     * là không có gì xảy ra cả:
     *
     *   phase2 thiếu {content_types_block}
     *     → buildContentTypesBlock() query DB, dựng block, rồi block đó không tới
     *       Claude. PromptPayload::haikuPrompt() chỉ ghép phase1 . phase2, và
     *       contentTypesBlock không được đọc ở đâu khác ngoài fingerprint().
     *
     *   phase3 thiếu {structure_template}
     *     → HookEngine detect content type, resolve structure_template, PromptGuard
     *       xác nhận nó không rỗng — rồi sonnetPrompt() str_replace không khớp gì.
     *       Sonnet viết bài không có chỉ dẫn cấu trúc nào.
     */
    public const REQUIRED_PLACEHOLDERS = [
        'phase2_diagnose' => ['content_types_block'],
        'phase3_generate' => ['structure_template'],
    ];

    // Placeholder giữ lại sau inject() ở phase3 — sonnetPrompt() resolve muộn hơn
    private const DEFERRED_PHASE3 = ['structure_template'];

    // Default output schema khi category chưa config CategoryOutputField
    private const DEFAULT_SCHEMA = <<<'JSON'
{
  "title": "...",
  "content": "...",
  "faq": []
}
JSON;

    /**
     * Universal Facebook fields — appended to EVERY output schema regardless of category.
     *
     * ── fb_image_text ──────────────────────────────────────────────────────────
     * Overlay lên ảnh bìa. 1 câu, 70-100 chars. Bắt buộc có tên người/đội thật.
     * Công thức: Hook + Tension — reveal half the story, keep best part hidden. No emoji.
     * GOOD: "Green Bay is still betting on MarShawn Lloyd — who played one NFL game in two years."
     * BAD:  "Green Bay is still banking on a back who played once." — anonymous, no proper name.
     *
     * ── fb_quote ───────────────────────────────────────────────────────────────
     * Direct quote từ nhân vật thật trong bài. Không bịa. Nếu không có → "".
     * 40–150 ký tự, kèm attribution.
     *
     * ── fb_post_content ────────────────────────────────────────────────────────
     * Caption Facebook 150-250 chars. Formula: named person/team + specific stake +
     * pressure/tension + withheld outcome. End with a soft CTA (deadline, stakes fork,
     * question, incomplete fact, insider signal) — never an explicit command.
     * No generic phrases: "major update", "huge news", "shocking development", "fans stunned".
     * No emoji. No URL. No hashtags. Same language as article.
     */
    private const FB_SCHEMA_APPEND = <<<'JSON'
,
  "fb_image_text": "1 sentence 70-100 chars. Strong active verb. MUST include full person name or team name — never 'a player', 'a back', 'the team'. Hook + Tension: reveal half the story, keep best part hidden. No emoji. GOOD: 'Green Bay is still betting on MarShawn Lloyd — who played one NFL game in two years.' BAD: anonymous reference. Same language as article.",
  "fb_post_content": "150-250 chars. Plain text only — no bullet points, emoji, URL, hashtags. Formula: [Named person/team] + [Specific stake] + [Pressure/tension] + [Withheld outcome]. End with a soft CTA — choose one: Deadline ('The window closes Thursday.'), Stakes fork ('Sign him now — or lose him for nothing.'), Question ('Which team made the call nobody expected?'), Incomplete fact ('One number explains why Green Bay kept Lloyd over Wilson.'), Insider signal ('What he said off-script tells you everything.'). No explicit CTAs: 'Find out', 'Read more', 'Click', 'Discover'. No generic phrases: 'major update', 'huge news', 'shocking development', 'fans stunned'. Same language as article."
JSON;

    public function __construct(
        private PromptGuard $promptGuard,
    ) {}

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

        $this->promptGuard->validatePlaceholders($framework->only(array_keys(self::PLACEHOLDERS)));

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
        ], self::DEFERRED_PHASE3);

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

    /**
     * Soi placeholder của một bộ field. KHÔNG throw — trả về danh sách vấn đề.
     *
     * PromptGuard::validatePlaceholders() bọc hàm này với ngữ nghĩa throw cho
     * runtime; PromptFrameworkForm gọi thẳng để hiện lỗi ngay trên form lúc lưu.
     * Hai đường dùng chung một contract nên không thể lệch nhau.
     *
     * Soi template THÔ, trước khi inject. Nếu soi sau thì giá trị vừa chèn vào
     * (content_types_block, structure_template…) có thể chứa dấu ngoặc nhọn và
     * bị nhận nhầm là placeholder hỏng.
     *
     * @param  array<string, string|null>  $fields  field => nội dung
     * @return array<string, array{unknown: string[], missing: string[]}>
     *         Chỉ chứa field có vấn đề; mảng rỗng nghĩa là hợp lệ.
     */
    public static function inspectPlaceholders(array $fields): array
    {
        $problems = [];

        foreach ($fields as $field => $text) {
            if (!array_key_exists($field, self::PLACEHOLDERS)) {
                continue;
            }

            preg_match_all('/\{[a-z_]+\}/', (string) $text, $m);
            $found = array_unique($m[0]);

            $allowed = array_map(fn ($k) => "{{$k}}", self::PLACEHOLDERS[$field]);
            $needed  = array_map(fn ($k) => "{{$k}}", self::REQUIRED_PLACEHOLDERS[$field] ?? []);

            $unknown = array_values(array_diff($found, $allowed));
            $missing = array_values(array_diff($needed, $found));

            if ($unknown || $missing) {
                $problems[$field] = ['unknown' => $unknown, 'missing' => $missing];
            }
        }

        return $problems;
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
     * FB fields (fb_image_text, fb_post_content) luôn được append
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
     *
     * Soi orphan trên template THÔ (trước khi thay), không phải sau. Soi sau thì
     * giá trị vừa chèn vào — content_types_block dựng từ structure_template của
     * admin — có thể chứa dấu ngoặc nhọn và bị nhận nhầm là placeholder hỏng.
     *
     * Orphan = placeholder không nằm trong $vars lẫn $deferred. Từ khi
     * PromptGuard::validatePlaceholders() chặn ở đầu vào, tình huống này chỉ còn
     * xảy ra khi const PLACEHOLDERS lệch khỏi các lời gọi inject() trong build()
     * — lỗi lập trình, không phải lỗi nội dung admin viết. Vì vậy log mức error
     * kèm đủ ngữ cảnh để truy, rồi vẫn xóa đi để Claude không nhận literal.
     */
    private function inject(string $template, array $vars, array $deferred = []): string
    {
        preg_match_all('/\{[a-z_]+\}/', $template, $matches);
        $resolvable = array_map(
            fn ($k) => "{{$k}}",
            array_merge(array_keys($vars), $deferred)
        );
        $orphans = array_values(array_diff(array_unique($matches[0]), $resolvable));

        foreach ($vars as $key => $value) {
            $template = str_replace("{{$key}}", (string) $value, $template);
        }

        if ($orphans) {
            Log::error('[PromptBuilder] Placeholder không giải được — PLACEHOLDERS lệch khỏi build()?', [
                'orphans'    => $orphans,
                'resolvable' => $resolvable,
            ]);
            $template = str_replace($orphans, '', $template);
        }

        return $template;
    }

    /**
     * Fallback khi category chưa có context config.
     */
    private function buildFallback(): PromptPayload
    {
        $framework = $this->resolveFallbackFramework();

        $fallbackSchema = preg_replace('/\}\s*$/', self::FB_SCHEMA_APPEND . "\n}", self::DEFAULT_SCHEMA);

        // Strip unresolved {placeholders} — no context to inject, but keep {structure_template}
        // which is a deferred placeholder resolved later in sonnetPrompt()
        $strip = fn(string $t) => preg_replace('/\{(?!structure_template\})[a-z_]+\}/', '', $t);

        return new PromptPayload(
            system:            $framework->system_prompt,
            phase1:            $strip($framework->phase1_analyze),
            phase2:            $strip($framework->phase2_diagnose),
            phase3:            $strip($framework->phase3_generate),
            outputSchema:      $fallbackSchema,
            contentTypesBlock: '(fallback — no category context)',
            contextId:         null,
            frameworkVersion:  $framework->version,
        );
    }

    /**
     * Chọn framework cho nhánh fallback.
     *
     * Lấy theo tên cấu hình ở config('prompt.fallback_framework'), không phải
     * theo "cũ nhất". Cách cũ dùng orderBy('created_at') mà 8 framework do
     * PromptSystemSeeder tạo có created_at giống nhau tới từng giây — MySQL
     * không có căn cứ nào để sắp thứ tự, nên cùng một truy vấn trả về
     * entertainment_viral rồi nfl_sports ở hai lần chạy cách nhau vài phút.
     * Phase3 của chúng khác nhau (mỗi cái một bộ DOMAIN LAWS) và system_prompt
     * cũng khác, nên bài cùng một category có thể đổi giọng giữa các lần chạy.
     *
     * Không tìm thấy tên đã cấu hình thì mới xét tới thứ tự — và xếp theo name,
     * thứ duy nhất ở đây thực sự phân biệt được các bản ghi.
     */
    private function resolveFallbackFramework(): PromptFramework
    {
        $configured = config('prompt.fallback_framework');

        if ($configured) {
            $framework = PromptFramework::where('name', $configured)
                ->where('is_active', true)
                ->first();

            if ($framework) {
                return $framework;
            }

            Log::warning('[PromptBuilder] Framework fallback đã cấu hình không dùng được — xếp theo tên để chọn', [
                'configured' => $configured,
                'reason'     => 'không tồn tại hoặc is_active = false',
            ]);
        }

        $framework = PromptFramework::where('is_active', true)
            ->orderBy('name')
            ->first();

        if (!$framework) {
            throw new \RuntimeException('[PromptBuilder] No active prompt framework found. Run seeder first.');
        }

        return $framework;
    }
}
