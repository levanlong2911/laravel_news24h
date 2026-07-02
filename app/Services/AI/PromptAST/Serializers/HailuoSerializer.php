<?php

namespace App\Services\AI\PromptAST\Serializers;

use App\Services\AI\PromptAST\PromptAST;

/**
 * Serializes PromptAST → Hailuo (MiniMax) prompt string.
 *
 * Sprint 7: implement full Hailuo format.
 * Hailuo responds well to concise, directive-style prompts.
 * Model-specific lookup tables will live here.
 */
final class HailuoSerializer implements PromptSerializer
{
    public function serialize(PromptAST $ast): string
    {
        $cam     = $ast->camera->camType->value;
        $emotion = $ast->cinematic->emotion->label();

        return "{$cam} shot. {$emotion}. " . ($ast->cinematic->goal ?: 'Cinematic action.');
    }
}
