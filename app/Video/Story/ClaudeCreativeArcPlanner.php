<?php

namespace App\Video\Story;

use App\Video\Concept\CreativeConcept;
use App\Video\Inspiration\CategoryCreativeProfile;
use App\Video\Llm\LlmClient;
use App\Video\Llm\LlmRequest;
use JsonException;

final class ClaudeCreativeArcPlanner
{
    private const INSTRUCTION_VERSION = 'creative-arc-v2';

    private const MIN_SCENES = 4;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly string $model = 'sonnet',
    ) {}

    /** @return array<string, array<string, mixed>> */
    public function plan(CreativeConcept $concept, CategoryCreativeProfile $profile): array
    {
        $profile->assertArcReady();

        $response = $this->llm->complete(new LlmRequest(
            $this->instruction($profile),
            json_encode($concept->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            self::INSTRUCTION_VERSION,
            $this->model,
            maxTokens: 8000,
            temperature: 0.7,
        ));

        $scenes = $this->parse($response->text);
        $violations = $this->violations($scenes, $profile);
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

    private function instruction(CategoryCreativeProfile $profile): string
    {
        $stages = implode('|', $profile->arcStages);
        $first = $profile->arcStages[0];
        $last = $profile->arcStages[array_key_last($profile->arcStages)];
        $required = implode(', ', $profile->arcRequiredStages);
        $min = self::MIN_SCENES;

        return <<<TEXT
You are the creative arc planner. Turn the supplied original product concept into
a visual sequence whose scene count and progression serve that specific design.

Return JSON only: {"scenes":[...]}. Create at least {$min} scenes — decide the
number yourself so the count serves this specific design. The sequence must begin
with {$first}, end with {$last}, and include every one of: {$required}.
Stages may repeat but may never move backwards. Every scene must reveal new
information. Do not copy names, brands, owners, builders, or products.

Each scene must contain exactly:
- stage: {$stages}
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
    private function violations(array $scenes, CategoryCreativeProfile $profile): array
    {
        $stages = $profile->arcStages;
        $violations = [];
        if (count($scenes) < self::MIN_SCENES) {
            $violations[] = 'scenes must contain at least '.self::MIN_SCENES.' items';
        }

        $lastStage = -1;
        $seen = [];
        foreach ($scenes as $index => $scene) {
            $path = "scenes[{$index}]";
            if (! is_array($scene)) {
                $violations[] = "{$path} must be an object";

                continue;
            }

            $this->validateScene($scene, $path, $violations, $stages);
            $stage = array_search($scene['stage'] ?? null, $stages, true);
            if ($stage !== false) {
                if ($stage < $lastStage) {
                    $violations[] = "{$path}.stage moves backwards";
                }
                $lastStage = $stage;
                $seen[$scene['stage']] = true;
            }
        }

        foreach ($profile->arcRequiredStages as $required) {
            if (! isset($seen[$required])) {
                $violations[] = "stage {$required} is required";
            }
        }

        $first = $stages[0];
        $last = $stages[array_key_last($stages)];

        if (($scenes[0]['stage'] ?? null) !== $first) {
            $violations[] = "the first scene must be {$first}";
        }
        $lastScene = $scenes === [] ? null : $scenes[array_key_last($scenes)];
        if (($lastScene['stage'] ?? null) !== $last) {
            $violations[] = "the final scene must be {$last}";
        }

        return $violations;
    }

    /** @param array<string, mixed> $scene
     * @param  list<string>  $violations
     */
    private function validateScene(array $scene, string $path, array &$violations, array $stages): void
    {
        $allowed = ['stage', 'purpose', 'objective', 'motion_intent', 'camera', 'aesthetic', 'composition_note', 'micro_physics'];
        if (array_diff(array_keys($scene), $allowed) !== [] || array_diff($allowed, array_keys($scene)) !== []) {
            $violations[] = "{$path} must declare exactly the creative arc fields";

            return;
        }

        $this->enum($scene, 'stage', $stages, $path, $violations);
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
