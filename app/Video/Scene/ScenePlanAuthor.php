<?php

declare(strict_types=1);

namespace App\Video\Scene;

use App\Services\PromptLibrary\ResolvedPrompt;
use App\Video\Prompt\TextCompletionClient;
use App\Video\Screenplay\SceneBeats;
use App\Video\Screenplay\ScreenplayValidator;
use JsonException;

final class ScenePlanAuthor
{
    private const SPLIT_MARKER = 'PLANNING INPUT:';

    public const SCENE_CONTRACT_VERSION = 'scene-contract-v5';

    public const BEAT_CONTRACT_VERSION = 'scene-contract-v6';

    public const STORYBOARD_CONTRACT_VERSION = 'storyboard-contract-v4';

    public const CUT_STORYBOARD_CONTRACT_VERSION = 'storyboard-contract-v3';

    public const PREVIOUS_STORYBOARD_CONTRACT_VERSION = 'storyboard-contract-v2';

    public const LEGACY_STORYBOARD_CONTRACT_VERSION = 'storyboard-contract-v1';

    /** @var list<string> */
    public const STORYBOARD_CONTRACT_VERSIONS = [
        self::STORYBOARD_CONTRACT_VERSION, self::CUT_STORYBOARD_CONTRACT_VERSION,
        self::PREVIOUS_STORYBOARD_CONTRACT_VERSION, self::LEGACY_STORYBOARD_CONTRACT_VERSION,
    ];

    /** @var list<string> the contracts whose shots carry cuts, structured references and a timeline */
    public const SHOT_CONTRACT_VERSIONS = [self::STORYBOARD_CONTRACT_VERSION, self::CUT_STORYBOARD_CONTRACT_VERSION];

    /** @var list<string> */
    public const AFTER_JUMP_FIELDS = ['beat_id', 'evidence', 'subject_start_state', 'room_state_start', 'object_states'];

    public const PACKET_VERSION = 'storyboard-packet-v3';

    public const DESIGN_FIRST_SOURCE = 'design_first';

    public const LEGACY_SOURCE = 'legacy';

    /** @var list<string> */
    public const SHOT_SIZES = ['extreme_wide', 'wide', 'medium_wide', 'medium', 'medium_close_up', 'close_up', 'extreme_close_up'];

    /** @var list<string> */
    public const CAMERA_ANGLES = ['eye_level', 'high', 'low', 'overhead', 'aerial'];

    /** @var list<string> */
    public const CAMERA_LENSES = ['wide', 'normal', 'long'];

    public const STATIC_MOVEMENT = 'static';

    /** @var list<string> */
    public const CAMERA_MOVEMENTS = [self::STATIC_MOVEMENT, 'pan', 'tilt', 'push_in', 'pull_out', 'track', 'crane', 'orbit'];

    /** @var list<string> */
    public const COMPOSITION_FIELDS = ['must_show', 'foreground', 'background', 'motion_room'];

    /** @var list<string> */
    public const MOVEMENT_FIELDS = ['type', 'start', 'end'];

    /** @var list<string> */
    public const REFERENCE_FIELDS = ['kind', 'purpose', 'view', 'state', 'must_show', 'design_refs', 'source_shot'];

    /** @var list<string> the kinds whose image shows the subject from a named view */
    public const VIEWED_REFERENCE_KINDS = ['identity', 'design'];

    /** @return list<string> */
    public static function referenceViews(): array
    {
        return [\App\Video\Reference\ReferenceView::DESIGN_ANCHOR_VIEW, ...\App\Video\Reference\ReferenceView::values()];
    }

    /** @var list<string> */
    public const VALIDATED_CONTRACT_VERSIONS = [...self::STORYBOARD_CONTRACT_VERSIONS, FilmCutAuthor::CONTRACT_VERSION, FilmCutAuthor::LEGACY_CONTRACT_VERSION];

    /** @var list<string> */
    public const CONTRACT_VERSIONS = [
        self::SCENE_CONTRACT_VERSION, self::BEAT_CONTRACT_VERSION, ...self::VALIDATED_CONTRACT_VERSIONS,
    ];

    /** @var list<string> */
    public const SUBJECT_SIDES = ['port', 'starboard', 'front', 'rear', 'top'];

    /** @var list<string> */
    public const PREVIOUS_CAMERA_FIELDS = ['position', 'elevation', 'framing', 'subject_side'];

    /** @var list<string> */
    public const CAMERA_FIELDS = [
        ...self::PREVIOUS_CAMERA_FIELDS, 'shot_size', 'facing', 'angle', 'lens', 'composition', 'movement',
    ];

    public const SAME_CAMERA = 'same_camera';

    public const NEW_CAMERA = 'new_camera';

    /** @var list<string> */
    public const BEAT_PARTS = ['whole', 'opening', 'middle', 'closing'];

    /** @var list<string> */
    public const REFERENCE_KINDS = ['design', 'continuity', 'identity', 'environment'];

    public const SHOT_SHAPE = 'beat-coverage-1';

    public const TEXT_LIMIT = 1000;

    public const CAMERA_TEXT_LIMIT = 300;

    /**
     * Prompt da ghep tu thu vien prompt cho DUNG mot lan lap storyboard. Null thi
     * doc promptPath nhu truoc. Author la singleton nen khong bao gio gan vao
     * chinh no: withPrompt() tra ban sao, de worker chay lau khong giu revision cu.
     */
    private ?ResolvedPrompt $prompt = null;

    public function __construct(
        private readonly TextCompletionClient $client,
        private readonly string $promptPath,
        private readonly string $promptVersion,
        private readonly string $model,
        private readonly int $maxTokens,
    ) {}

    public function withPrompt(ResolvedPrompt $prompt): self
    {
        $author = clone $this;
        $author->prompt = $prompt;

        return $author;
    }

    public function resolvedPrompt(): ?ResolvedPrompt
    {
        return $this->prompt;
    }

    /**
     * Dung y nhu plan() se gui di, khong goi model: de so file voi DB tung byte.
     *
     * @param  array<string, mixed>  $packet
     * @return array{system: string, user: string}
     */
    public function preview(array $packet): array
    {
        return ['system' => $this->system(), 'user' => $this->user($packet)];
    }

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

        return new ScenePlanResult(
            scenes: $this->scenesOf($response->text, $usage),
            raw: $response->text,
            authorModel: $response->model,
            inputTokens: $response->inputTokens,
            outputTokens: $response->outputTokens,
            reasoningTokens: $response->reasoningTokens,
        );
    }

    /**
     * @param  string  $raw  an answer the model already returned and the ledger kept
     */
    public function fromStored(string $raw, ?string $authorModel): ScenePlanResult
    {
        return new ScenePlanResult(
            scenes: $this->scenesOf($raw, []),
            raw: $raw,
            authorModel: $authorModel,
            inputTokens: 0,
            outputTokens: 0,
            reasoningTokens: 0,
        );
    }

    /**
     * @param  array<string, mixed>  $usage
     * @return list<mixed>
     */
    private function scenesOf(string $raw, array $usage): array
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ScenePlanException('Storyboard response was not valid JSON: '.$e->getMessage(), $raw, $usage);
        }

        if (! is_array($decoded)
            || ! is_array($decoded['scenes'] ?? null)
            || ! array_is_list($decoded['scenes'])) {
            throw new ScenePlanException('Storyboard response carried no scenes list.', $raw, $usage);
        }

        return array_values($decoded['scenes']);
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

    private function skill(): string
    {
        if ($this->prompt !== null) {
            return $this->prompt->content;
        }

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
        $optionalCameraText = ['anyOf' => [$cameraText, ['type' => 'null']]];

        $camera = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => self::CAMERA_FIELDS,
            'properties' => [
                'position' => $cameraText,
                'elevation' => $cameraText,
                'framing' => $cameraText,
                'subject_side' => ['anyOf' => [['type' => 'string', 'enum' => self::SUBJECT_SIDES], ['type' => 'null']]],
                'shot_size' => ['type' => 'string', 'enum' => self::SHOT_SIZES],
                'facing' => $cameraText,
                'angle' => ['type' => 'string', 'enum' => self::CAMERA_ANGLES],
                'lens' => ['type' => 'string', 'enum' => self::CAMERA_LENSES],
                'composition' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => self::COMPOSITION_FIELDS,
                    'properties' => [
                        'must_show' => $cameraText,
                        'foreground' => $optionalCameraText,
                        'background' => $optionalCameraText,
                        'motion_room' => $optionalCameraText,
                    ],
                ],
                'movement' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => self::MOVEMENT_FIELDS,
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => self::CAMERA_MOVEMENTS],
                        'start' => $optionalCameraText,
                        'end' => $optionalCameraText,
                    ],
                ],
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
                'beat_coverage', 'after_jump', 'visual_purpose', 'camera_relation', 'camera', 'camera_change_reason', 'keyframe', 'action',
                'duration_ms', 'used_start_ms', 'used_ms', 'result_at_ms', 'first_frame_object_ids', 'last_frame_object_ids', 'state_changes', 'subject_end_state',
                'room_state_end', 'reference_requirements',
            ],
            'properties' => [
                'beat_coverage' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['beat_id', 'part', 'shown'],
                        'properties' => [
                            'beat_id' => ['type' => 'string', 'pattern' => '^b[0-9]{1,2}$'],
                            'part' => ['type' => 'string', 'enum' => self::BEAT_PARTS],
                            'shown' => $cameraText,
                        ],
                    ],
                ],
                'after_jump' => ['anyOf' => [[
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => self::AFTER_JUMP_FIELDS,
                    'properties' => [
                        'beat_id' => ['type' => 'string', 'pattern' => '^b[0-9]{1,2}$'],
                        'evidence' => $cameraText,
                        'subject_start_state' => ['anyOf' => [$moment, ['type' => 'null']]],
                        'room_state_start' => ['anyOf' => [['type' => 'string', 'enum' => SceneBeats::ROOM_STATES], ['type' => 'null']]],
                        'object_states' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['object_id', 'state'],
                                'properties' => ['object_id' => $objectId, 'state' => $stateText],
                            ],
                        ],
                    ],
                ], ['type' => 'null']]],
                'visual_purpose' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 300],
                'camera_relation' => ['type' => 'string', 'enum' => [self::NEW_CAMERA, self::SAME_CAMERA]],
                'camera' => ['anyOf' => [$camera, ['type' => 'null']]],
                'camera_change_reason' => $optionalCameraText,
                'keyframe' => $text,
                'action' => $text,
                'duration_ms' => ['type' => 'integer'],
                'used_start_ms' => ['type' => 'integer'],
                'used_ms' => ['type' => 'integer'],
                'result_at_ms' => ['type' => 'integer'],
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
                'room_state_end' => ['anyOf' => [['type' => 'string', 'enum' => SceneBeats::ROOM_STATES], ['type' => 'null']]],
                'reference_requirements' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => self::REFERENCE_FIELDS,
                        'properties' => [
                            'kind' => ['type' => 'string', 'enum' => self::REFERENCE_KINDS],
                            'purpose' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 200],
                            'view' => ['anyOf' => [['type' => 'string', 'enum' => self::referenceViews()], ['type' => 'null']]],
                            'state' => $optionalCameraText,
                            'must_show' => $cameraText,
                            'design_refs' => ['type' => 'array', 'items' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,80}$']],
                            'source_shot' => ['anyOf' => [['type' => 'string', 'pattern' => '^sc_[a-z0-9_]{1,56}_s[0-9]{2}$'], ['type' => 'null']]],
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
                        'required' => ['scene_id', 'duration_conflict', 'tracked_objects', 'shots'],
                        'properties' => [
                            'scene_id' => ['type' => 'string', 'pattern' => '^sc_[a-z0-9_]{1,56}$'],
                            'duration_conflict' => ['anyOf' => [['type' => 'string', 'minLength' => 3, 'maxLength' => 500], ['type' => 'null']]],
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
