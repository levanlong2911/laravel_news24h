<?php

declare(strict_types=1);

namespace App\Video\Scene;

use App\Video\Prompt\TextCompletionClient;
use JsonException;

final class ScenePlanAuthor
{
    private const SPLIT_MARKER = 'PLANNING INPUT:';

    public function __construct(
        private readonly TextCompletionClient $client,
        private readonly string $promptPath,
        private readonly string $promptVersion,
        private readonly string $model,
        private readonly int $maxTokens,
        private readonly int $maxScenes,
    ) {}

    /**
     * @param  array<string, mixed>  $planningInput
     * @param  ?callable(): void  $onAttempt  fired once the request is built, immediately before the client call
     */
    public function plan(
        array $planningInput,
        SceneProfile $profile,
        ?callable $onAttempt = null,
    ): ScenePlanResult {
        if (array_key_exists('profile', $planningInput)) {
            throw new ScenePlanException('Planning input may not carry its own profile.');
        }

        if ($this->maxScenes < $profile->minScenes) {
            throw new ScenePlanException(
                'video.scene_plan.max_scenes ('.$this->maxScenes
                .') is below the profile min_scenes ('.$profile->minScenes.').'
            );
        }

        $system = $this->system();
        $user = $this->user(array_replace($planningInput, ['profile' => $profile->toPayload()]));
        $schema = $this->schema($profile);

        if ($this->maxTokens < 1) {
            throw new ScenePlanException('video.scene_plan.max_tokens must be >= 1.');
        }

        if ($onAttempt !== null) {
            $onAttempt();
        }

        $response = $this->client->complete(
            model: $this->model,
            system: $system,
            user: $user,
            maxTokens: $this->maxTokens,
            outputSchema: $schema,
        );

        $usage = [
            'provider_model' => $response->model,
            'tokens_in' => $response->inputTokens,
            'tokens_out' => $response->outputTokens,
            'thinking_tokens' => $response->reasoningTokens,
        ];

        if ($response->wasTruncated()) {
            throw new ScenePlanException(
                'Scene plan was cut off at the token limit; raise video.scene_plan.max_tokens.',
                $response->text,
                $usage,
            );
        }

        try {
            $decoded = json_decode($response->text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ScenePlanException(
                'Scene plan response was not valid JSON: '.$e->getMessage(),
                $response->text,
                $usage,
            );
        }

        if (! is_array($decoded)
            || ! is_array($decoded['scenes'] ?? null)
            || ! array_is_list($decoded['scenes'])) {
            throw new ScenePlanException(
                'Scene plan response carried no scenes list.',
                $response->text,
                $usage,
            );
        }

        return new ScenePlanResult(
            scenes: array_values($decoded['scenes']),
            raw: $response->text,
            authorModel: $response->model,
            inputTokens: $response->inputTokens,
            outputTokens: $response->outputTokens,
            reasoningTokens: $response->reasoningTokens,
        );
    }

    public function promptVersion(): string
    {
        return $this->promptVersion;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function maxScenes(): int
    {
        return $this->maxScenes;
    }

    public function skillHash(): string
    {
        return hash('sha256', $this->skill());
    }

    private function skill(): string
    {
        if (! is_file($this->promptPath)) {
            throw new ScenePlanException('Scene plan skill not found: '.$this->promptPath);
        }

        return (string) file_get_contents($this->promptPath);
    }

    private function system(): string
    {
        $skill = $this->skill();
        $seen = substr_count($skill, self::SPLIT_MARKER);

        if ($seen !== 1) {
            throw new ScenePlanException(
                'Scene plan skill must carry "'.self::SPLIT_MARKER.'" exactly once, found '.$seen.'.'
            );
        }

        return trim(substr($skill, 0, (int) strpos($skill, self::SPLIT_MARKER)));
    }

    /** @param array<string, mixed> $planningInput */
    private function user(array $planningInput): string
    {
        return self::SPLIT_MARKER."\n\n".json_encode(
            $planningInput,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Version hoa RIENG khoi scene item, doc lap voi `prompt_version`: prompt
     * co the sua chu ma hop dong khong doi, va nguoc lai. Vao claim input de
     * dedup phan biet duoc hai hop dong.
     */
    public const SCENE_CONTRACT_VERSION = 'scene-contract-v2';

    /** @return array<string, mixed> */
    public static function sceneItemSchema(SceneProfile $profile): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'scene_code', 'title', 'purpose', 'phase', 'milestone_keys',
                'basis', 'state_before', 'scene_state', 'transition_mode',
                'continuity_group', 'source_scene_code', 'camera_change_reason',
                'camera_mode', 'delta', 'video',
            ],
            'properties' => [
                'scene_code' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{2,59}$'],
                'title' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 120],
                'purpose' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 500],
                'phase' => ['type' => 'string', 'enum' => $profile->phaseKeys()],
                'milestone_keys' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => $profile->maxMilestonesPerScene,
                    'items' => ['type' => 'string', 'enum' => $profile->milestoneKeys()],
                ],
                'basis' => [
                    'type' => 'string',
                    'enum' => ['source_supported', 'inferred_process'],
                ],
                'state_before' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 120],
                'scene_state' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 120],
                'transition_mode' => [
                    'type' => 'string',
                    'enum' => ScenePreservationPrompt::modes(),
                ],
                'continuity_group' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{2,59}$'],
                'source_scene_code' => ['type' => 'string', 'maxLength' => 60],
                'camera_change_reason' => ['type' => 'string', 'maxLength' => 300],
                'camera_mode' => ['type' => 'string', 'enum' => ['locked']],
                'delta' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1000],
                'video' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['action', 'preserve', 'end_state'],
                    'properties' => [
                        'action' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1000],
                        'preserve' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
                        'end_state' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 120],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function schema(SceneProfile $profile): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['scenes'],
            'properties' => [
                'scenes' => [
'type' => 'array',
'minItems' => $profile->minScenes,
'maxItems' => $this->maxScenes,
'items' => self::sceneItemSchema($profile),
                ],
            ],
        ];
    }
}
