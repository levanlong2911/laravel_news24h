<?php

declare(strict_types=1);

namespace App\Video\Scene;

use App\Video\Prompt\TextCompletionClient;
use App\Video\Screenplay\ScreenplayValidator;
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
        private readonly int $maxShots,
        private readonly ?string $beatPromptPath = null,
        private readonly ?string $beatPromptVersion = null,
        private readonly bool $beats = false,
    ) {}

    public function forBeats(): self
    {
        if ($this->beatPromptPath === null || $this->beatPromptVersion === null) {
            throw new ScenePlanException('video.scene_plan.beat_prompt_path and beat_prompt_version must be set.');
        }

        return new self(
            client: $this->client,
            promptPath: $this->beatPromptPath,
            promptVersion: $this->beatPromptVersion,
            model: $this->model,
            maxTokens: $this->maxTokens,
            maxShots: $this->maxShots,
            beats: true,
        );
    }

    public function usesBeats(): bool
    {
        return $this->beats;
    }

    public function contractVersion(): string
    {
        return $this->beats ? self::BEAT_CONTRACT_VERSION : self::SCENE_CONTRACT_VERSION;
    }

    /**
     * @param  array<string, mixed>  $planningInput
     * @param  list<string>  $coverageIds
     * @param  ?callable(): void  $onAttempt  fired once the request is built, immediately before the client call
     */
    public function plan(
        array $planningInput,
        SceneProfile $profile,
        array $coverageIds,
        ?callable $onAttempt = null,
        ?int $minimumShots = null,
        ?int $maximumShots = null,
    ): ScenePlanResult {
        if (array_key_exists('profile', $planningInput)) {
            throw new ScenePlanException('Planning input may not carry its own profile.');
        }

        $minimumShots ??= $profile->minScenes;
        $maximumShots ??= $this->maxShots;

        if ($minimumShots < 1 || $maximumShots < $minimumShots || $maximumShots > $this->maxShots) {
            throw new ScenePlanException(
                'The requested shot range is outside video.scene_plan.max_shots.'
            );
        }

        $system = $this->system();
        $profilePayload = array_replace($profile->toPlanningPayload(), ['min_scenes' => $minimumShots]);
        $user = $this->user(array_replace($planningInput, ['profile' => $profilePayload]));
        $schema = $this->schema($minimumShots, $maximumShots, $coverageIds);

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

    public function maxShots(): int
    {
        return $this->maxShots;
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
    public const SCENE_CONTRACT_VERSION = 'scene-contract-v5';

    public const BEAT_CONTRACT_VERSION = 'scene-contract-v6';

    /** @var list<string> */
    public const CONTRACT_VERSIONS = [self::SCENE_CONTRACT_VERSION, self::BEAT_CONTRACT_VERSION];

    /**
     * @param  array<string, mixed>  $screenplay
     * @return list<string>
     */
    public static function shownCoverageIds(array $screenplay): array
    {
        $ids = [];

        foreach ((array) ($screenplay['coverage'] ?? []) as $item) {
            if (is_array($item)
                && ($item['mode'] ?? null) === 'shown'
                && is_string($item['coverage_id'] ?? null)) {
                $ids[$item['coverage_id']] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @param  list<string>  $coverageIds
     * @return array<string, mixed>
     */
    public static function sceneItemSchema(array $coverageIds, bool $beats = false): array
    {
        $item = self::baseItemSchema($coverageIds);

        if (! $beats) {
            return $item;
        }

        $moment = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['progress', 'configuration'],
            'properties' => [
                'progress' => ['type' => ['string', 'null'], 'maxLength' => ScreenplayValidator::PROGRESS_LIMITS[1]],
                'configuration' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['part', 'state'],
                        'properties' => [
                            'part' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 80],
                            'state' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 120],
                        ],
                    ],
                ],
            ],
        ];

        $item['required'] = [...$item['required'], 'beat_ids', 'keyframe_state', 'end_state'];
        $item['properties']['beat_ids'] = [
            'type' => 'array',
            'minItems' => 1,
            'items' => ['type' => 'string', 'pattern' => '^b[0-9]{1,2}$'],
        ];
        $item['properties']['keyframe_state'] = $moment;
        $item['properties']['end_state'] = $moment;

        return $item;
    }

    /**
     * @param  list<string>  $coverageIds
     * @return array<string, mixed>
     */
    private static function baseItemSchema(array $coverageIds): array
    {
        $coverageItem = $coverageIds === []
            ? ['type' => 'string', 'pattern' => '^cov_[a-z0-9_]{3,40}$']
            : ['type' => 'string', 'enum' => array_values($coverageIds)];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'scene_code', 'screenplay_scene_code', 'shot_index',
                'location_id', 'character_ids',
                'title', 'purpose', 'coverage_ids',
                'basis', 'state_before', 'scene_state', 'transition_mode',
                'continuity_group', 'source_scene_code', 'camera_change_reason',
                'camera_mode', 'delta', 'video',
            ],
            'properties' => [
                'scene_code' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{2,59}$'],
                'screenplay_scene_code' => ['type' => 'string', 'pattern' => '^sc_[a-z0-9_]{1,56}$'],
                'shot_index' => [
                    'type' => 'integer',
                    'minimum' => (int) config('video.scene_plan.min_shots_per_scene', 1),
                    'maximum' => (int) config('video.scene_plan.max_shots_per_scene', 10),
                ],
                'location_id' => ['type' => 'string', 'pattern' => '^lo_[a-z0-9_]{1,56}$'],
                'character_ids' => [
                    'type' => 'array',
                    'maxItems' => 12,
                    'items' => ['type' => 'string', 'pattern' => '^ch_[a-z0-9_]{1,56}$'],
                ],
                'title' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 120],
                'purpose' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 500],
                'coverage_ids' => [
                    'type' => 'array',
                    'maxItems' => count($coverageIds),
                    'items' => $coverageItem,
                ],
                'basis' => [
                    'type' => 'string',
                    'enum' => ['source_supported'],
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

    /**
     * @param  list<string>  $coverageIds
     * @return array<string, mixed>
     */
    private function schema(int $minScenes, int $maxScenes, array $coverageIds): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['scenes'],
            'properties' => [
                'scenes' => [
                    'type' => 'array',
                    'minItems' => $minScenes,
                    'maxItems' => $maxScenes,
                    'items' => self::sceneItemSchema($coverageIds, $this->beats),
                ],
            ],
        ];
    }
}
