<?php

namespace App\Video\Director;

use App\Video\Llm\LlmClient;
use App\Video\Llm\LlmRequest;
use App\Video\Producer\ProducerOutput;
use App\Video\World\VerifiedWorldGraph;

/**
 * Director chay tren mot model that. Chi dung cho integration test / ghi lan
 * dau (co duyet) — giong ClaudeExtractor/ClaudeProducer. CI dung FakeDirector.
 */
final class ClaudeDirector implements DirectorInterface
{
    public const INSTRUCTION_VERSION = 'director-v1';

    public function __construct(
        private readonly LlmClient $llm,
        private readonly string $model = 'sonnet',
    ) {
    }

    public function select(array $candidates, VerifiedWorldGraph $world, ?ProducerOutput $producer): ActionSelection
    {
        $request = new LlmRequest(
            $this->instruction(),
            $this->renderContext($candidates, $world, $producer),
            self::INSTRUCTION_VERSION,
            $this->model,
        );

        $response = $this->llm->complete($request);

        return $this->parse($response->text, $candidates);
    }

    private function renderContext(array $candidates, VerifiedWorldGraph $world, ?ProducerOutput $producer): string
    {
        $lines = ['HERO CANDIDATES:'];
        foreach ($candidates['hero_candidates'] as $id) {
            $entity = $world->entity($id);
            $lines[] = sprintf('- %s (%s)', $id, $entity?->type->value ?? 'unknown');
        }

        $lines[] = '';
        $lines[] = 'ACTION CANDIDATES (chọn bằng index):';
        foreach ($candidates['action_candidates'] as $i => $action) {
            $modifiers = $action->modifiers === [] ? '' : ' (' . implode(', ', $action->modifiers) . ')';
            $lines[] = sprintf('[%d] %s: %s -> %s%s', $i, $action->type->value, $action->actor, $action->target, $modifiers);
        }

        if ($producer !== null) {
            $lines[] = '';
            $lines[] = 'STORY CONTEXT:';
            $lines[] = 'Core conflict: ' . $producer->coreConflict;
            $lines[] = 'Visual promise: ' . $producer->visualPromise;
        }

        return implode("\n", $lines);
    }

    private function parse(string $text, array $candidates): ActionSelection
    {
        $s = trim($text);
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $s, $m)) {
            $s = trim($m[1]);
        }

        $data = json_decode($s, true);
        if (! is_array($data)) {
            $data = [];
        }

        return new ActionSelection(
            (string) ($data['hero'] ?? ($candidates['hero_candidates'][0] ?? '')),
            (int) ($data['primary_index'] ?? 0),
            is_array($data['secondary_indices'] ?? null) ? array_map('intval', $data['secondary_indices']) : [],
            (string) ($data['emotion'] ?? ''),
            (string) ($data['reveal'] ?? ''),
        );
    }

    private function instruction(): string
    {
        return <<<'TEXT'
        You are a film director choosing among pre-generated, physically valid options for
        one scene. You do not invent new actions or entities — you only select indices from
        the list given to you, and describe the emotional intent.

        Return ONLY raw JSON, no markdown fences, no commentary:

        {
          "hero": "entity id from HERO CANDIDATES",
          "primary_index": 0,
          "secondary_indices": [1, 2],
          "emotion": "one or two words describing what the audience should feel",
          "reveal": "how this scene reveals information (immediate/delayed/withheld etc.)"
        }

        Only use indices that exist in ACTION CANDIDATES. Only use an entity id that exists
        in HERO CANDIDATES for "hero". Ground your choice in the story context given, not on
        outside knowledge.
        TEXT;
    }
}
