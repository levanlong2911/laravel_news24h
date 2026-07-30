<?php

namespace App\Services\Admin;

/**
 * PromptGuard — validates pre-conditions BEFORE calling Claude Sonnet.
 *
 * Design: THROWS, does not return a result object.
 * Rationale: these are hard pre-conditions. If they fail, there is nothing
 * useful Sonnet can do — proceeding would waste tokens and produce garbage.
 *
 * Contrast with PostGuard (returns PostGuardResult) — post-conditions can be
 * partially acceptable (e.g. low confidence → human review), so PostGuard
 * returns a result and lets the caller decide. Pre-conditions cannot be
 * partially acceptable.
 *
 * Called in WriteArticleJob between Step 6 (HookEngine) and Step 7 (Sonnet).
 *
 * Checks:
 *   1. validateHook()              — bestHook is non-empty
 *   2. validateStructureTemplate() — structureTemplate is non-empty after
 *                                    config default resolution
 */
class PromptGuard
{
    /**
     * Validate that HookEngine produced a usable hook.
     *
     * Empty hook = HookEngine fully failed (even template fallback).
     * Proceeding without an anchor would let Sonnet invent a title with no
     * constraint — directly undermining the "content serves the hook" design.
     *
     * @throws PromptGuardException
     */
    public function validateHook(string $bestHook): void
    {
        if (empty(trim($bestHook))) {
            throw new PromptGuardException(
                'HookEngine returned an empty bestHook — cannot generate an article without a title anchor.',
                field: 'hook',
            );
        }
    }

    /**
     * Validate that a structure template is available for Sonnet.
     *
     * Called AFTER WriteArticleJob has resolved structureTemplate (with
     * config default fallback). If it's still empty, the config default
     * itself is missing — this is a misconfiguration, not a runtime edge case.
     *
     * @throws PromptGuardException
     */
    public function validateStructureTemplate(string $structureTemplate): void
    {
        if (empty(trim($structureTemplate))) {
            throw new PromptGuardException(
                'No structure_template resolved for this content type — check seeder data and prompt.default_structure config.',
                field: 'structure_template',
            );
        }
    }

    /**
     * Convenience: run all validations in one call.
     *
     * @throws PromptGuardException on first failure
     */
    /**
     * Validate placeholder của framework trước khi build prompt.
     *
     * Bắt hai loại lỗi mà validateStructureTemplate() ở trên không thấy được:
     *
     *   1. Placeholder lạ  — admin gõ {domian} hoặc {output_schema} (không hề
     *      được inject vào phase3). inject() sẽ xóa nó đi, prompt gửi Claude
     *      thiếu mảnh mà không còn dấu vết gì.
     *
     *   2. Thiếu placeholder bắt buộc — nguy hiểm hơn vì không để lại dấu vết
     *      nào cả. validateStructureTemplate() xác nhận structure_template có
     *      giá trị, nhưng nếu phase3 không chứa {structure_template} thì
     *      sonnetPrompt() str_replace không khớp gì và giá trị đó bị vứt đi.
     *      Guard cũ canh "giá trị có tồn tại không", guard này canh "template
     *      có chỗ để nhận nó không".
     *
     * Soi template thô trước khi inject nên không phụ thuộc context — gọi được
     * cho cả nhánh build() lẫn buildFallback().
     *
     * @param  array<string, string|null>  $fields  field => nội dung, thường là
     *         $framework->only(array_keys(PromptBuilderService::PLACEHOLDERS))
     *
     * @throws PromptGuardException ở field hỏng đầu tiên
     */
    public function validatePlaceholders(array $fields): void
    {
        $problems = PromptBuilderService::inspectPlaceholders($fields);

        foreach ($problems as $field => $problem) {
            if ($problem['missing']) {
                throw new PromptGuardException(
                    sprintf(
                        '%s thiếu placeholder bắt buộc %s — giá trị đã tính ra sẽ bị vứt đi lặng lẽ, Claude không bao giờ nhận được.',
                        $field,
                        implode(' ', $problem['missing']),
                    ),
                    field: $field,
                );
            }

            throw new PromptGuardException(
                sprintf(
                    '%s chứa placeholder không giải được %s — hợp lệ cho field này chỉ có: %s.',
                    $field,
                    implode(' ', $problem['unknown']),
                    implode(' ', array_map(
                        fn ($k) => "{{$k}}",
                        PromptBuilderService::PLACEHOLDERS[$field]
                    )) ?: '(không có)',
                ),
                field: $field,
            );
        }
    }

    public function validate(string $bestHook, string $structureTemplate): void
    {
        $this->validateHook($bestHook);
        $this->validateStructureTemplate($structureTemplate);
    }
}
