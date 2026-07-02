<?php

namespace App\Services\AI\PromptCompiler\Renderers;

use App\Services\AI\PromptCompiler\PromptDocument\PromptDocument;
use App\Services\AI\PromptCompiler\RenderProfile;

/**
 * Renders PromptDocument → Ken Burns effect descriptor.
 *
 * Format:
 *   SOURCE IMAGE: {flux-like image description}
 *   [EFFECT: slow_zoom_in, pan: center, duration: Xs]
 *
 * The image generation engine reads SOURCE IMAGE.
 * The Ken Burns engine reads EFFECT parameters for animation.
 */
final class KenBurnsRenderer
{
    public static function render(PromptDocument $doc, array $dsl = [], ?RenderProfile $profile = null): string
    {
        $dur = $dsl['dur'] ?? 3.0;

        // Image description (same quality as Flux — it IS Flux output)
        $imageParts = [];
        $cam        = $doc->camera;
        $imageParts[] = ucfirst($cam->type) . ', ' . $cam->lens;

        if ($doc->subject->enrichedSentence !== '') {
            $imageParts[] = ucfirst($doc->subject->enrichedSentence);
        }

        if ($doc->environment->description !== '') {
            $envFirst     = explode('.', $doc->environment->description)[0];
            $imageParts[] = $envFirst;
        }

        if ($doc->emotion->modifiers !== []) {
            $imageParts[] = implode(' ', $doc->emotion->modifiers);
        }

        if ($doc->quality->phrases !== []) {
            $imageParts[] = implode(' ', $doc->quality->phrases);
        }

        $imagePrompt = implode(', ', array_filter($imageParts));

        return "SOURCE IMAGE: {$imagePrompt}.\n[EFFECT: slow_zoom_in, pan: center, duration: {$dur}s]";
    }
}
