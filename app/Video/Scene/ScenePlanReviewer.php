<?php

declare(strict_types=1);

namespace App\Video\Scene;

use App\Video\Prompt\TextCompletionClient;
use JsonException;

final class ScenePlanReviewer
{
    private const SPLIT_MARKER = 'REVIEW INPUT:';

    /** @var list<string> */
    public const RULES = [
        'progress', 'source_image', 'end_state', 'preserve',
        'camera', 'milestone_basis', 'content', 'duration', 'continuity',
    ];

    /** @var list<string> */
    public const VERDICTS = ['pass', 'revise', 'requires_replan'];

    /** @var list<string> */
    public const SEVERITIES = ['blocking', 'advisory'];

    public const MAX_FINDINGS = 40;

    /** @var list<string> */
    public const EVIDENCE_SOURCES = ['scene', 'article'];

    /** @var list<string> */
    public const SCENE_FIELDS = [
        'delta', 'title', 'purpose', 'basis', 'state_before', 'scene_state',
        'transition_mode', 'continuity_group', 'source_scene_code', 'camera_change_reason',
        'video.action', 'video.preserve', 'video.end_state',
    ];

    /** @var list<string> */
    public const ARTICLE_FIELDS = ['title', 'summary'];

    public const MAX_EVIDENCE = 3;

    public const MIN_QUOTE = 3;

    public const MAX_QUOTE = 300;

    public function __construct(
        private readonly TextCompletionClient $client,
        private readonly string $promptPath,
        private readonly string $promptVersion,
        private readonly string $model,
        private readonly int $maxTokens,
    ) {}

    /**
     * @param  array<string, mixed>  $reviewInput
     * @param  ?callable(): void  $onAttempt  fired once the request is built, immediately before the client call
     */
    public function review(
        array $reviewInput,
        SceneProfile $profile,
        int $maxScenes,
        string $skill,
        ?callable $onAttempt = null,
    ): ScenePlanReviewResult {
        $system = $this->systemOf($skill);
        $user = $this->user($reviewInput);
        $schema = $this->schema($profile, $maxScenes);

        if ($this->maxTokens < 1) {
            throw new ScenePlanException('video.scene_plan.review.max_tokens must be >= 1.');
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
                'Scene review was cut off at the token limit; raise video.scene_plan.review.max_tokens.',
                $response->text,
                $usage,
            );
        }

        try {
            $decoded = json_decode($response->text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ScenePlanException(
                'Scene review response was not valid JSON: '.$e->getMessage(),
                $response->text,
                $usage,
            );
        }

        if (! is_array($decoded)
            || ! is_string($decoded['verdict'] ?? null)
            || ! is_array($decoded['findings'] ?? null)
            || ! array_is_list($decoded['findings'])
            || ! is_array($decoded['patch'] ?? null)
            || ! array_is_list($decoded['patch'])) {
            throw new ScenePlanException(
                'Scene review response was not shaped as a review.',
                $response->text,
                $usage,
            );
        }

        return new ScenePlanReviewResult(
            verdict: $decoded['verdict'],
            findings: array_values($decoded['findings']),
            patch: array_values($decoded['patch']),
            raw: $response->text,
            reviewerModel: $response->model,
            inputTokens: $response->inputTokens,
            outputTokens: $response->outputTokens,
            reasoningTokens: $response->reasoningTokens,
        );
    }

    /**
     * Doc file MOT LAN, kiem marker va kiem phan system khong rong — TRUOC luot
     * author. Author hong thi chua ton dong nao; reviewer hong thi luot author
     * DA tra tien.
     *
     * Tra ve chinh noi dung da doc: hash va moi vong ra deu dung ban NAY, nen
     * file bi sua giua chung khong lam prompt lech khoi hash da luu. Ban chup
     * song dung bang mot luot planning, khong phai cache static.
     */
    public function preflight(): string
    {
        $skill = $this->skill();

        if (trim($this->systemOf($skill)) === '') {
            throw new ScenePlanException(
                'Scene review skill has no instructions before "'.self::SPLIT_MARKER.'".'
            );
        }

        return $skill;
    }

    public function promptVersion(): string
    {
        return $this->promptVersion;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function maxTokens(): int
    {
        return $this->maxTokens;
    }

    public function skillHash(string $skill): string
    {
        return hash('sha256', $skill);
    }

    private function skill(): string
    {
        if (! is_file($this->promptPath)) {
            throw new ScenePlanException('Scene review skill not found: '.$this->promptPath);
        }

        return (string) file_get_contents($this->promptPath);
    }

    private function systemOf(string $skill): string
    {
        $seen = substr_count($skill, self::SPLIT_MARKER);

        if ($seen !== 1) {
            throw new ScenePlanException(
                'Scene review skill must carry "'.self::SPLIT_MARKER.'" exactly once, found '.$seen.'.'
            );
        }

        return trim(substr($skill, 0, (int) strpos($skill, self::SPLIT_MARKER)));
    }

    /** @param array<string, mixed> $reviewInput */
    private function user(array $reviewInput): string
    {
        return self::SPLIT_MARKER."\n\n".json_encode(
            $reviewInput,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string, mixed> */
    private function schema(SceneProfile $profile, int $maxScenes): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['verdict', 'findings', 'patch'],
            'properties' => [
                'verdict' => ['type' => 'string', 'enum' => self::VERDICTS],
                'findings' => [
                    'type' => 'array',
                    'maxItems' => self::MAX_FINDINGS,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['scene_code', 'rule', 'severity', 'problem', 'fix', 'evidence'],
                        'properties' => [
                            'scene_code' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{2,59}$'],
                            'rule' => ['type' => 'string', 'enum' => self::RULES],
                            'severity' => ['type' => 'string', 'enum' => self::SEVERITIES],
                            'problem' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 500],
                            'fix' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 500],
                            'evidence' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'maxItems' => self::MAX_EVIDENCE,
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['source', 'scene_code', 'field', 'quote'],
                                    'properties' => [
                                        'source' => ['type' => 'string', 'enum' => self::EVIDENCE_SOURCES],
                                        'scene_code' => ['type' => 'string', 'maxLength' => 60],
                                        'field' => [
                                            'type' => 'string',
                                            'enum' => array_values(array_unique(
                                                array_merge(self::SCENE_FIELDS, self::ARTICLE_FIELDS),
                                            )),
                                        ],
                                        'quote' => [
                                            'type' => 'string',
                                            'minLength' => self::MIN_QUOTE,
                                            'maxLength' => self::MAX_QUOTE,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'patch' => [
                    'type' => 'array',
                    'maxItems' => $maxScenes,
                    'items' => ScenePlanAuthor::sceneItemSchema($profile),
                ],
            ],
        ];
    }
}
