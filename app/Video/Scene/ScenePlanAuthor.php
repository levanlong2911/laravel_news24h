<?php

declare(strict_types=1);

namespace App\Video\Scene;

use App\Video\Prompt\TextCompletionClient;
use App\Video\Screenplay\ScreenplayValidator;
use JsonException;

final class ScenePlanAuthor
{
    private const SPLIT_MARKER = 'PLANNING INPUT:';

    public const SCENE_CONTRACT_VERSION = 'scene-contract-v5';

    public const BEAT_CONTRACT_VERSION = 'scene-contract-v6';

    public const STORYBOARD_CONTRACT_VERSION = 'storyboard-contract-v1';

    /** @var list<string> */
    public const CONTRACT_VERSIONS = [
        self::SCENE_CONTRACT_VERSION, self::BEAT_CONTRACT_VERSION, self::STORYBOARD_CONTRACT_VERSION,
    ];

    /** @var list<string> */
    public const SUBJECT_SIDES = ['port', 'starboard', 'front', 'rear', 'top'];

    /** @var list<string> */
    public const CAMERA_FIELDS = ['position', 'elevation', 'framing', 'subject_side'];

    public const SAME_CAMERA = 'same_camera';

    public const NEW_CAMERA = 'new_camera';

    /** @var list<string> */
    public const BEAT_PARTS = ['whole', 'opening', 'middle', 'closing'];

    /** @var list<string> */
    public const REFERENCE_KINDS = ['design', 'continuity', 'identity', 'environment'];

    public const SHOT_SHAPE = 'beat-coverage-1';

    public const TEXT_LIMIT = 1000;

    public const CAMERA_TEXT_LIMIT = 300;

    public function __construct(
        private readonly TextCompletionClient $client,
        private readonly string $promptPath,
        private readonly string $promptVersion,
        private readonly string $model,
        private readonly int $maxTokens,
    ) {}

    public function contractVersion(): string
    {
        return self::STORYBOARD_CONTRACT_VERSION;
    }

    /**
     * @param  array<string, mixed>  $packet
     * @param  ?callable(): void  $onAttempt  fired once the request is built, immediately before the client call
     */
    public function plan(array $packet, ?callable $onAttempt = null): ScenePlanResult
    {
        if ($this->maxTokens < 1) {
            throw new ScenePlanException('video.scene_plan.max_tokens must be >= 1.');
        }

        $system = $this->system();
        $user = $this->user($packet);
        $schema = $this->schema();

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
                'Storyboard was cut off at the token limit; raise video.scene_plan.max_tokens.',
                $response->text,
                $usage,
            );
        }

        try {
            $decoded = json_decode($response->text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ScenePlanException(
                'Storyboard response was not valid JSON: '.$e->getMessage(),
                $response->text,
                $usage,
            );
        }

        if (! is_array($decoded)
            || ! is_array($decoded['scenes'] ?? null)
            || ! array_is_list($decoded['scenes'])) {
            throw new ScenePlanException('Storyboard response carried no scenes list.', $response->text, $usage);
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

    public function skillHash(): string
    {
        return hash('sha256', $this->skill());
    }

    public function schemaHash(): string
    {
        return hash('sha256', (string) json_encode($this->schema(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

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

    private function skill(): string
    {
        if (! is_file($this->promptPath)) {
            throw new ScenePlanException('Storyboard skill not found: '.$this->promptPath);
        }

        return (string) file_get_contents($this->promptPath);
    }

    private function system(): string
    {
        $skill = $this->skill();
        $seen = substr_count($skill, self::SPLIT_MARKER);

        if ($seen !== 1) {
            throw new ScenePlanException(
                'Storyboard skill must carry "'.self::SPLIT_MARKER.'" exactly once, found '.$seen.'.'
            );
        }

        return trim(substr($skill, 0, (int) strpos($skill, self::SPLIT_MARKER)));
    }

    /** @param array<string, mixed> $packet */
    private function user(array $packet): string
    {
        return self::SPLIT_MARKER."\n\n".json_encode(
            $packet,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        $text = ['type' => 'string', 'minLength' => 1, 'maxLength' => self::TEXT_LIMIT];
        $cameraText = ['type' => 'string', 'minLength' => 3, 'maxLength' => self::CAMERA_TEXT_LIMIT];

        $camera = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => self::CAMERA_FIELDS,
            'properties' => [
                'position' => $cameraText,
                'elevation' => $cameraText,
                'framing' => $cameraText,
                'subject_side' => ['type' => ['string', 'null'], 'enum' => [...self::SUBJECT_SIDES, null]],
            ],
        ];

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

        $objectId = ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{1,40}$'];
        $stateText = ['type' => 'string', 'minLength' => 2, 'maxLength' => 300];

        $shot = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'beat_coverage', 'visual_purpose', 'camera_relation', 'camera', 'keyframe', 'action',
                'duration_ms', 'first_frame_object_ids', 'last_frame_object_ids', 'state_changes', 'subject_end_state',
                'reference_requirements',
            ],
            'properties' => [
                'beat_coverage' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['beat_id', 'part'],
                        'properties' => [
                            'beat_id' => ['type' => 'string', 'pattern' => '^b[0-9]{1,2}$'],
                            'part' => ['type' => 'string', 'enum' => self::BEAT_PARTS],
                        ],
                    ],
                ],
                'visual_purpose' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 300],
                'camera_relation' => ['type' => 'string', 'enum' => [self::NEW_CAMERA, self::SAME_CAMERA]],
                'camera' => ['anyOf' => [$camera, ['type' => 'null']]],
                'keyframe' => $text,
                'action' => $text,
                'duration_ms' => ['type' => 'integer'],
                'first_frame_object_ids' => ['type' => 'array', 'items' => $objectId],
                'last_frame_object_ids' => ['type' => 'array', 'items' => $objectId],
                'state_changes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['object_id', 'state'],
                        'properties' => ['object_id' => $objectId, 'state' => $stateText],
                    ],
                ],
                'subject_end_state' => ['anyOf' => [$moment, ['type' => 'null']]],
                'reference_requirements' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['kind', 'purpose'],
                        'properties' => [
                            'kind' => ['type' => 'string', 'enum' => self::REFERENCE_KINDS],
                            'purpose' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 200],
                        ],
                    ],
                ],
            ],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['scenes'],
            'properties' => [
                'scenes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['scene_id', 'tracked_objects', 'shots'],
                        'properties' => [
                            'scene_id' => ['type' => 'string', 'pattern' => '^sc_[a-z0-9_]{1,56}$'],
                            'tracked_objects' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['object_id', 'name', 'start_state'],
                                    'properties' => [
                                        'object_id' => $objectId,
                                        'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 80],
                                        'start_state' => ['anyOf' => [$stateText, ['type' => 'null']]],
                                    ],
                                ],
                            ],
                            'shots' => ['type' => 'array', 'minItems' => 1, 'items' => $shot],
                        ],
                    ],
                ],
            ],
        ];
    }
}
