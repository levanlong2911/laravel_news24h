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
    public function validate(string $bestHook, string $structureTemplate): void
    {
        $this->validateHook($bestHook);
        $this->validateStructureTemplate($structureTemplate);
    }
}
