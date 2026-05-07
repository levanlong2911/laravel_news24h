<?php

namespace App\Services\Admin;

/**
 * Value Object — output của PromptBuilderService.
 * Immutable. Chứa toàn bộ data để gọi Claude pipeline.
 */
final class PromptPayload
{
    public function __construct(
        public readonly string  $system,
        public readonly string  $phase1,
        public readonly string  $phase2,
        public readonly string  $phase3,
        public readonly string  $outputSchema,
        public readonly string  $contentTypesBlock,
        public readonly ?string $contextId,        // null = fallback, chưa config context
        public readonly int     $frameworkVersion, // framework->version → schema_version trong log
    ) {}

    /**
     * Short fingerprint của prompt (12 hex chars).
     * Include cả outputSchema + contentTypesBlock vì chúng ảnh hưởng trực tiếp đến output.
     * Nếu thiếu: cùng fingerprint nhưng output khác → debug production rất khó.
     */
    public function fingerprint(): string
    {
        return substr(
            hash('sha256', $this->system . $this->phase1 . $this->phase3 . $this->outputSchema . $this->contentTypesBlock),
            0, 12
        );
    }

    /**
     * Schema version = hash của outputSchema (8 hex chars).
     * Độc lập với framework->version — đổi khi output fields thay đổi,
     * kể cả khi framework version không đổi.
     */
    public function schemaVersion(): string
    {
        return substr(hash('sha256', $this->outputSchema), 0, 8);
    }

    // ── Prompt gửi Haiku: phase1 (analyze) + phase2 (diagnose) + rawText ─────

    public function haikuPrompt(string $rawText): string
    {
        return $this->phase1
            . "\n\n"
            . $this->phase2
            . "\n\nRAW CONTENT:\n---\n{$rawText}\n---";
    }

    /**
     * Haiku = signal compressor: extract key facts (< 1500 chars) + 5 headline hooks in ONE call.
     * Instructions placed BEFORE raw content so the model registers the output format first.
     */
    public function haikuCombinedPrompt(string $rawText, string $keyword, string $hookStyle): string
    {
        return $this->phase1
            . "\n\n"
            . $this->phase2
            . "\n\nIMPORTANT: Keep your analysis concise — under 1500 characters total."
            . "\nSignal-density rules: keep only high-signal facts (controversy, key quotes, stakes, timeline)."
            . "\nRemove: repetition, background filler, fan reactions, SEO padding."
            . "\nAFTER your analysis, you MUST end with exactly this line (replace h1-h5 with real headlines):"
            . "\nHOOKS_JSON:[\"h1\",\"h2\",\"h3\",\"h4\",\"h5\"]"
            . "\nHeadline rules: KEYWORD={$keyword} | STYLE={$hookStyle} | 45-90 chars | factually accurate | vary formats."
            . "\n\nRAW CONTENT:\n---\n{$rawText}\n---"
            . "\n\nReminder: end your response with HOOKS_JSON:[...] — 5 headlines, JSON array, nothing after.";
    }

    // ── Sonnet = article writer: compressed facts (≤ 2000 chars) + bestHook anchor ──
    // bestHook từ HookEngine — content phục vụ hook, không phải ngược lại
    // structureTemplate: injected AFTER HookEngine detects content type
    //   → phase3 contains {structure_template} placeholder
    //   → replaced here at call time (not at PromptBuilder::build() time)

    public function sonnetPrompt(
        string $facts,
        string $bestHook,
        string $keyword,
        string $structureTemplate = '',
    ): string {
        // Resolve {structure_template} placeholder in phase3
        $defaultStructure = config(
            'prompt.default_structure',
            "① HOOK — Open with the most compelling element\n② CONTEXT — Background and significance\n③ BODY — Core facts and analysis\n④ IMPACT — Consequences and implications\n⑤ CONCLUSION — Forward-looking statement"
        );

        $resolvedStructure = $structureTemplate ?: $defaultStructure;
        $phase3 = str_replace('{structure_template}', $resolvedStructure, $this->phase3);

        // Normalize + cap facts at sentence boundary ≤ 2000 chars.
        // Cutting at last '.' avoids broken sentences / mid-quote truncation.
        $quotes      = ['"', '"', '"'];
        $safeKeyword = str_replace($quotes, "'", $keyword);
        $safeHook    = str_replace($quotes, "'", $bestHook);

        $normalizedFacts = preg_replace('/\s+/', ' ', str_replace($quotes, "'", $facts));
        $safeFacts       = mb_substr($normalizedFacts, 0, 2000);
        $lastPeriod      = mb_strrpos($safeFacts, '.');
        if ($lastPeriod !== false) {
            $safeFacts = mb_substr($safeFacts, 0, $lastPeriod + 1);
        }

        return $phase3
            . "\n\nTOPIC: {$safeKeyword}"
            . "\nTITLE ANCHOR (write content to support this hook): {$safeHook}"
            . "\n\nEXTRACTED FACTS:\n---\n{$safeFacts}\n---"
            . "\n\nOUTPUT RULES:"
            . "\n- Return ONLY valid JSON. No markdown, no code block, no extra text."
            . "\n- In the \"content\" field: use single quotes for ALL HTML attributes (e.g. style='...' not style=\"...\")."
            . "\n- Keep \"content\" under 700 words to avoid truncation."
            . "\n- Keep \"faq\" to 3 items max."
            . "\n\n"
            . $this->outputSchema;
    }

    public function hasContext(): bool
    {
        return $this->contextId !== null;
    }
}
