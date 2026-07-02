<?php

namespace App\Services\AI\PromptAST\Serializers;

use App\Services\AI\PromptAST\PromptAST;

/**
 * Serializes PromptAST → Google Veo 3 prompt string.
 *
 * Sprint 7: implement full Veo 3 format.
 * Veo expects natural-language paragraphs, not labeled sections.
 * Model-specific lookup tables will live here — not in PromptAST or Assembler.
 */
final class VeoSerializer implements PromptSerializer
{
    public function serialize(PromptAST $ast): string
    {
        // Sprint 7 stub — returns a minimal single-paragraph prompt
        $sceneTitle = $ast->scene->sceneTitle !== '' ? $ast->scene->sceneTitle . '. ' : '';
        $emotion    = $ast->cinematic->emotion->label();
        $goal       = $ast->cinematic->goal !== '' ? $ast->cinematic->goal . '. ' : '';

        return "{$sceneTitle}{$goal}Mood: {$emotion}.";
    }
}
