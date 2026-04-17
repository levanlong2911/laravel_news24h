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

    // ── Prompt gửi Sonnet: phase3 + facts + bestHook (anchor) + schema ───────
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

        return $phase3
            . "\n\nTOPIC: {$keyword}"
            . "\nTITLE ANCHOR (write content to support this hook): {$bestHook}"
            . "\n\nEXTRACTED FACTS:\n---\n{$facts}\n---"
            . "\n\nOUTPUT — Return ONLY this JSON (no markdown, no code block):\n"
            . $this->outputSchema;
    }

    public function hasContext(): bool
    {
        return $this->contextId !== null;
    }
}
