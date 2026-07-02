<?php

namespace App\Services\AI\SceneGraph\Enums;

enum StoryPhase: string
{
    case SETUP   = 'setup';
    case BUILD   = 'build';
    case CLIMAX  = 'climax';
    case RESOLVE = 'resolve';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower($value)) ?? self::BUILD;
    }

    /** Tonal prefix injected into the SCENE section of a prompt. */
    public function sceneLabel(): string
    {
        return match ($this) {
            self::SETUP   => 'Opening shot. ',
            self::CLIMAX  => 'Climactic moment. ',
            self::RESOLVE => 'Resolution. ',
            self::BUILD   => '',
        };
    }
}
