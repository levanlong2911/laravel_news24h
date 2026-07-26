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
    public const INSTRUCTION_VERSION = 'director-v2';

    public function __construct(
        private readonly LlmClient $llm,
        private readonly string $model = 'sonnet',
    ) {
    }

    public function select(array $candidates, VerifiedWorldGraph $world, ?ProducerOutput $producer, int $sceneOrdinal = 1, int $totalScenes = 1, array $priorScenes = []): ActionSelection
    {
        $request = new LlmRequest(
            $this->instruction(),
            $this->renderContext($candidates, $world, $producer, $sceneOrdinal, $totalScenes, $priorScenes),
            self::INSTRUCTION_VERSION,
            $this->model,
        );

        $response = $this->llm->complete($request);

        return $this->parse($response->text, $candidates);
    }

    /**
     * @param list<array{ordinal: int, hero: string, emotion: string, composition_note: string}> $priorScenes
     */
    private function renderContext(array $candidates, VerifiedWorldGraph $world, ?ProducerOutput $producer, int $sceneOrdinal, int $totalScenes, array $priorScenes = []): string
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

            // Vị trí trong mạch cảm xúc — thuần số học tỉ lệ (Objective), KHÔNG
            // phải Director tự chọn cảm xúc nào — chỉ cho Director BIẾT đang ở
            // đâu để composition/tông nhất quán xuyên phim. 2026-07-23.
            $curve = $producer->emotionalCurve;
            if ($curve !== [] && $totalScenes > 0) {
                $index = min((int) floor(($sceneOrdinal - 1) / $totalScenes * count($curve)), count($curve) - 1);
                $lines[] = sprintf('Emotional arc position (scene %d/%d): %s', $sceneOrdinal, $totalScenes, $curve[$index]);
            }
        }

        if ($priorScenes !== []) {
            $lines[] = '';
            $lines[] = 'PREVIOUS SCENES (for continuity — do not repeat verbatim, avoid picking the same hero/composition every time unless the story calls for it):';
            foreach ($priorScenes as $prior) {
                $lines[] = sprintf(
                    '- Scene %d: hero=%s, emotion=%s, composition="%s"',
                    $prior['ordinal'],
                    $prior['hero'] === '' ? '(none)' : $prior['hero'],
                    $prior['emotion'] === '' ? '(none)' : $prior['emotion'],
                    $prior['composition_note'],
                );
            }
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
            (string) ($data['composition_note'] ?? ''),
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
          "reveal": "how this scene reveals information (immediate/delayed/withheld etc.)",
          "composition_note": "one sentence describing blocking/framing for this shot"
        }

        Only use indices that exist in ACTION CANDIDATES. Only use an entity id that exists
        in HERO CANDIDATES for "hero". Ground your choice in the story context given, not on
        outside knowledge.

        composition_note — this is where you act as a director, not a database:

        Write ONE sentence describing how to BLOCK this shot: what sits in the foreground,
        what recedes into the background, what the eye should land on first. This is framing
        and emphasis, not new content.

        You may ONLY refer to the hero you picked, the action you picked (primary/secondary),
        and entities already listed in HERO CANDIDATES. Do NOT introduce any person, object,
        crowd, weather, or detail that is not already present in the candidates given to you —
        that would be inventing a fact, not composing a shot. If you cannot describe a
        composition using only what's given, leave composition_note as an empty string rather
        than add something new.

        Bad (invents a crowd never given to you): "the vehicle in foreground, a cheering crowd
        blurred behind it"
        Good (uses only the hero already chosen): "the vehicle dominates the frame, its surface
        catching the light, everything else receding into soft focus"

        If PREVIOUS SCENES is given, you are the same director who shot those scenes moments
        ago — keep them in mind the way a real director holds the whole film in their head
        while shooting one shot. Vary hero and composition across scenes unless repeating one
        is itself the storytelling choice (e.g. returning to the same hero to build tension).
        Do not contradict a prior scene's emotion without narrative reason.
        TEXT;
    }
}
