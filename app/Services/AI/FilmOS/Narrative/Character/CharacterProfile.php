<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Character;

use App\Services\AI\FilmOS\Narrative\Shared\AttributeBag;

/**
 * Who a character is and what they look like — the introduction snapshot.
 *
 * $appearance holds the visual continuity contract for AI rendering:
 * "outfit" => "black suit", "hair" => "short dark" — PromptCompiler repeats
 * these in every shot's prompt so the character stays visually consistent.
 *
 * worldObjectRef: optional cross-reference to a D3 WorldObject by objectId.
 * A string, not an import — Character does not depend on the World domain.
 */
final class CharacterProfile
{
    public function __construct(
        public readonly string       $id,
        public readonly string       $label,
        public readonly AttributeBag $appearance,
        public readonly ?string      $worldObjectRef = null,
    ) {}
}
