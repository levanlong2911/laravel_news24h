<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoDesignImage;
use App\Models\VideoPlanningStage;
use App\Video\Prompt\Exceptions\TextCompletionException;
use App\Video\Prompt\Exceptions\TextCompletionRefusalException;
use App\Video\Prompt\OpenAiTextClient;
use App\Video\Reference\IdentityPreservationPrompt;
use App\Video\Reference\ReferenceEnvironment;
use App\Video\Reference\ReferenceView;
use App\Video\Screenplay\LocationProfile;
use App\Video\Screenplay\ProtagonistProfile;
use App\Video\Screenplay\VesselDesign;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ReferencePromptWriter
{
    public const DERIVATION_VERSION = 'reference-view-v1';

    private const SPLIT_MARKER = 'SOURCE MATERIAL:';

    public const MAX_CLAIMS = 16;

    public const MAX_STATEMENT = 320;

    public const MAX_PATHS = 4;

    public const MAX_PATH = 96;

    public const MAX_DISCREPANCIES = 8;

    public const MAX_OBSERVATION = 300;

    public const MAX_GEOMETRY = 3000;

    /** @var list<string> */
    public const BASES = ['image', 'source', 'image_and_source', 'inference'];

    /** @var list<string> */
    public const UNCITED_BASES = ['image', 'inference'];

    /** @var list<string> */
    public const CITED_BASES = ['source', 'image_and_source'];

    /** @var list<string> */
    public const MAJOR_TOPICS = ['mass_count', 'connection', 'proportion', 'primary_opening'];

    /** @var list<string> */
    public const SEVERITIES = ['unclear', 'minor', 'major'];

    /** @var list<string> */
    public const TOPICS = ['mass_count', 'connection', 'proportion', 'primary_opening', 'other'];

    private const PATH_PATTERN = '^(design|subject|locations|scenes)\.[A-Za-z0-9_.]+$';

    private const ID_PATTERN = '/^[A-Za-z0-9_]+$/';

    /** @var list<string> */
    private const FINGERPRINT_KEYS = [
        'anchor_artifact_id', 'anchor_sha256', 'screenplay_stage_id', 'packet_hash', 'view',
        'model', 'reasoning_effort', 'skill_hash', 'prompt_version', 'preservation_version', 'camera_version',
    ];

    public function __construct(
        private readonly PlanningStageStore $stages,
        private ?OpenAiTextClient $client = null,
    ) {}

    /**
     * @return array{0: ?VideoPlanningStage, 1: string, 2: list<string>}
     */
    public function write(string $projectId, ?VideoDesignImage $anchor, ReferenceView $view, bool $force = false): array
    {
        [$base, $why] = $this->base($projectId, $anchor);

        if ($base === null) {
            return [null, $why, []];
        }

        $packet = $this->packet($base, $view);
        $input = $this->fingerprint($base, $packet, $view);

        [$claimed, $token, $reason] = $this->stages->claimProjectStage(
            $projectId, PlanningStageName::REFERENCE_PROMPT, $input, $force, ['pricing' => 'unpriced'],
        );

        if ($claimed === null) {
            return [null, $reason, []];
        }

        if ($reason === 'already_succeeded') {
            return [$claimed, 'reference_prompt_cached', []];
        }

        if ($token === null) {
            return [null, 'reference_prompt_running', []];
        }

        try {
            $response = $this->client()->completeWithImage(
                model: (string) config('image_prompt.reference.model'),
                system: $this->system(),
                user: self::SPLIT_MARKER."\n\n".json_encode(
                    $packet,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
                ),
                imageDataUri: 'data:'.$base['mime'].';base64,'.base64_encode($base['bytes']),
                maxTokens: (int) config('image_prompt.reference.max_tokens'),
                outputSchema: self::schema(),
            );
        } catch (Throwable $e) {
            $answered = $e instanceof TextCompletionException || $e instanceof TextCompletionRefusalException;

            if ($answered && $e->usage !== []) {
                [$usage, $pricing, $priced] = $this->accounting(
                    $e->usage,
                    (string) ($e->model ?? config('image_prompt.reference.model')),
                    (int) ($e->usage['prompt_tokens'] ?? 0),
                    (int) ($e->usage['completion_tokens'] ?? 0),
                    (int) ($e->usage['completion_tokens_details']['reasoning_tokens'] ?? 0),
                );

                $this->stages->finishFailed(
                    (string) $claimed->id,
                    $token,
                    $e->getMessage(),
                    $usage,
                    $e->raw,
                    ['usage' => $e->usage, 'author_model' => $e->model, 'pricing' => $priced],
                    $pricing,
                );
            } else {
                $this->stages->finishFailed((string) $claimed->id, $token, $e->getMessage());
            }

            Log::error('reference-prompt: author call failed', [
                'project_id' => $projectId,
                'stage_id' => $claimed->id,
                'exception' => $e,
            ]);

            return [null, 'reference_prompt_call_failed', [$e->getMessage()]];
        }

        $decoded = json_decode($response->text, true);
        $violations = match (true) {
            $response->wasTruncated() => ['the answer was cut off at the token limit'],
            ! is_array($decoded) => ['the answer is not a JSON object'],
            default => self::violations($decoded, $packet),
        };

        [$usage, $pricing, $priced] = $this->accounting($response->usage, $response->model, $response->inputTokens, $response->outputTokens, $response->reasoningTokens);

        $output = [
            'claims' => is_array($decoded['claims'] ?? null) ? array_values($decoded['claims']) : [],
            'discrepancies' => is_array($decoded['discrepancies'] ?? null) ? array_values($decoded['discrepancies']) : [],
            'review_incomplete' => ($decoded['review_incomplete'] ?? null) === true,
            'author_model' => $response->model,
            'usage' => $response->usage,
            'pricing' => $priced,
        ] + ($violations === [] ? [] : ['violations' => $violations]);

        $recorded = $violations === []
            ? $this->stages->finishSucceeded((string) $claimed->id, $token, $response->text, $output, $usage, $pricing)
            : $this->stages->finishFailed(
                (string) $claimed->id,
                $token,
                'Reference prompt failed validation: '.implode('; ', $violations),
                $usage,
                $response->text,
                $output,
                $pricing,
            );

        if (! $recorded) {
            $this->stages->recordOrphanAttempt(
                $projectId,
                PlanningStageName::REFERENCE_PROMPT,
                $input,
                $pricing,
                (string) $claimed->id,
                $token,
                'Claim lost before the reference prompt result was recorded.',
                $usage,
                $response->text,
                $output,
            );

            return [null, 'reference_prompt_claim_lost', $violations];
        }

        return $violations === []
            ? [$claimed->fresh(), 'written', []]
            : [null, 'reference_prompt_invalid', $violations];
    }

    /**
     * @return array<string, array<string, mixed>> keyed by view value
     */
    public function previews(string $projectId, ?VideoDesignImage $anchor): array
    {
        [$base] = $this->base($projectId, $anchor, false);

        if ($base === null) {
            return [];
        }

        $stages = VideoPlanningStage::query()
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::REFERENCE_PROMPT->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->orderByDesc('planning_revision')
            ->get()
            ->groupBy(static fn (VideoPlanningStage $stage): string => (string) ($stage->input_json['view'] ?? ''));

        $previews = [];

        foreach (ReferenceView::menu() as $view) {
            $packet = $this->packet($base, $view);
            $stage = ($stages[$view->value] ?? collect())->first(fn (VideoPlanningStage $row): bool => $this->usable(
                is_array($row->input_json) ? $row->input_json : [],
                is_array($row->output_json) ? $row->output_json : [],
                $base,
                $packet,
                $view,
            ));

            if ($stage !== null) {
                $previews[$view->value] = $this->previewOf($stage, $view, $packet);
            }
        }

        return $previews;
    }

    /**
     * @param  array<string, mixed>  $packet
     * @return array<string, mixed>
     */
    private function previewOf(VideoPlanningStage $stage, ReferenceView $view, array $packet): array
    {
        $output = (array) $stage->output_json;
        $prompts = [];

        foreach (ReferenceEnvironment::cases() as $environment) {
            $prompt = self::assemble($output['claims'], $view, $environment);
            $prompts[$environment->value] = ['prompt' => $prompt, 'prompt_sha256' => hash('sha256', $prompt)];
        }

        return [
            'stage_id' => (string) $stage->id,
            'claims' => array_map(static fn (array $claim): array => [
                'statement' => (string) ($claim['statement'] ?? ''),
                'basis' => (string) ($claim['basis'] ?? ''),
                'sources' => array_map(static fn (string $path): array => [
                    'path' => $path,
                    'text' => (string) self::textAt($packet, $path),
                ], (array) ($claim['source_paths'] ?? [])),
            ], $output['claims']),
            'discrepancies' => $output['discrepancies'],
            'review_incomplete' => $output['review_incomplete'],
            'has_major' => self::hasMajor($output['discrepancies']),
            'prompts' => $prompts,
        ];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    public function retryable(VideoDesignImage $cell): array
    {
        $spec = is_array($cell->prompt_spec_json) ? $cell->prompt_spec_json : [];

        if (($spec['derivation_version'] ?? null) !== self::DERIVATION_VERSION) {
            return [false, 'reference_needs_ai_prompt'];
        }

        $stage = VideoPlanningStage::query()
            ->whereKey((string) ($spec['reference_prompt_stage_id'] ?? ''))
            ->where('project_id', (string) $cell->project_id)
            ->where('stage', PlanningStageName::REFERENCE_PROMPT->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->first();

        $input = is_array($stage?->input_json) ? $stage->input_json : [];
        $output = is_array($stage?->output_json) ? $stage->output_json : [];

        if ($stage === null
            || ($input['view'] ?? null) !== ($spec['view_key'] ?? null)
            || ($input['anchor_artifact_id'] ?? null) !== ($spec['source_artifact_id'] ?? null)
            || ($input['anchor_sha256'] ?? null) !== ($spec['source_artifact_sha256'] ?? null)
            || ($input['preservation_version'] ?? null) !== IdentityPreservationPrompt::VERSION
            || ! in_array($input['camera_version'] ?? null, ReferenceView::SUPPORTED_CAMERA_VERSIONS, true)
            || ! is_array($output['claims'] ?? null)) {
            return [false, 'reference_prompt_stale'];
        }

        if (($output['review_incomplete'] ?? true) !== false) {
            return [false, 'reference_review_incomplete'];
        }

        if (self::hasMajor((array) ($output['discrepancies'] ?? []))
            && ($spec['discrepancy_ack']['stage_id'] ?? null) !== (string) $stage->id) {
            return [false, 'reference_discrepancy_unacknowledged'];
        }

        $view = ReferenceView::tryFrom((string) ($spec['view_key'] ?? ''));
        $environment = ReferenceEnvironment::tryFrom((string) ($spec['environment'] ?? ''));

        if ($view === null || $environment === null
            || self::assemble($output['claims'], $view, $environment) !== ($spec['prompt'] ?? null)) {
            return [false, 'reference_prompt_stale'];
        }

        return [true, 'ok'];
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: string}
     */
    public function renderable(
        string $projectId,
        ?VideoDesignImage $anchor,
        string $stageId,
        ReferenceView $view,
        ReferenceEnvironment $environment,
        string $promptSha256,
        bool $acknowledged,
        ?string $actorId,
    ): array {
        [$base, $why] = $this->base($projectId, $anchor);

        if ($base === null) {
            return [null, $why];
        }

        $stage = VideoPlanningStage::query()
            ->whereKey($stageId)
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::REFERENCE_PROMPT->value)
            ->first();

        if ($stage === null || $stage->status !== VideoPlanningStageStatus::SUCCEEDED->value) {
            return [null, 'reference_prompt_not_found'];
        }

        $packet = $this->packet($base, $view);
        $input = is_array($stage->input_json) ? $stage->input_json : [];
        $output = is_array($stage->output_json) ? $stage->output_json : [];

        if (! $this->usable($input, $output, $base, $packet, $view)) {
            return [null, 'reference_prompt_stale'];
        }

        if ($output['review_incomplete']) {
            return [null, 'reference_review_incomplete'];
        }

        $major = self::hasMajor($output['discrepancies']);

        if ($major && ! $acknowledged) {
            return [null, 'reference_discrepancy_unacknowledged'];
        }

        $prompt = self::assemble($output['claims'], $view, $environment);

        if (! hash_equals(hash('sha256', $prompt), $promptSha256)) {
            return [null, 'reference_preview_stale'];
        }

        return [[
            'prompt' => $prompt,
            'stage_id' => (string) $stage->id,
            'packet_hash' => (string) $input['packet_hash'],
            'anchor_image_id' => (string) $base['image_id'],
            'anchor_artifact_id' => (string) $base['artifact_id'],
            'anchor_sha256' => (string) $base['sha256'],
            'discrepancy_ack' => $major ? [
                'stage_id' => (string) $stage->id,
                'report_hash' => hash('sha256', json_encode(
                    ['discrepancies' => $output['discrepancies'], 'review_incomplete' => $output['review_incomplete']],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                )),
                'by' => $actorId,
                'at' => now()->toIso8601String(),
            ] : null,
        ], 'ok'];
    }

    /**
     * @param  list<array<string, mixed>>  $claims
     */
    public static function assemble(array $claims, ReferenceView $view, ReferenceEnvironment $environment): string
    {
        $blocks = [
            IdentityPreservationPrompt::text(),
            "VIEW GEOMETRY:\n".self::viewGeometry($claims),
            $view->cameraBlock(),
        ];

        if ($environment->override() !== '') {
            $blocks[] = $environment->override();
        }

        return implode("\n\n", $blocks);
    }

    /**
     * @param  list<array<string, mixed>>  $claims
     */
    public static function viewGeometry(array $claims): string
    {
        return implode(' ', array_map(
            static fn (array $claim): string => trim((string) ($claim['statement'] ?? '')),
            $claims,
        ));
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $path = ['type' => 'string', 'maxLength' => self::MAX_PATH, 'pattern' => self::PATH_PATTERN];
        $claim = static fn (array $bases, array $paths): array => [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['statement', 'basis', 'source_paths'],
            'properties' => [
                'statement' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_STATEMENT],
                'basis' => ['type' => 'string', 'enum' => $bases],
                'source_paths' => ['type' => 'array', 'items' => $path] + $paths,
            ],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['claims', 'discrepancies', 'review_incomplete'],
            'properties' => [
                'claims' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => self::MAX_CLAIMS,
                    'items' => [
                        'anyOf' => [
                            $claim(self::UNCITED_BASES, ['maxItems' => 0]),
                            $claim(self::CITED_BASES, ['minItems' => 1, 'maxItems' => self::MAX_PATHS]),
                        ],
                    ],
                ],
                'discrepancies' => [
                    'type' => 'array',
                    'maxItems' => self::MAX_DISCREPANCIES,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['severity', 'topic', 'image_observation', 'source_statement', 'source_path'],
                        'properties' => [
                            'severity' => ['type' => 'string', 'enum' => self::SEVERITIES],
                            'topic' => ['type' => 'string', 'enum' => self::TOPICS],
                            'image_observation' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_OBSERVATION],
                            'source_statement' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_OBSERVATION],
                            'source_path' => $path,
                        ],
                    ],
                ],
                'review_incomplete' => ['type' => 'boolean'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  array<string, mixed>  $packet
     * @return list<string>
     */
    public static function violations(array $decoded, array $packet): array
    {
        $violations = [];
        $citable = (array) ($packet['citable_paths'] ?? []);
        $claims = $decoded['claims'] ?? null;
        $discrepancies = $decoded['discrepancies'] ?? null;

        if (! is_bool($decoded['review_incomplete'] ?? null)) {
            $violations[] = 'review_incomplete must be true or false';
        }

        if (! is_array($claims) || ! array_is_list($claims) || $claims === []) {
            $violations[] = 'claims must hold at least one claim';
            $claims = [];
        } elseif (count($claims) > self::MAX_CLAIMS) {
            $violations[] = 'claims hold more than '.self::MAX_CLAIMS.' items';
        }

        foreach ($claims as $index => $claim) {
            foreach (self::claimViolations($claim, $citable) as $violation) {
                $violations[] = "claims[{$index}]: {$violation}";
            }
        }

        if ($claims !== [] && array_filter($claims, static fn (mixed $claim): bool => ! is_array($claim)) === []
            && mb_strlen(self::viewGeometry($claims)) > self::MAX_GEOMETRY) {
            $violations[] = 'the joined claims exceed '.self::MAX_GEOMETRY.' characters';
        }

        if (! is_array($discrepancies) || ! array_is_list($discrepancies)) {
            $violations[] = 'discrepancies must be a list';
            $discrepancies = [];
        } elseif (count($discrepancies) > self::MAX_DISCREPANCIES) {
            $violations[] = 'discrepancies hold more than '.self::MAX_DISCREPANCIES.' items';
        }

        foreach ($discrepancies as $index => $discrepancy) {
            foreach (self::discrepancyViolations($discrepancy, $packet, $citable) as $violation) {
                $violations[] = "discrepancies[{$index}]: {$violation}";
            }
        }

        return $violations;
    }

    public static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @param  array<string, mixed>  $packet
     */
    public static function textAt(array $packet, string $path): ?string
    {
        if (! in_array($path, (array) ($packet['citable_paths'] ?? []), true)) {
            return null;
        }

        $node = $packet;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }

            $node = $node[$segment];
        }

        return match (true) {
            is_string($node) => $node,
            is_int($node), is_float($node) => json_encode($node, JSON_THROW_ON_ERROR),
            default => null,
        };
    }

    public function skillHash(): string
    {
        return hash('sha256', $this->skill());
    }

    /**
     * @param  list<string>  $citable
     * @return list<string>
     */
    private static function claimViolations(mixed $claim, array $citable): array
    {
        if (! is_array($claim)) {
            return ['not an object'];
        }

        $violations = [];
        $statement = $claim['statement'] ?? null;
        $basis = $claim['basis'] ?? null;
        $paths = $claim['source_paths'] ?? null;

        if (! is_string($statement) || trim($statement) === '') {
            $violations[] = 'statement is empty';
        } elseif (mb_strlen($statement) > self::MAX_STATEMENT) {
            $violations[] = 'statement exceeds '.self::MAX_STATEMENT.' characters';
        } elseif (preg_match('/[.!?]["\')]?$/u', trim($statement)) !== 1) {
            $violations[] = 'statement is not a complete sentence (it does not end with . ! or ?)';
        }

        if (! in_array($basis, self::BASES, true)) {
            $violations[] = 'basis is not one of '.implode(', ', self::BASES);
        }

        if (! is_array($paths) || ! array_is_list($paths)) {
            return [...$violations, 'source_paths must be a list'];
        }

        if (count($paths) > self::MAX_PATHS) {
            $violations[] = 'source_paths hold more than '.self::MAX_PATHS.' items';
        }

        if (in_array($basis, self::CITED_BASES, true) && $paths === []) {
            $violations[] = "basis {$basis} needs at least one source path";
        }

        if (in_array($basis, self::UNCITED_BASES, true) && $paths !== []) {
            $violations[] = "basis {$basis} requires source_paths to be empty";
        }

        $scene = false;
        $layout = false;
        $support = false;

        foreach ($paths as $path) {
            if (! is_string($path) || ! in_array($path, $citable, true)) {
                $violations[] = 'source path '.json_encode($path).' is not a citable path';

                continue;
            }

            $scene = $scene || str_starts_with($path, 'scenes.');
            $layout = $layout || (str_starts_with($path, 'locations.') && str_ends_with($path, '.layout'));
            $support = $support || str_starts_with($path, 'design.') || str_starts_with($path, 'subject.');
        }

        if ($scene && ! $support) {
            $violations[] = 'a scene action path needs a design or subject path beside it';
        }

        if ($layout && ! $support) {
            $violations[] = 'a location layout path needs a design or subject path beside it';
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $packet
     * @param  list<string>  $citable
     * @return list<string>
     */
    private static function discrepancyViolations(mixed $discrepancy, array $packet, array $citable): array
    {
        if (! is_array($discrepancy)) {
            return ['not an object'];
        }

        $violations = [];

        if (! in_array($discrepancy['severity'] ?? null, self::SEVERITIES, true)) {
            $violations[] = 'severity is not one of '.implode(', ', self::SEVERITIES);
        }

        if (! in_array($discrepancy['topic'] ?? null, self::TOPICS, true)) {
            $violations[] = 'topic is not one of '.implode(', ', self::TOPICS);
        } elseif (($discrepancy['severity'] ?? null) === 'major'
            && ! in_array($discrepancy['topic'], self::MAJOR_TOPICS, true)) {
            $violations[] = 'a major discrepancy must be one of '.implode(', ', self::MAJOR_TOPICS);
        }

        $observation = $discrepancy['image_observation'] ?? null;

        if (! is_string($observation) || trim($observation) === '') {
            $violations[] = 'image_observation is empty';
        } elseif (mb_strlen($observation) > self::MAX_OBSERVATION) {
            $violations[] = 'image_observation exceeds '.self::MAX_OBSERVATION.' characters';
        }

        $path = $discrepancy['source_path'] ?? null;
        $statement = $discrepancy['source_statement'] ?? null;
        $quote = is_string($statement) ? self::normalize($statement) : '';

        if ($quote === '') {
            $violations[] = 'source_statement is empty';
        } elseif (mb_strlen((string) $statement) > self::MAX_OBSERVATION) {
            $violations[] = 'source_statement exceeds '.self::MAX_OBSERVATION.' characters';
        }

        if (! is_string($path) || ! in_array($path, $citable, true)) {
            return [...$violations, 'source_path '.json_encode($path).' is not a citable path'];
        }

        $text = self::textAt($packet, $path);

        if ($quote !== '' && ($text === null || ! str_contains(self::normalize($text), $quote))) {
            $violations[] = "source_statement is not a word-for-word excerpt of {$path}";
        }

        return $violations;
    }

    /**
     * @param  list<mixed>  $discrepancies
     */
    private static function hasMajor(array $discrepancies): bool
    {
        foreach ($discrepancies as $row) {
            if (is_array($row) && ($row['severity'] ?? null) === 'major') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: string}
     */
    private function base(string $projectId, ?VideoDesignImage $anchor, bool $readBytes = true): array
    {
        $artifact = $anchor?->artifact;

        if ($anchor === null || $artifact === null) {
            return [null, 'no_approved_anchor'];
        }

        $spec = is_array($anchor->prompt_spec_json) ? $anchor->prompt_spec_json : [];
        $designId = $spec['design_stage_id'] ?? null;
        $fromDesign = is_string($designId) && trim($designId) !== '';
        $stageId = $fromDesign ? $designId : ($spec['screenplay_stage_id'] ?? null);
        $subjectId = $spec['character_id'] ?? null;

        if (! is_string($stageId) || trim($stageId) === '' || ! is_string($subjectId) || trim($subjectId) === '') {
            return [null, 'reference_anchor_source_unknown'];
        }

        $stage = VideoPlanningStage::query()
            ->whereKey($stageId)
            ->where('project_id', $projectId)
            ->where('stage', $fromDesign ? PlanningStageName::VESSEL_DESIGN->value : PlanningStageName::SCREENPLAY->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->first();

        $screenplay = is_array($stage?->output_json) ? $stage->output_json : null;

        if ($fromDesign && $screenplay !== null) {
            $screenplay = [
                'design_thesis' => $screenplay['design_thesis'] ?? null,
                'principal_dimensions' => $screenplay['principal_dimensions'] ?? null,
                'characters' => [VesselDesign::vesselRow($screenplay)],
            ];
        }

        if ($stage === null || $screenplay === null) {
            return [null, 'reference_screenplay_unavailable'];
        }

        $subject = collect((array) ($screenplay['characters'] ?? []))
            ->first(static fn (mixed $row): bool => is_array($row) && ($row['id'] ?? null) === $subjectId);

        if (! is_array($subject) || ($subject['kind'] ?? null) !== 'object') {
            return [null, 'reference_anchor_not_object'];
        }

        $bytes = '';

        if ($readBytes) {
            $path = (string) $artifact->storage_path;

            try {
                $disk = Storage::disk((string) $artifact->storage_disk);

                if ($path === '' || ! $disk->exists($path)) {
                    return [null, 'artifact_file_not_found'];
                }

                $bytes = (string) $disk->get($path);
            } catch (Throwable) {
                return [null, 'artifact_file_not_found'];
            }

            if ($bytes === '' || ! hash_equals((string) $artifact->sha256, hash('sha256', $bytes))) {
                return [null, 'artifact_checksum_mismatch'];
            }
        }

        return [[
            'image_id' => (string) $anchor->id,
            'artifact_id' => (string) $artifact->id,
            'sha256' => (string) $artifact->sha256,
            'mime' => (string) ($artifact->mime_type ?: 'image/png'),
            'bytes' => $bytes,
            'screenplay_stage_id' => (string) $stage->id,
            'screenplay' => $screenplay,
            'subject' => $subject,
        ], 'ok'];
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function packet(array $base, ReferenceView $view): array
    {
        $screenplay = $base['screenplay'];
        $subject = $base['subject'];
        $subjectId = (string) $subject['id'];
        $paths = [];

        $design = ['logline' => $screenplay['logline'] ?? null];
        $this->cite($paths, 'design.logline', $design['logline']);

        $thesis = is_array($screenplay['design_thesis'] ?? null) ? $screenplay['design_thesis'] : [];
        $design['design_thesis'] = [];

        foreach (['central_idea', 'visible_difference', 'spatial_consequence', 'coherence', 'realization'] as $key) {
            $design['design_thesis'][$key] = $thesis[$key] ?? null;
            $this->cite($paths, 'design.design_thesis.'.$key, $design['design_thesis'][$key]);
        }

        $dimensions = is_array($screenplay['principal_dimensions'] ?? null) ? $screenplay['principal_dimensions'] : [];
        $design['principal_dimensions'] = [];

        foreach (['length_m', 'beam_m', 'rationale'] as $key) {
            $design['principal_dimensions'][$key] = $dimensions[$key] ?? null;
            $this->cite($paths, 'design.principal_dimensions.'.$key, $design['principal_dimensions'][$key]);
        }

        $packetSubject = [
            'id' => $subjectId,
            'name' => $subject['name'] ?? null,
            'kind' => $subject['kind'] ?? null,
        ];

        foreach (['description', 'appearance'] as $key) {
            $packetSubject[$key] = $subject[$key] ?? null;
            $this->cite($paths, 'subject.'.$key, $packetSubject[$key]);
        }

        if (is_array($subject[ProtagonistProfile::CHARACTER_KEY] ?? null)) {
            $image = ProtagonistProfile::forImage($subject[ProtagonistProfile::CHARACTER_KEY]);
            $profile = [];

            foreach (ProtagonistProfile::IMAGE_SECTIONS as $section) {
                $profile[$section] = $image[$section];
                $this->cite($paths, 'subject.profile.'.$section, $profile[$section]);
            }

            $profile['figures'] = [];

            foreach ($image['figures'] as $figure) {
                $quantity = (string) ($figure['quantity'] ?? '');

                if (preg_match(self::ID_PATTERN, $quantity) === 1) {
                    $profile['figures'][$quantity] = trim(($figure['value'] ?? '').' '.($figure['unit'] ?? '')).' ('.($figure['origin'] ?? '').')';
                    $this->cite($paths, 'subject.profile.figures.'.$quantity, $profile['figures'][$quantity]);
                }
            }

            $profile['signature_features'] = $image['signature_features'];

            foreach ($profile['signature_features'] as $index => $feature) {
                foreach (ProtagonistProfile::RENDERED_FEATURE_FIELDS as $field) {
                    $this->cite($paths, 'subject.profile.signature_features.'.$index.'.'.$field, $feature[$field] ?? null);
                }
            }

            $packetSubject[ProtagonistProfile::CHARACTER_KEY] = $profile;
        }

        $scenes = [];
        $locationIds = [];

        foreach ((array) ($screenplay['scenes'] ?? []) as $scene) {
            $id = is_array($scene) ? (string) ($scene['id'] ?? '') : '';

            if ($id === '' || preg_match(self::ID_PATTERN, $id) !== 1) {
                continue;
            }

            $present = in_array($subjectId, (array) ($scene['character_ids'] ?? []), true)
                || \App\Video\Screenplay\SceneBeats::subjectId($scene) === $subjectId;

            if (! $present) {
                continue;
            }

            $scenes[$id] = [
                'stage' => $scene['stage'] ?? null,
                'location_id' => $scene['location_id'] ?? null,
                'action' => $scene['action'] ?? null,
            ];

            if (is_string($scene['location_id'] ?? null)) {
                $locationIds[$scene['location_id']] = true;
            }
        }

        $locations = [];

        foreach ((array) ($screenplay['locations'] ?? []) as $location) {
            $id = is_array($location) ? (string) ($location['id'] ?? '') : '';

            if (! isset($locationIds[$id]) || preg_match(self::ID_PATTERN, $id) !== 1) {
                continue;
            }

            $locations[$id] = [
                'name' => $location['name'] ?? null,
                'description' => $location['description'] ?? null,
            ];
            $this->cite($paths, 'locations.'.$id.'.description', $locations[$id]['description']);

            if (LocationProfile::isProfiled($location)) {
                $place = LocationProfile::forPrompt($location);
                $locations[$id]['spatial_relation'] = $place['spatial_relation'];

                if ($place['spatial_relation'] === LocationProfile::SUBJECT_PART && $place['subject_id'] === $subjectId) {
                    $locations[$id]['layout'] = $place['layout'];
                    $this->cite($paths, 'locations.'.$id.'.layout', $place['layout']);
                }
            }
        }

        foreach ($scenes as $id => $scene) {
            $this->cite($paths, 'scenes.'.$id.'.action', $scene['action']);
        }

        return [
            'request' => [
                'view' => $view->value,
                'view_label' => $view->label(),
                'camera' => $view->cameraBlock(),
            ],
            'design' => $design,
            'subject' => $packetSubject,
            'locations' => $locations,
            'scenes' => $scenes,
            'citable_paths' => $paths,
        ];
    }

    /**
     * @param  list<string>  $paths
     */
    private function cite(array &$paths, string $path, mixed $value): void
    {
        if ((is_string($value) && trim($value) !== '') || is_int($value) || is_float($value)) {
            $paths[] = $path;
        }
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $packet
     * @return array<string, string>
     */
    private function fingerprint(array $base, array $packet, ReferenceView $view): array
    {
        return [
            'anchor_artifact_id' => $base['artifact_id'],
            'anchor_sha256' => $base['sha256'],
            'screenplay_stage_id' => $base['screenplay_stage_id'],
            'packet_hash' => self::packetHash($packet),
            'view' => $view->value,
            'model' => (string) config('image_prompt.reference.model'),
            'reasoning_effort' => (string) config('image_prompt.reference.reasoning_effort'),
            'skill_hash' => $this->skillHash(),
            'prompt_version' => (string) config('image_prompt.reference.prompt_version'),
            'preservation_version' => IdentityPreservationPrompt::VERSION,
            'camera_version' => ReferenceView::CAMERA_VERSION,
        ];
    }

    /**
     * @param  array<string, mixed>  $packet
     */
    private static function packetHash(array $packet): string
    {
        return hash('sha256', json_encode(
            $packet,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $packet
     */
    private function usable(array $input, array $output, array $base, array $packet, ReferenceView $view): bool
    {
        foreach (self::FINGERPRINT_KEYS as $key) {
            if (! is_string($input[$key] ?? null) || $input[$key] === '') {
                return false;
            }
        }

        return $input['view'] === $view->value
            && hash_equals($input['anchor_artifact_id'], $base['artifact_id'])
            && hash_equals($input['anchor_sha256'], $base['sha256'])
            && hash_equals($input['screenplay_stage_id'], $base['screenplay_stage_id'])
            && hash_equals($input['packet_hash'], self::packetHash($packet))
            && $input['preservation_version'] === IdentityPreservationPrompt::VERSION
            && in_array($input['camera_version'], ReferenceView::SUPPORTED_CAMERA_VERSIONS, true)
            && is_array($output['claims'] ?? null) && $output['claims'] !== []
            && is_array($output['discrepancies'] ?? null)
            && is_bool($output['review_incomplete'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{0: array<string, mixed>, 1: array<string, ?string>, 2: array<string, mixed>}
     */
    private function accounting(array $raw, string $model, int $inputTokens, int $outputTokens, int $reasoningTokens): array
    {
        $prompt = $raw['prompt_tokens'] ?? null;
        $completion = $raw['completion_tokens'] ?? null;
        $cached = $raw['prompt_tokens_details']['cached_tokens'] ?? null;
        $rates = (array) config('image_prompt.reference.pricing');
        $measured = is_int($prompt) && is_int($completion) && is_int($cached) && $cached <= $prompt;

        $cost = $measured
            ? round(
                (($prompt - $cached) * (float) $rates['input_per_million']
                    + $cached * (float) $rates['cached_input_per_million']
                    + $completion * (float) $rates['output_per_million']) / 1_000_000,
                6,
            )
            : 0.0;

        $pricing = $measured
            ? ['pricing' => 'estimated', 'pricing_version' => (string) $rates['version']]
            : ['pricing' => 'unpriced', 'pricing_version' => null];

        return [
            [
                'model' => 'openai',
                'provider_model' => $model,
                'instruction_version' => (string) config('image_prompt.reference.prompt_version'),
                'tokens_in' => $inputTokens,
                'tokens_out' => $outputTokens,
                'thinking_tokens' => is_int($raw['completion_tokens_details']['reasoning_tokens'] ?? null)
                    ? $reasoningTokens
                    : null,
                'cost_usd' => $cost,
            ],
            $pricing,
            $pricing + ['estimated_usd' => $measured ? $cost : null],
        ];
    }

    private function client(): OpenAiTextClient
    {
        return $this->client ??= app('video.reference_prompt.client');
    }

    private function skill(): string
    {
        $path = (string) config('image_prompt.reference.prompt_path');

        if (! is_file($path)) {
            throw new TextCompletionException('Reference prompt skill not found: '.$path);
        }

        return (string) file_get_contents($path);
    }

    private function system(): string
    {
        $skill = $this->skill();
        $position = mb_strpos($skill, self::SPLIT_MARKER);

        if ($position === false) {
            throw new TextCompletionException('Reference prompt skill has no "'.self::SPLIT_MARKER.'" insertion point.');
        }

        return trim(mb_substr($skill, 0, $position));
    }
}
