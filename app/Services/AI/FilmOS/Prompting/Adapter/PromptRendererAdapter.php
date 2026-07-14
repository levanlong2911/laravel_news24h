<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Adapter;

use App\Services\AI\FilmOS\Prompting\IR\ShotPrompt;

/**
 * Vendor boundary — where organized knowledge becomes vendor language.
 *
 * ALL prompt phrasing lives here: TELEPHOTO → "85mm telephoto compression",
 * FEAR/intense → "terrified", 'weather'=>'cold' → "cold breath vapor".
 * One adapter per provider (kling / veo / runway…); FilmOS never locks
 * into a single vendor's prompt syntax.
 *
 * Adapters read ONLY the IR — never View interfaces, never World domain
 * types (PromptEnvironment already flattened them), never Timeline classes.
 *
 * Implementations must derive anatomy/subject constraints from typed IR
 * knowledge (emotions present ⇒ human subjects; camera focus id…) — never
 * by regex-guessing strings (the Sprint-3 yacht lesson).
 */
interface PromptRendererAdapter
{
    /** Provider this adapter renders for: 'kling' | 'veo' | 'runway' | … */
    public function providerId(): string;

    /** Renders one shot's IR into this vendor's prompt string. */
    public function render(ShotPrompt $prompt): string;
}
