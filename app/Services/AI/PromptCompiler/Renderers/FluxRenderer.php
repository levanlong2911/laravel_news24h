<?php

namespace App\Services\AI\PromptCompiler\Renderers;

use App\Services\AI\PromptCompiler\PromptDocument\PromptDocument;
use App\Services\AI\PromptCompiler\RenderProfile;

/**
 * Renders PromptDocument → Flux image generation prompt.
 *
 * Format: semantic paragraph blocks separated by blank lines.
 * Block order: Camera → Subject → Environment → Emotion → Quality[+Profile]
 */
final class FluxRenderer
{
    public static function render(PromptDocument $doc, ?RenderProfile $profile = null): string
    {
        $blocks = [];

        // Camera block
        $cam         = $doc->camera;
        $lensArticle = self::article($cam->lens);
        $cameraLines = [ucfirst($cam->type) . " using {$lensArticle} {$cam->lens}."];
        if (!$cam->isStatic) {
            $cameraLines[] = ucfirst($cam->move) . '.';
        }
        $blocks[] = implode("\n", $cameraLines);

        // Subject block
        if ($doc->subject->enrichedSentence !== '') {
            $blocks[] = ucfirst($doc->subject->enrichedSentence) . '.';
        }

        // Environment block
        if ($doc->environment->description !== '') {
            $blocks[] = $doc->environment->description;
        }

        // Emotion block
        if ($doc->emotion->modifiers !== []) {
            $blocks[] = implode("\n", $doc->emotion->modifiers);
        }

        // Quality block + optional profile additions
        $qualityPhrases = $doc->quality->phrases;
        if ($profile !== null && $profile->styleAdditions !== []) {
            $qualityPhrases = array_merge($qualityPhrases, $profile->styleAdditions);
        }
        if ($qualityPhrases !== []) {
            $blocks[] = implode("\n", $qualityPhrases);
        }

        // Continuity block (shots 2+ only — injected by ContinuityEngine)
        if ($doc->continuity !== null && $doc->continuity->anchor !== '') {
            $blocks[] = 'Visual continuity: maintain identical appearance — ' . $doc->continuity->anchor . '.';
        }

        return implode("\n\n", array_filter($blocks));
    }

    /** Returns "an" if word starts with a vowel sound or digit '8'. */
    private static function article(string $word): string
    {
        if (preg_match('/^8/', $word)) return 'an';
        if (preg_match('/^[aeiou]/i', $word)) return 'an';
        return 'a';
    }
}
