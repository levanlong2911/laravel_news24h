<?php

declare(strict_types=1);

namespace App\Video\Superyacht\Script;

final class ScriptProjection
{
    public const VERSION = 'phase4-script-projection-v2';

    /**
     * @param  array<string, mixed>  $document
     * @return array{version: string, story: array{reveal_plan: list<array{beat: string, reveal: string}>, emotion_curve: list<array{beat: string, emotion: int}>}, tour: array<string, mixed>}
     */
    public static function of(array $document): array
    {
        $beats = (array) ($document['data']['script']['beats'] ?? []);

        return [
            'version' => self::VERSION,
            'story' => [
                'reveal_plan' => array_map(static fn (array $beat): array => ['beat' => (string) $beat['id'], 'reveal' => (string) $beat['reveal']], $beats),
                'emotion_curve' => array_map(static fn (array $beat): array => ['beat' => (string) $beat['id'], 'emotion' => (int) $beat['emotion']], $beats),
            ],
            'tour' => (array) $document['data']['script']['tour'],
        ];
    }
}
