<?php

namespace App\Services\AI\PromptCompiler;

use App\Services\AI\PromptCompiler\Renderers\FluxRenderer;
use App\Services\AI\PromptCompiler\Renderers\KenBurnsRenderer;
use App\Services\AI\PromptCompiler\Renderers\KlingRenderer;

/**
 * Stateless entry point: compile(dsl, provider, ?profile) → prompt string.
 *
 * Pipeline: DSL → PromptDocumentBuilder → PromptDocument → Renderer(profile) → string
 *
 * Adding a new AI provider = new Renderer + one match arm here.
 * RenderProfile is optional for Sprint 1 (Decision Engine picks it in Phase B).
 */
final class Compiler
{
    public function compile(
        array $dsl,
        string $provider,
        ?RenderProfile $profile = null,
        ?string $continuityAnchor = null,
    ): string {
        $doc = PromptDocumentBuilder::build($dsl, $continuityAnchor);

        return match ($provider) {
            'flux'     => FluxRenderer::render($doc, $profile),
            'kling'    => KlingRenderer::render($doc, $dsl, $profile),
            'kenburns' => KenBurnsRenderer::render($doc, $dsl, $profile),
            default    => FluxRenderer::render($doc, $profile),
        };
    }
}
