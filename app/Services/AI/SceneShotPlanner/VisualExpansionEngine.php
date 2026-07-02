<?php

namespace App\Services\AI\SceneShotPlanner;

use App\DTOs\BeatDTO;
use App\DTOs\VisualMomentDTO;
use App\Services\Admin\ClaudeWriterService;
use App\Services\AI\PromptCompiler\Libraries\ShotGrammarLibrary;

/**
 * Claude Haiku call: BeatDTO → VisualMomentDTO[]
 *
 * Claude generates: visual_intent, subject, action, duration_hint, importance.
 * Code assigns: beat_number, moment_index.
 *
 * This is the ONLY place in SceneShotPlanner that calls an AI model.
 * All downstream steps (ShotGrammarEngine, DSLBuilder) are pure PHP.
 */
final class VisualExpansionEngine
{
    public function __construct(
        private readonly ClaudeWriterService $claude,
    ) {}

    /**
     * @return VisualMomentDTO[]
     * @throws \RuntimeException if Claude returns unusable JSON
     */
    public function expand(BeatDTO $beat): array
    {
        $density     = ShotGrammarLibrary::densityFor($beat->informationType);
        $momentCount = ShotGrammarLibrary::momentCount($density);
        $prompt      = Prompt::build($beat, $momentCount);

        $response = $this->claude->generate($prompt, 'haiku', '');
        $parsed   = $this->parseJson($response->text);

        if ($parsed === null || empty($parsed['moments'])) {
            throw new \RuntimeException(
                "VisualExpansionEngine: Claude returned unparseable JSON for beat {$beat->beatNumber}"
            );
        }

        return array_values(array_map(
            fn (array $m, int $i) => VisualMomentDTO::fromArray($m, $beat->beatNumber, $i + 1),
            $parsed['moments'],
            array_keys($parsed['moments']),
        ));
    }

    private function parseJson(string $text): ?array
    {
        $text    = trim(preg_replace(['/^```(?:json)?\s*/i', '/\s*```$/'], '', $text));
        $decoded = json_decode($text, true);
        return is_array($decoded) ? $decoded : null;
    }
}
