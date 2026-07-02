<?php

namespace App\Services\AI\PromptAST\Serializers;

use App\Services\AI\PromptAST\PromptAST;

/**
 * Serializes PromptAST → Seedance (ByteDance) prompt string.
 *
 * Sprint 7: implement full Seedance format.
 * Seedance uses a hybrid approach: scene description + camera instruction.
 * Model-specific lookup tables will live here.
 */
final class SeedanceSerializer implements PromptSerializer
{
    public function serialize(PromptAST $ast): string
    {
        $emotion = $ast->cinematic->emotion->label();
        $goal    = $ast->cinematic->goal !== '' ? $ast->cinematic->goal . '. ' : '';

        return "{$goal}Camera: {$ast->camera->camType->value} shot. Mood: {$emotion}.";
    }
}
