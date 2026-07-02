<?php

namespace App\Services\AI\PromptCompiler\Libraries;

final class EmotionLibrary
{
    public const VERSION = '1.0';

    private const EMOTIONS = [
        'HOOK' => [
            'modifiers'  => ['Immediately arresting.', 'Compels attention.', 'Irresistible visual draw.'],
            'action_adv' => 'dramatically',
        ],
        'CRAFT' => [
            'modifiers'  => ['Quiet craftsmanship.', 'Intimate precision.', 'Premium handmade engineering.'],
            'action_adv' => 'carefully',
        ],
        'AWE' => [
            'modifiers'  => ['Breathtaking scale.', 'Stunning grandeur.', 'Awe-inspiring beauty.'],
            'action_adv' => 'majestically',
        ],
        'TENSE' => [
            'modifiers'  => ['Edge-of-seat tension.', 'High stakes.', 'Suspenseful urgency.'],
            'action_adv' => 'urgently',
        ],
        'DRAMA' => [
            'modifiers'  => ['Cinematic drama.', 'Full emotional impact.', 'Powerful presence.'],
            'action_adv' => 'dramatically',
        ],
        'REVEAL' => [
            'modifiers'  => ['Purposeful revelation.', 'Satisfying discovery.', 'Deliberate unveiling.'],
            'action_adv' => 'purposefully',
        ],
        'CALM' => [
            'modifiers'  => ['Serene atmosphere.', 'Peaceful tranquility.', 'Quiet grace.'],
            'action_adv' => 'serenely',
        ],
        'POWER' => [
            'modifiers'  => ['Raw power.', 'Unbridled force.', 'Visceral energy.'],
            'action_adv' => 'powerfully',
        ],
        'JOY' => [
            'modifiers'  => ['Pure euphoria.', 'Infectious energy.', 'Uplifting spirit.'],
            'action_adv' => 'joyfully',
        ],
        'FEAR' => [
            'modifiers'  => ['Primal dread.', 'Unsettling tension.', 'Ominous atmosphere.'],
            'action_adv' => 'ominously',
        ],
        'EPIC' => [
            'modifiers'  => ['Epic grandeur.', 'Sweeping magnitude.', 'Monumental scale.'],
            'action_adv' => 'epically',
        ],
    ];

    public static function modifiers(string $emo): array
    {
        return self::EMOTIONS[$emo]['modifiers'] ?? [$emo . '.'];
    }

    public static function actionAdverb(string $emo): string
    {
        return self::EMOTIONS[$emo]['action_adv'] ?? '';
    }
}
