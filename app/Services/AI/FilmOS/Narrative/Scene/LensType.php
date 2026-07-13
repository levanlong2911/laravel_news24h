<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Scene;

/**
 * Semantic lens category for shot composition.
 *
 * Maps to prompt language used by AI models:
 *   WIDE      → "24mm wide cinematic"   — landscape, establishing, compression-free
 *   NORMAL    → "35mm–50mm natural"     — standard narrative, closest to human eye
 *   TELEPHOTO → "85mm–135mm portrait"   — facial detail, background compression
 *
 * Not a focal length in millimetres — PromptCompiler decides the exact phrasing.
 * This is the semantic intent, not a render parameter.
 */
enum LensType: string
{
    case WIDE      = 'wide';
    case NORMAL    = 'normal';
    case TELEPHOTO = 'telephoto';
}
