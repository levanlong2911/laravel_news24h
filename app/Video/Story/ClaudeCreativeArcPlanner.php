<?php

namespace App\Video\Story;

use App\Video\Concept\CreativeConcept;
use App\Video\Llm\LlmClient;
use App\Video\Llm\LlmRequest;
use JsonException;

final class ClaudeCreativeArcPlanner
{
    private const INSTRUCTION_VERSION = 'creative-arc-v1';

    private const MIN_SCENES = 4;

    private const MAX_SCENES = 10;

    /** @var list<string> */
    private const STAGES = ['design', 'construction', 'finishing', 'completion', 'operation'];

    public function __construct(
        private readonly LlmClient $llm,
        private readonly string $model = 'sonnet',
    ) {}

    /** @return array<string, array<string, mixed>> */
    public function plan(CreativeConcept $concept): array
    {
        $response = $this->llm->complete(new LlmRequest(
            $this->instruction(),
            json_encode($concept->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            self::INSTRUCTION_VERSION,
            $this->model,
            maxTokens: 3000,
            temperature: 0.7,
        ));

        $scenes = $this->parse($response->text);
        $violations = $this->violations($scenes);
        if ($violations !== []) {
            throw new InvalidCreativeArc($violations);
        }

        $phases = [];
        foreach ($scenes as $index => $scene) {
            $phases[sprintf('%02d_%s', $index + 1, $scene['stage'])] = [
                'purpose' => $scene['purpose'],
                'objective' => $scene['objective'],
                'motion_intent' => $scene['motion_intent'],
                'camera' => $scene['camera'],
                'aesthetic' => $scene['aesthetic'],
                'composition_note' => $scene['composition_note'],
                'micro_physics' => $scene['micro_physics'],
            ];
        }

        return $phases;
    }

    private function instruction(): string
    {
        return <<<'TEXT'
You are the creative arc planner. Turn the supplied original product concept into
a visual sequence whose scene count and progression serve that specific design.

Return JSON only: {"scenes":[...]}. Create 4 to 10 scenes. The sequence must
begin with design, include construction, reach completion, and end in operation.
Stages may repeat but may never move backwards. Every scene must reveal new
information. Do not copy names, brands, owners, builders, or products.

Each scene must contain exactly:
- stage: design|construction|finishing|completion|operation
- purpose: ESTABLISH|PROCESS|DETAIL|REVEAL|ACTION|RESOLUTION
- objective: one concise editorial goal
- motion_intent: NONE|LOW|HIGH
- camera: {framing: WIDE|MEDIUM|CLOSE|DETAIL|AERIAL,
  movement: STATIC|ORBIT|PUSH_IN|PULL_OUT|PAN|TRACK,
  speed: SLOW|MEDIUM|FAST}
- aesthetic: {emotion: MAJESTIC|TENSE|CALM|DRAMATIC|TRIUMPHANT|SOMBRE,
  composition: CENTERED|RULE_OF_THIRDS|SYMMETRICAL|LEADING_LINES,
  light_intensity: SOFT|NEUTRAL|HARSH,
  light_grade: WARM|COOL|NEUTRAL|GOLDEN|NOIR}
- composition_note: what is visibly arranged in the shot
- micro_physics: zero to three observable changes suitable for motion

Use only the supplied concept. Decide the number of scenes yourself. Do not
mention providers, prompts, lenses, render settings, or implementation details.
TEXT;
    }

    /** @return list<array<string, mixed>> */
    private function parse(string $raw): array
    {
        $json = trim($raw);
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $json, $match) === 1) {
            $json = $match[1];
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidCreativeArc(['response is not valid JSON']);
        }

        return is_array($data) && isset($data['scenes']) && is_array($data['scenes'])
            ? array_values($data['scenes'])
            : [];
    }

    /** @param list<array<string, mixed>> $scenes
     * @return list<string>
     */
    private function violations(array $scenes): array
    {
        $violations = [];
        $count = count($scenes);
        if ($count < self::MIN_SCENES || $count > self::MAX_SCENES) {
            $violations[] = 'scenes must contain between 4 and 10 items';
        }

        $lastStage = -1;
        $seen = [];
        foreach ($scenes as $index => $scene) {
            $path = "scenes[{$index}]";
            if (! is_array($scene)) {
                $violations[] = "{$path} must be an object";

                continue;
            }

            $this->validateScene($scene, $path, $violations);
            $stage = array_search($scene['stage'] ?? null, self::STAGES, true);
            if ($stage !== false) {
                if ($stage < $lastStage) {
                    $violations[] = "{$path}.stage moves backwards";
                }
                $lastStage = $stage;
                $seen[$scene['stage']] = true;
            }
        }

        foreach (['design', 'construction', 'completion', 'operation'] as $required) {
            if (! isset($seen[$required])) {
                $violations[] = "stage {$required} is required";
            }
        }
        if (($scenes[0]['stage'] ?? null) !== 'design') {
            $violations[] = 'the first scene must be design';
        }
        $lastScene = $scenes === [] ? null : $scenes[array_key_last($scenes)];
        if (($lastScene['stage'] ?? null) !== 'operation') {
            $violations[] = 'the final scene must be operation';
        }

        return $violations;
    }

    /** @param array<string, mixed> $scene
     * @param  list<string>  $violations
     */
    private function validateScene(array $scene, string $path, array &$violations): void
    {
        $allowed = ['stage', 'purpose', 'objective', 'motion_intent', 'camera', 'aesthetic', 'composition_note', 'micro_physics'];
        if (array_diff(array_keys($scene), $allowed) !== [] || array_diff($allowed, array_keys($scene)) !== []) {
            $violations[] = "{$path} must declare exactly the creative arc fields";

            return;
        }

        $this->enum($scene, 'stage', self::STAGES, $path, $violations);
        $this->enum($scene, 'purpose', ['ESTABLISH', 'PROCESS', 'DETAIL', 'REVEAL', 'ACTION', 'RESOLUTION'], $path, $violations);
        $this->enum($scene, 'motion_intent', ['NONE', 'LOW', 'HIGH'], $path, $violations);
        foreach (['objective', 'composition_note'] as $field) {
            if (! is_string($scene[$field]) || trim($scene[$field]) === '') {
                $violations[] = "{$path}.{$field} must not be empty";
            }
        }

        $camera = $scene['camera'];
        if (! is_array($camera) || ! $this->hasExactly($camera, ['framing', 'movement', 'speed'])) {
            $violations[] = "{$path}.camera has an invalid shape";
        } else {
            $this->enum($camera, 'framing', ['WIDE', 'MEDIUM', 'CLOSE', 'DETAIL', 'AERIAL'], "{$path}.camera", $violations);
            $this->enum($camera, 'movement', ['STATIC', 'ORBIT', 'PUSH_IN', 'PULL_OUT', 'PAN', 'TRACK'], "{$path}.camera", $violations);
            $this->enum($camera, 'speed', ['SLOW', 'MEDIUM', 'FAST'], "{$path}.camera", $violations);
        }

        $aesthetic = $scene['aesthetic'];
        if (! is_array($aesthetic) || ! $this->hasExactly($aesthetic, ['emotion', 'composition', 'light_intensity', 'light_grade'])) {
            $violations[] = "{$path}.aesthetic has an invalid shape";
        } else {
            $this->enum($aesthetic, 'emotion', ['MAJESTIC', 'TENSE', 'CALM', 'DRAMATIC', 'TRIUMPHANT', 'SOMBRE'], "{$path}.aesthetic", $violations);
            $this->enum($aesthetic, 'composition', ['CENTERED', 'RULE_OF_THIRDS', 'SYMMETRICAL', 'LEADING_LINES'], "{$path}.aesthetic", $violations);
            $this->enum($aesthetic, 'light_intensity', ['SOFT', 'NEUTRAL', 'HARSH'], "{$path}.aesthetic", $violations);
            $this->enum($aesthetic, 'light_grade', ['WARM', 'COOL', 'NEUTRAL', 'GOLDEN', 'NOIR'], "{$path}.aesthetic", $violations);
        }

        if (! is_array($scene['micro_physics']) || count($scene['micro_physics']) > 3) {
            $violations[] = "{$path}.micro_physics must contain at most three items";
        } elseif (array_filter($scene['micro_physics'], fn ($item) => ! is_string($item) || trim($item) === '') !== []) {
            $violations[] = "{$path}.micro_physics contains an invalid item";
        }
    }

    /** @param array<string, mixed> $data
     * @param  list<string>  $allowed
     * @param  list<string>  $violations
     */
    private function enum(array $data, string $field, array $allowed, string $path, array &$violations): void
    {
        if (! isset($data[$field]) || ! is_string($data[$field]) || ! in_array($data[$field], $allowed, true)) {
            $violations[] = "{$path}.{$field} is invalid";
        }
    }

    /** @param array<string, mixed> $data
     * @param  list<string>  $keys
     */
    private function hasExactly(array $data, array $keys): bool
    {
        $actual = array_keys($data);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }
}
