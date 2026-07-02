<?php

namespace App\Services\AI\SceneGraph\Enums;

enum Emotion: string
{
    case HOOK   = 'HOOK';
    case POWER  = 'POWER';
    case EPIC   = 'EPIC';
    case JOY    = 'JOY';
    case TENSE  = 'TENSE';
    case AWE    = 'AWE';
    case DRAMA  = 'DRAMA';
    case REVEAL = 'REVEAL';
    case CALM   = 'CALM';
    case CRAFT  = 'CRAFT';
    case FEAR   = 'FEAR';

    public static function fromDsl(string $code): self
    {
        return self::tryFrom(strtoupper($code)) ?? self::CRAFT;
    }

    public function storyPhase(): StoryPhase
    {
        return match ($this) {
            self::HOOK, self::REVEAL                      => StoryPhase::SETUP,
            self::TENSE, self::DRAMA, self::CRAFT         => StoryPhase::BUILD,
            self::POWER, self::EPIC, self::AWE, self::JOY => StoryPhase::CLIMAX,
            self::CALM, self::FEAR                        => StoryPhase::RESOLVE,
        };
    }

    /** Lowercase label for prompt output. */
    public function label(): string
    {
        return strtolower($this->value);
    }
}
