<?php

namespace App\Services\AI\PromptAST\Serializers;

use App\Services\AI\PromptAST\PromptAST;

/**
 * Contract for all prompt serializers.
 *
 * Each AI model backend implements this interface.
 * The serialize() method is the only entry point — no extra parameters.
 * All model-specific lookup tables and wording live inside the implementation.
 *
 * Implementing a new model support = writing one new class.
 */
interface PromptSerializer
{
    public function serialize(PromptAST $ast): string;
}
