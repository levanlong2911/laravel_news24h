<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoDesignImage;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Video\Concept\Contracts\StructuredOutputLlmClient;
use App\Video\Concept\Exceptions\AnthropicRefusalException;
use App\Video\Concept\Exceptions\AnthropicTruncatedOutputException;
use App\Video\Prompt\AnchorCoverage;
use App\Video\Prompt\Exceptions\TextCompletionException;
use App\Video\Prompt\Exceptions\TextCompletionRefusalException;
use App\Video\Prompt\OpenAiTextClient;
use App\Video\Prompt\TextCompletionAccounting;
use App\Video\Reference\IdentityPreservationPrompt;
use App\Video\Reference\ReferenceEnvironment;
use App\Video\Reference\ReferenceSheet;
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

    public const PACKET_VERSION = 'reference-packet-v8';

    public const SOURCE_DESIGN = 'vessel_design';

    public const SOURCE_SCREENPLAY = 'screenplay';

    public const MAX_GEOMETRY = 4000;

    public const MAX_CONFLICTS = 12;

    public const MAX_CONFLICT = 400;

    private const SPLIT_MARKER = 'SOURCE MATERIAL:';

    private const ID_PATTERN = '/^[A-Za-z0-9_]+$/';

    public const V3_UNSUPPORTED = 'reference_writer_v3_uses_sheet';

    public const PLAN_VERSION = 'reference-plan-v1';

    public const PLAN_MODE = 'production_plan';

    public const PLAN_DERIVATION_VERSION = 'reference-plan-render-v1';

    private const PLAN_MARKER = 'PLANNING INPUT:';

    public const REUSE_ANCHOR = 'reuse_anchor';

    public const REUSE_REFERENCE = 'reuse_reference';

    public const RENDER_NEW = 'render_new';

    public const NEEDS_REVIEW = 'needs_review';

    /** @var list<string> */
    public const RECOMMENDATIONS = [self::REUSE_ANCHOR, self::REUSE_REFERENCE, self::RENDER_NEW, self::NEEDS_REVIEW];

    public const UNFINISHED = 'unfinished';

    public const FINISHED = 'finished';

    public const BARE_METAL = 'bare_metal';

    public const APPROVED = 'approved';

    public const SKIPPED = 'skipped';

    public const MAX_DESCRIPTION = 6000;

    public const MAX_PLAN_TEXT = 1200;

    /** @var array{pricing: string, pricing_version: null} */
    private const UNPRICED = ['pricing' => 'unpriced', 'pricing_version' => null];

    private const DIRECTIVE_PATTERNS = [
        '/(?:^|[.!?;:\n])\s*\K(?:show|render|photograph|depict|draw|frame)\s+(?:the|this|a|its)\s+(?:vessel|yacht|ship|boat|hull|scene|image|view|shot)\b/iu',
        '/\b(?:seen|viewed|shown|photographed|rendered|pictured|observed)\s+from\b/iu',
        '/\b(?:the|a|this)\s+(?:camera|lens|viewer|observer|viewpoint)(?![\w-])/iu',
        '/\b(?:focal length|field of view|depth of field|wide[- ]angle lens|telephoto|close[- ]up|bird\'?s[- ]eye)\b/iu',
        '/\b(?:in|into|to)\s+the\s+(?:foreground|background)\b|\b(?:against|on)\s+(?:the|an?)\s+(?:[\w-]+\s+)?(?:background|backdrop)\b/iu',
        '/\b(?:studio|soft|hard|dramatic|diffused?|golden[- ]hour|rim|key|three[- ]point)\s+(?:light|lighting|backdrop|background)\b|\b(?:lit|illuminated)\s+(?:by|from|with)\b/iu',
        '/\b(?:left|right|top|bottom|centre|center|edge|corner)\s+of\s+the\s+(?:frame|image|picture|shot|composition)\b/iu',
    ];

    private const DIRECTION_PATTERNS = [
        '/\b(?:show|shows|shown|showing|render|rendered|photograph(?:ed)?|depict(?:s|ed)?|seen|viewed|view|views|viewing|looking|looks|look|observed|pictured)\b[^.;:!?\n]{0,60}?\bfrom\s+(?:the\s+|its\s+)?(?:(?:vessel|yacht|ship|boat)\'?s\s+)?(port|starboard|bow|stern|aft|ahead|astern|forward|front|rear|behind)\b/iu',
        '/\b(?:camera|viewer|observer|viewpoint)\b[^.;:!?\n]{0,40}?\b(?:sees?|faces?|looks?\s+at|is\s+on|stands?\s+(?:off|on|at))\s+(?:the\s+|its\s+)?(port|starboard|bow|stern)\b/iu',
        '/\b(port|starboard|bow|stern)\b[^.;:!?\n]{0,40}?\b(?:toward|towards|facing|faces|nearest|nearer|closest\s+to|closer\s+to)\s+(?:the\s+)?(?:camera|viewer|observer)\b/iu',
    ];

    private const DIRECTION_SIDES = [
        'port' => ['side', 'port'], 'starboard' => ['side', 'starboard'],
        'bow' => ['near_end', 'bow'], 'ahead' => ['near_end', 'bow'], 'forward' => ['near_end', 'bow'], 'front' => ['near_end', 'bow'],
        'stern' => ['near_end', 'stern'], 'aft' => ['near_end', 'stern'], 'astern' => ['near_end', 'stern'], 'rear' => ['near_end', 'stern'], 'behind' => ['near_end', 'stern'],
    ];

    /** @var list<string> */
    private const FINGERPRINT_KEYS = [
        'anchor_artifact_id', 'anchor_sha256', 'screenplay_stage_id', 'packet_hash', 'view',
        'model', 'reasoning_effort', 'skill_hash', 'prompt_version', 'preservation_version', 'camera_version',
    ];

    public function __construct(
        private readonly PlanningStageStore $stages,
        private ?OpenAiTextClient $client = null,
        private ?StructuredOutputLlmClient $planClient = null,
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

        if (self::v3Subject($base) && ! $base['canonical']) {
            return [null, self::V3_UNSUPPORTED, []];
        }

        if ($base['canonical']) {
            $gaps = VesselDesign::referenceDesignGaps($base['design'], $view->value, $view->frame(), $view->proofViews());

            if ($gaps !== []) {
                Log::error('reference-prompt: the view extract references parts the design does not hold, no model call made', [
                    'project_id' => $projectId,
                    'view' => $view->value,
                    'gaps' => $gaps,
                ]);

                return [null, 'reference_design_reference_missing', $gaps];
            }
        }

        $packet = $this->packet($base, $view);
        $input = $this->fingerprint($base, $packet, $view);

        [$claimed, $token, $reason] = $this->stages->claimProjectStage(
            $projectId, PlanningStageName::REFERENCE_PROMPT, $input, $force, ['pricing' => 'unpriced', 'reference_source' => $packet],
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

        [$usage, $pricing, $priced] = $this->accounting($response->usage, $response->model, $response->inputTokens, $response->outputTokens);

        $output = [
            'contract' => self::PACKET_VERSION,
            'view_geometry' => is_array($decoded) && is_string($decoded['view_geometry'] ?? null) ? trim($decoded['view_geometry']) : '',
            'conflicts' => is_array($decoded) && is_array($decoded['conflicts'] ?? null) ? array_values($decoded['conflicts']) : [],
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

        if ($base === null || (self::v3Subject($base) && ! $base['canonical'])) {
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
                $previews[$view->value] = $this->previewOf($stage, $view);
            }
        }

        return $previews;
    }

    private function previewOf(VideoPlanningStage $stage, ReferenceView $view): array
    {
        $output = (array) $stage->output_json;
        $prompts = [];

        foreach (ReferenceEnvironment::cases() as $environment) {
            $prompt = self::assemble((string) $output['view_geometry'], $view, $environment);
            $prompts[$environment->value] = ['prompt' => $prompt, 'prompt_sha256' => hash('sha256', $prompt)];
        }

        return [
            'stage_id' => (string) $stage->id,
            'conflicts' => array_values(array_filter((array) $output['conflicts'], 'is_string')),
            'warnings' => self::warnings((string) $output['view_geometry']),
            'prompts' => $prompts,
        ];
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: string}
     */
    public function sheet(string $projectId, ?VideoDesignImage $anchor, string $state, ReferenceEnvironment $environment): array
    {
        [$base, $why] = $this->base($projectId, $anchor, false);

        if ($base === null) {
            return [null, $why];
        }

        if (! $base['canonical']) {
            return [null, 'reference_sheet_needs_design'];
        }

        [$prompt, $reason] = ReferenceSheet::compile($base['design'], $state, $environment);

        if ($prompt === null) {
            return [null, $reason];
        }

        return [[
            'prompt' => $prompt,
            'prompt_sha256' => hash('sha256', $prompt),
            'anchor_image_id' => $base['image_id'],
            'anchor_artifact_id' => $base['artifact_id'],
            'anchor_sha256' => $base['sha256'],
            'design_stage_id' => $base['screenplay_stage_id'],
            'design_content_hash' => $base['design_content_hash'],
            'state' => $state,
            'environment' => $environment->value,
            'layout_version' => ReferenceSheet::LAYOUT_VERSION,
            'panels' => ReferenceSheet::PANELS,
        ], 'ok'];
    }

    public function sheetFinishReady(string $projectId, ?VideoDesignImage $anchor): bool
    {
        [$base] = $this->base($projectId, $anchor, false);

        return $base !== null && $base['canonical'] && ReferenceSheet::finish($base['design']) !== null;
    }

    /** @return list<string> */
    public static function warnings(string $viewGeometry): array
    {
        return preg_match('/[.!?]["\')\]]?$/u', trim($viewGeometry)) === 1
            ? []
            : ['VIEW GEOMETRY không kết thúc bằng dấu chấm câu — kiểm tra câu cuối có bị cụt không.'];
    }

    /**
     * @return array{kind: ?string, design_stage_id: ?string, design_revision: ?int, design_content_hash: ?string, canonical: bool, error: ?string}
     */
    public function source(string $projectId, ?VideoDesignImage $anchor): array
    {
        [$base, $why] = $this->base($projectId, $anchor, false);

        return [
            'kind' => $base['source_kind'] ?? null,
            'design_stage_id' => ($base['source_kind'] ?? null) === self::SOURCE_DESIGN ? $base['screenplay_stage_id'] : null,
            'design_revision' => $base['design_revision'] ?? null,
            'design_content_hash' => ($base['design_content_hash'] ?? '') === '' ? null : $base['design_content_hash'],
            'canonical' => (bool) ($base['canonical'] ?? false),
            'error' => $base === null ? $why : null,
        ];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    public function retryable(VideoDesignImage $cell, ?VideoDesignImage $anchor): array
    {
        $spec = is_array($cell->prompt_spec_json) ? $cell->prompt_spec_json : [];

        if (($spec['reference_mode'] ?? null) === ReferenceSheet::MODE) {
            $environment = ReferenceEnvironment::tryFrom((string) ($spec['environment'] ?? ''));
            [$sheet, $why] = $environment === null
                ? [null, 'reference_preview_stale']
                : $this->sheet((string) $cell->project_id, $anchor, (string) ($spec['sheet_state'] ?? ''), $environment);

            if ($sheet === null) {
                return [false, $why];
            }

            return ($spec['source_artifact_id'] ?? null) === $sheet['anchor_artifact_id']
                && ($spec['source_artifact_sha256'] ?? null) === $sheet['anchor_sha256']
                && ($spec['prompt'] ?? null) === $sheet['prompt']
                ? [true, 'ok']
                : [false, 'reference_preview_stale'];
        }

        if (($spec['derivation_version'] ?? null) !== self::DERIVATION_VERSION) {
            return [false, 'reference_needs_ai_prompt'];
        }

        $view = ReferenceView::tryFrom((string) ($spec['view_key'] ?? ''));
        $environment = ReferenceEnvironment::tryFrom((string) ($spec['environment'] ?? ''));

        if ($view === null || $environment === null) {
            return [false, 'reference_prompt_stale'];
        }

        [$checked, $why] = $this->checkedStage((string) $cell->project_id, $anchor, (string) ($spec['reference_prompt_stage_id'] ?? ''), $view);

        if ($checked === null) {
            return [false, $why];
        }

        if (($spec['source_artifact_id'] ?? null) !== $checked['base']['artifact_id']
            || ($spec['source_artifact_sha256'] ?? null) !== $checked['base']['sha256']
            || self::assemble((string) $checked['output']['view_geometry'], $view, $environment) !== ($spec['prompt'] ?? null)) {
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
    ): array {
        [$checked, $why] = $this->checkedStage($projectId, $anchor, $stageId, $view);

        if ($checked === null) {
            return [null, $why];
        }

        ['stage' => $stage, 'input' => $input, 'output' => $output, 'base' => $base] = $checked;
        $prompt = self::assemble((string) $output['view_geometry'], $view, $environment);

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
        ], 'ok'];
    }

    /**
     * @return array{0: ?array{stage: VideoPlanningStage, input: array<string, mixed>, output: array<string, mixed>, base: array<string, mixed>}, 1: string}
     */
    private function checkedStage(string $projectId, ?VideoDesignImage $anchor, string $stageId, ReferenceView $view): array
    {
        [$base, $why] = $this->base($projectId, $anchor);

        if ($base === null) {
            return [null, $why];
        }

        if (self::v3Subject($base) && ! $base['canonical']) {
            return [null, self::V3_UNSUPPORTED];
        }

        $stage = VideoPlanningStage::query()
            ->whereKey($stageId)
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::REFERENCE_PROMPT->value)
            ->first();

        if ($stage === null || $stage->status !== VideoPlanningStageStatus::SUCCEEDED->value) {
            return [null, 'reference_prompt_not_found'];
        }

        $input = is_array($stage->input_json) ? $stage->input_json : [];
        $output = is_array($stage->output_json) ? $stage->output_json : [];

        if (! $this->usable($input, $output, $base, $this->packet($base, $view), $view)) {
            return [null, 'reference_prompt_stale'];
        }

        return [['stage' => $stage, 'input' => $input, 'output' => $output, 'base' => $base], 'ok'];
    }

    public static function assemble(string $viewGeometry, ReferenceView $view, ReferenceEnvironment $environment): string
    {
        $blocks = [
            IdentityPreservationPrompt::editTarget($view->label(), $environment->override() !== ''),
            IdentityPreservationPrompt::text(),
            "VIEW GEOMETRY:\n".trim($viewGeometry),
            $view->cameraBlock(),
        ];

        if ($environment->override() !== '') {
            $blocks[] = $environment->override();
        }

        $blocks[] = IdentityPreservationPrompt::referenceState();

        return implode("\n\n", $blocks);
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['view_geometry', 'conflicts'],
            'properties' => [
                'view_geometry' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_GEOMETRY],
                'conflicts' => [
                    'type' => 'array',
                    'maxItems' => self::MAX_CONFLICTS,
                    'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_CONFLICT],
                ],
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
        $geometry = $decoded['view_geometry'] ?? null;
        $conflicts = $decoded['conflicts'] ?? null;

        if (! is_string($geometry) || trim($geometry) === '') {
            $violations[] = 'view_geometry is empty';
        } else {
            if (mb_strlen($geometry) >= self::MAX_GEOMETRY) {
                $violations[] = 'view_geometry reaches the '.self::MAX_GEOMETRY.'-character limit; as a precaution an answer at the limit is not used';
            }

            foreach (AnchorCoverage::internalIds($geometry, $packet) as $id) {
                $violations[] = "view_geometry writes the internal id {$id} instead of naming the part";
            }

            $directives = [];

            foreach (self::DIRECTIVE_PATTERNS as $pattern) {
                preg_match_all($pattern, $geometry, $matches);
                array_push($directives, ...array_map(static fn (string $match): string => mb_strtolower(trim($match)), $matches[0]));
            }

            if ($directives !== []) {
                $violations[] = 'view_geometry gives a camera, environment or lighting instruction ('
                    .implode(', ', array_values(array_unique($directives))).')';
            }

            array_push($violations, ...self::oppositeDirections($geometry, ReferenceView::tryFrom((string) ($packet['request']['view'] ?? ''))?->frame()));
        }

        if (! is_array($conflicts) || ! array_is_list($conflicts)) {
            $violations[] = 'conflicts must be a list';
        } else {
            if (count($conflicts) > self::MAX_CONFLICTS) {
                $violations[] = 'conflicts hold more than '.self::MAX_CONFLICTS.' items';
            }

            foreach ($conflicts as $index => $conflict) {
                if (! is_string($conflict) || trim($conflict) === '' || mb_strlen($conflict) > self::MAX_CONFLICT) {
                    $violations[] = "conflicts[{$index}]: must be text of at most ".self::MAX_CONFLICT.' characters';
                }
            }
        }

        return $violations;
    }

    /**
     * @param  array{near_end: ?string, side: ?string}|null  $frame  the end and the side the camera sees
     * @return list<string>
     */
    private static function oppositeDirections(string $geometry, ?array $frame): array
    {
        if ($frame === null) {
            return [];
        }

        $violations = [];

        foreach (self::DIRECTION_PATTERNS as $pattern) {
            preg_match_all($pattern, $geometry, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                [$axis, $direction] = self::DIRECTION_SIDES[mb_strtolower($match[1])];

                if ($frame[$axis] !== null && $frame[$axis] !== $direction) {
                    $violations[] = 'view_geometry directs the view to the '.$direction.' ("'.trim($match[0]).'") but this camera sees the '
                        .$frame[$axis].($axis === 'side' ? ' side' : ' end');
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * @param  array<string, mixed>  $source  the verified production source, its needs and the reusable references
     * @param  (callable(): ?string)|null  $recheck  why the production source no longer matches; null while it does
     * @return array{0: ?VideoPlanningStage, 1: string, 2: list<string>}
     */
    public function writePlan(string $projectId, array $source, bool $force = false, ?callable $recheck = null): array
    {
        if ($source['needs'] === []) {
            return [null, 'reference_plan_no_needs', []];
        }

        $packet = self::planPacket($source);
        $input = $this->planFingerprint($source, $packet);

        [$claimed, $token, $reason] = $this->stages->claimProjectStage(
            $projectId, PlanningStageName::REFERENCE_PROMPT, $input, $force, ['pricing' => 'unpriced'],
        );

        if ($claimed === null) {
            return [null, $reason, []];
        }

        if ($reason === 'already_succeeded') {
            return [$claimed, 'reference_plan_cached', []];
        }

        if ($token === null) {
            return [null, 'reference_prompt_running', []];
        }

        $model = (string) config('image_prompt.reference_plan.model');

        try {
            $response = $this->planClient()->create(
                model: $model,
                system: $this->planSkill(),
                messages: [['role' => 'user', 'content' => [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $source['anchor_mime'], 'data' => base64_encode($source['anchor_bytes'])]],
                    ['type' => 'text', 'text' => self::PLAN_MARKER."\n\n".json_encode($packet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)],
                ]]],
                outputSchema: self::planSchema(),
                maxTokens: (int) config('image_prompt.reference_plan.max_tokens'),
            );
        } catch (Throwable $e) {
            $failed = $e instanceof AnthropicRefusalException || $e instanceof AnthropicTruncatedOutputException ? $e->response : null;
            $usage = $failed === null ? [] : $this->planUsage($failed->model, $failed->inputTokens, $failed->outputTokens);
            $this->stages->finishFailed((string) $claimed->id, $token, $e->getMessage(), $usage, (string) ($failed?->rawText ?? ''), [], self::UNPRICED);
            Log::error('reference-plan: author call failed', ['project_id' => $projectId, 'stage_id' => $claimed->id, 'exception' => $e]);

            return [null, 'reference_plan_call_failed', [$e->getMessage()]];
        }

        $usage = $this->planUsage($response->model, $response->inputTokens, $response->outputTokens);
        $decoded = json_decode($response->rawText, true);
        $violations = is_array($decoded) ? self::planViolations($decoded, $source) : ['the answer is not a JSON object'];
        $changed = $recheck === null ? null : $recheck();

        if ($violations === [] && $changed !== null) {
            $violations = ['the production source changed during the call ('.$changed.'); the answer is kept and not applied'];
        }

        $output = [
            'contract' => self::PLAN_VERSION,
            'author_model' => $response->model,
            'usage' => $usage,
        ] + ($violations === [] ? self::normalizedPlan($decoded, $source) : ['violations' => $violations]);

        $recorded = $violations === []
            ? $this->stages->finishSucceeded((string) $claimed->id, $token, $response->rawText, $output, $usage, self::UNPRICED)
            : $this->stages->finishFailed((string) $claimed->id, $token, 'Reference plan failed validation: '.implode('; ', $violations), $usage, $response->rawText, $output, self::UNPRICED);

        if (! $recorded) {
            $this->stages->recordOrphanAttempt($projectId, PlanningStageName::REFERENCE_PROMPT, $input, self::UNPRICED, (string) $claimed->id, $token,
                'Claim lost before the reference plan was recorded.', $usage, $response->rawText, $output);

            return [null, 'reference_prompt_claim_lost', $violations];
        }

        if ($changed !== null) {
            return [null, 'reference_plan_source_changed', $violations];
        }

        return $violations === [] ? [$claimed->fresh(), 'reference_plan_written', []] : [null, 'reference_plan_invalid', $violations];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return VideoPlanningStage|null the newest written plan for exactly this production source and these needs
     */
    public function currentPlan(string $projectId, array $source): ?VideoPlanningStage
    {
        $keys = self::planSourceKeys($source);

        return VideoPlanningStage::query()
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::REFERENCE_PROMPT->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->where('input_json->mode', self::PLAN_MODE)
            ->orderByDesc('planning_revision')
            ->get()
            ->first(fn (VideoPlanningStage $stage): bool => ($stage->output_json['contract'] ?? null) === self::PLAN_VERSION
                && array_intersect_key((array) $stage->input_json, $keys) == $keys);
    }

    /**
     * @param  array<string, mixed>  $item  one normalized reference of a plan
     */
    public static function assemblePlanned(array $item, ReferenceEnvironment $environment): string
    {
        $view = ReferenceView::tryFrom((string) $item['view']);
        $label = $view?->label() ?? 'bow three-quarter port';
        $changes = array_values(array_filter([
            $view === null ? null : 'the camera viewpoint to the '.$label.' view',
            'the vessel to the build state described below',
            $environment->override() !== '' ? 'the surroundings stated below' : null,
        ]));
        $blocks = [
            'Edit the supplied image: change only '.implode(', ', array_slice($changes, 0, -1)).(count($changes) > 1 ? ' and ' : '').end($changes)
                .($view === null ? ', keeping the supplied image\'s camera viewpoint' : '').'. It is the same vessel, not a new design.',
            IdentityPreservationPrompt::plannedText(),
            'THE VESSEL AT THIS VIEW ('.$item['presentation'].'):'."\n".trim((string) $item['description']),
        ];

        if ($view !== null) {
            $blocks[] = $view->cameraBlock();
        }

        if ($environment->override() !== '') {
            $blocks[] = $environment->override();
        }

        $blocks[] = IdentityPreservationPrompt::lighting()."\n".IdentityPreservationPrompt::plannedClosing();

        return implode("\n\n", $blocks);
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array{0: ?array<string, mixed>, 1: string}
     */
    public function plannedRenderable(string $projectId, array $source, string $stageId, string $requestId, ReferenceEnvironment $environment, string $promptSha256): array
    {
        $stage = $this->currentPlan($projectId, $source);

        if ($stage === null || (string) $stage->id !== $stageId) {
            return [null, 'reference_plan_stale'];
        }

        $item = collect((array) ($stage->output_json['references'] ?? []))->firstWhere('request_id', $requestId);

        if (! is_array($item) || $item['recommendation'] !== self::RENDER_NEW) {
            return [null, 'reference_plan_item_not_renderable'];
        }

        if (($stage->output_json['decisions'][$requestId]['status'] ?? null) === self::SKIPPED) {
            return [null, 'reference_plan_item_skipped'];
        }

        $prompt = self::assemblePlanned($item, $environment);

        if (! hash_equals(hash('sha256', $prompt), $promptSha256)) {
            return [null, 'reference_preview_stale'];
        }

        return [['prompt' => $prompt, 'stage' => $stage, 'item' => $item], 'ok'];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed> what Claude receives: the needs, the views, the reusable images and the whole design once
     */
    public static function planPacket(array $source): array
    {
        $views = [['view' => ReferenceView::DESIGN_ANCHOR_VIEW, 'label' => 'Bow three-quarter port (the anchor view)', 'camera' => 'The camera of the supplied anchor image.']];

        foreach (ReferenceView::cases() as $view) {
            $views[] = ['view' => $view->value, 'label' => $view->label(), 'camera' => $view->cameraBlock()];
        }

        return [
            'needs' => array_map(static fn (array $need): array => array_diff_key($need, ['need_sha256' => true]), $source['needs']),
            'views' => $views,
            'existing_references' => $source['catalog'],
            'design' => array_diff_key($source['design'], ['author_model' => true]),
        ];
    }

    /** @return array<string, mixed> */
    public static function planSchema(): array
    {
        $views = [ReferenceView::DESIGN_ANCHOR_VIEW, ...ReferenceView::values()];
        $text = ['type' => 'string'];
        $texts = ['type' => 'array', 'items' => $text];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['references', 'unresolved'],
            'properties' => [
                'references' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['need_ids', 'view', 'presentation', 'state_details', 'recommendation', 'existing_artifact_id', 'description', 'source_refs', 'reason', 'findings'],
                    'properties' => [
                        'need_ids' => $texts,
                        'view' => ['type' => 'string', 'enum' => $views],
                        'presentation' => ['type' => 'string', 'enum' => [self::UNFINISHED, self::FINISHED]],
                        'state_details' => $text,
                        'recommendation' => ['type' => 'string', 'enum' => self::RECOMMENDATIONS],
                        'existing_artifact_id' => ['anyOf' => [$text, ['type' => 'null']]],
                        'description' => $text,
                        'source_refs' => $texts,
                        'reason' => $text,
                        'findings' => $texts,
                    ],
                ]],
                'unresolved' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['need_ids', 'reason'],
                    'properties' => ['need_ids' => $texts, 'reason' => $text],
                ]],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  array<string, mixed>  $source
     * @return list<string> why the plan cannot be applied; empty when PHP finds nothing wrong (which does not prove the prose right)
     */
    public static function planViolations(array $decoded, array $source): array
    {
        $references = $decoded['references'] ?? null;
        $unresolved = $decoded['unresolved'] ?? null;

        if (! is_array($references) || ! array_is_list($references) || ! is_array($unresolved) || ! array_is_list($unresolved)) {
            return ['references and unresolved must be lists'];
        }

        $needs = collect($source['needs'])->keyBy('need_id')->all();
        $catalog = collect($source['catalog'])->keyBy('artifact_id')->all();
        $views = [ReferenceView::DESIGN_ANCHOR_VIEW, ...ReferenceView::values()];
        $seen = [];
        $violations = [];
        $claim = static function (mixed $ids, string $at) use (&$seen, &$violations, $needs): array {
            if (! is_array($ids) || ! array_is_list($ids) || $ids === []) {
                $violations[] = $at.': need_ids must be a nonempty list';

                return [];
            }

            $known = [];

            foreach ($ids as $id) {
                if (! is_string($id) || ! isset($needs[$id])) {
                    $violations[] = $at.': need '.var_export($id, true).' is not a need of the production storyboard';
                } elseif (isset($seen[$id])) {
                    $violations[] = $at.': need '.$id.' is answered twice';
                } else {
                    $seen[$id] = true;
                    $known[] = $needs[$id];
                }
            }

            return $known;
        };

        foreach ($references as $index => $item) {
            $at = 'references['.$index.']';

            if (! is_array($item)) {
                $violations[] = $at.': must be an object';

                continue;
            }

            $served = $claim($item['need_ids'] ?? null, $at);
            $view = $item['view'] ?? null;
            $presentation = $item['presentation'] ?? null;
            $recommendation = $item['recommendation'] ?? null;
            $description = is_string($item['description'] ?? null) ? trim($item['description']) : null;

            if (! in_array($view, $views, true) || ! in_array($presentation, [self::UNFINISHED, self::FINISHED], true)
                || ! in_array($recommendation, self::RECOMMENDATIONS, true) || $description === null) {
                $violations[] = $at.': view, presentation, recommendation and description must take their listed values';

                continue;
            }

            foreach (['state_details', 'reason'] as $field) {
                if (! is_string($item[$field] ?? null) || trim($item[$field]) === '' || mb_strlen($item[$field]) > self::MAX_PLAN_TEXT) {
                    $violations[] = $at.': '.$field.' must be text of 1 to '.self::MAX_PLAN_TEXT.' characters';
                }
            }

            foreach ($served as $need) {
                if ($need['view'] !== null && $need['view'] !== $view) {
                    $violations[] = $at.': need '.$need['need_id'].' asks for '.$need['view'].', not '.$view;
                }

                if ($need['kind'] === 'identity' && $need['presentation'] !== $presentation) {
                    $violations[] = $at.': need '.$need['need_id'].' is in a '.$need['presentation'].' scene ('.$need['stage'].'), not '.$presentation;
                }
            }

            if (count(array_unique(array_column($served, 'kind'))) > 1) {
                $violations[] = $at.': one image serves both the real vessel and a drawing or model';
            }

            $stages = array_unique(array_column(array_filter($served, static fn (array $need): bool => $need['kind'] === 'identity' && $need['presentation'] === self::UNFINISHED), 'stage'));

            if (count($stages) > 1) {
                $violations[] = $at.': one unfinished image serves needs of different build stages ('.implode(', ', $stages).')';
            }

            $artifact = $item['existing_artifact_id'] ?? null;
            $findings = $item['findings'] ?? null;

            if (! is_array($findings) || ! array_is_list($findings) || array_filter($findings, static fn (mixed $line): bool => ! is_string($line) || trim($line) === '') !== []) {
                $violations[] = $at.': findings must be a list of text';
                $findings = [];
            }

            $wrong = match ($recommendation) {
                self::REUSE_ANCHOR => $view !== ReferenceView::DESIGN_ANCHOR_VIEW || $artifact !== null || $description !== ''
                    ? 'reuse_anchor is the anchor view with no artifact and no description' : null,
                self::REUSE_REFERENCE => ! is_string($artifact) || ! isset($catalog[$artifact]) || $catalog[$artifact]['view'] !== $view || $description !== ''
                    ? 'reuse_reference names an existing reference of this view and writes no description' : null,
                self::NEEDS_REVIEW => $artifact !== null || $description !== '' || $findings === []
                    ? 'needs_review gives its findings and no artifact or description' : null,
                default => $artifact !== null || $description === '' || mb_strlen($description) > self::MAX_DESCRIPTION
                    ? 'render_new writes a description of at most '.self::MAX_DESCRIPTION.' characters and names no artifact' : null,
            };

            if ($wrong !== null) {
                $violations[] = $at.': '.$wrong;
            }

            $reused = match ($recommendation) {
                self::REUSE_ANCHOR => ['presentation' => self::BARE_METAL, 'build_stage' => null, 'name' => 'the anchor'],
                self::REUSE_REFERENCE => is_string($artifact) && isset($catalog[$artifact])
                    ? $catalog[$artifact] + ['name' => 'reference '.$artifact] : null,
                default => null,
            };

            if ($reused !== null) {
                array_push($violations, ...array_map(static fn (string $line): string => $at.': '.$line, self::reuseViolations($reused, $served, $presentation)));
            }

            $refs = $item['source_refs'] ?? null;

            if (! is_array($refs) || ! array_is_list($refs) || ($recommendation === self::RENDER_NEW && $refs === [])) {
                $violations[] = $at.': source_refs must list the design paths the reference rests on';
                $refs = [];
            }

            $cited = [];

            foreach ($refs as $ref) {
                $found = is_string($ref) ? self::designPath($source['design'], $ref) : null;

                if ($found === null) {
                    $violations[] = $at.': source path '.var_export($ref, true).' does not exist in the design';
                } else {
                    $cited[] = $found;
                }
            }

            if ($recommendation === self::RENDER_NEW && $description !== '') {
                array_push($violations, ...array_map(static fn (string $line): string => $at.': '.$line, self::proseViolations($description, self::viewFrame($view), $source['design'], $cited)));
            }
        }

        foreach ($unresolved as $index => $item) {
            $claim(is_array($item) ? ($item['need_ids'] ?? null) : null, 'unresolved['.$index.']');

            if (! is_array($item) || ! is_string($item['reason'] ?? null) || trim($item['reason']) === '') {
                $violations[] = 'unresolved['.$index.']: gives a reason';
            }
        }

        foreach (array_diff(array_keys($needs), array_keys($seen)) as $missing) {
            $violations[] = 'need '.$missing.' is neither served nor unresolved';
        }

        return array_values(array_unique($violations));
    }

    /**
     * @param  array{presentation: string, build_stage: ?string, name: string}  $image  the image a reference reuses, with the state it was made in
     * @param  list<array<string, mixed>>  $served
     * @return list<string> why the image cannot stand for these needs: a real vessel shown in another finish or at another build stage
     */
    private static function reuseViolations(array $image, array $served, string $presentation): array
    {
        $violations = [];

        if ($image['presentation'] !== self::BARE_METAL && $image['presentation'] !== $presentation) {
            $violations[] = 'reuses '.$image['name'].', made '.$image['presentation'].', as '.$presentation;
        }

        foreach ($served as $need) {
            if ($need['kind'] !== 'identity') {
                continue;
            }

            if ($image['presentation'] !== $need['presentation']) {
                $violations[] = 'need '.$need['need_id'].' shows the vessel '.$need['presentation'].' ('.$need['stage'].'), but '.$image['name'].' shows it '.$image['presentation'];
            } elseif ($need['presentation'] === self::UNFINISHED && $image['build_stage'] !== $need['stage']) {
                $violations[] = 'need '.$need['need_id'].' is at the '.$need['stage'].' stage, but '.$image['name'].' shows the '.($image['build_stage'] ?? 'unknown').' stage';
            }
        }

        return $violations;
    }

    /**
     * @return array{near_end: ?string, side: ?string}|null the end and the side a reference view or the anchor view sees
     */
    public static function viewFrame(string $view): ?array
    {
        if ($view === ReferenceView::DESIGN_ANCHOR_VIEW) {
            return ReferenceView::DESIGN_ANCHOR_FRAME;
        }

        $frame = ReferenceView::tryFrom($view)?->frame();

        return $frame === null ? null : ['near_end' => $frame['near_end'], 'side' => $frame['side']];
    }

    /**
     * @param  array{near_end: ?string, side: ?string}|null  $frame  the end and the side the camera sees
     * @param  array<string, mixed>  $design
     * @param  list<mixed>  $cited  the design values the prose cites
     * @return list<string> what the prose does wrong that code can see: an internal id, a camera, light or surroundings instruction,
     *                      a side or end this camera does not see, or a number no cited value holds
     */
    public static function proseViolations(string $description, ?array $frame, array $design, array $cited): array
    {
        $violations = [];

        foreach (AnchorCoverage::internalIds($description, $design) as $id) {
            $violations[] = 'the description writes the internal id '.$id.' instead of naming the part';
        }

        $directives = [];

        foreach (self::DIRECTIVE_PATTERNS as $pattern) {
            preg_match_all($pattern, $description, $matches);
            array_push($directives, ...array_map(static fn (string $match): string => mb_strtolower(trim($match)), $matches[0]));
        }

        if ($directives !== []) {
            $violations[] = 'the description gives a camera, surroundings or lighting instruction ('.implode(', ', array_values(array_unique($directives))).')';
        }

        foreach (self::oppositeDirections($description, $frame) as $line) {
            $violations[] = str_replace('view_geometry', 'the description', $line);
        }

        $known = [];
        array_walk_recursive($cited, static function (mixed $value) use (&$known): void {
            if (is_int($value) || is_float($value) || is_string($value)) {
                preg_match_all('/\d+(?:[.,]\d+)?/', (string) $value, $numbers);
                $known = [...$known, ...$numbers[0]];
            }
        });
        preg_match_all('/\d+(?:[.,]\d+)?/', $description, $written);
        $unknown = array_values(array_diff(array_unique($written[0]), $known));

        if ($unknown !== []) {
            $violations[] = 'the description writes the number(s) '.implode(', ', $unknown).', which no cited source path holds';
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $design
     * @return mixed the value at a dotted path, a list row named by its id; null when the path does not exist
     */
    public static function designPath(array $design, string $path): mixed
    {
        $node = $design;

        foreach (explode('.', trim($path)) as $segment) {
            if (! is_array($node) || $segment === '') {
                return null;
            }

            if (array_is_list($node)) {
                $row = collect($node)->first(static fn (mixed $row): bool => is_array($row)
                    && in_array($segment, [$row['id'] ?? null, $row['feature_id'] ?? null, $row['component_id'] ?? null], true));
                $node = $row ?? (ctype_digit($segment) ? ($node[(int) $segment] ?? null) : null);
            } else {
                $node = array_key_exists($segment, $node) ? $node[$segment] : null;
            }

            if ($node === null) {
                return null;
            }
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  array<string, mixed>  $source
     * @return array{references: list<array<string, mixed>>, unresolved: list<array<string, mixed>>, interior: list<array<string, mixed>>, needs: list<array<string, mixed>>}
     */
    private static function normalizedPlan(array $decoded, array $source): array
    {
        $order = array_flip([ReferenceView::DESIGN_ANCHOR_VIEW, ...array_map(static fn (ReferenceView $view): string => $view->value, ReferenceView::menu()), ...ReferenceView::values()]);
        $needs = collect($source['needs'])->keyBy('need_id');
        $references = [];

        foreach ($decoded['references'] as $item) {
            $ids = array_values($item['need_ids']);
            sort($ids);
            $references[] = [
                'request_id' => 'ref_'.substr(hash('sha256', json_encode([$item['view'], $item['presentation'], trim($item['state_details']), $ids, $source['design_content_hash']], JSON_THROW_ON_ERROR)), 0, 12),
                'need_ids' => $ids,
                'shots' => array_values(array_unique($needs->only($ids)->pluck('shot_code')->all())),
                'kind' => $needs[$ids[0]]['kind'],
                'view' => $item['view'],
                'presentation' => $item['presentation'],
                'state_details' => trim($item['state_details']),
                'recommendation' => $item['recommendation'],
                'existing_artifact_id' => $item['existing_artifact_id'],
                'description' => trim($item['description']),
                'source_refs' => array_values($item['source_refs']),
                'reason' => trim($item['reason']),
                'findings' => array_values(array_map('trim', $item['findings'])),
            ];
        }

        usort($references, static fn (array $a, array $b): int => [$a['presentation'] === self::FINISHED, $order[$a['view']] ?? PHP_INT_MAX, $a['request_id']]
            <=> [$b['presentation'] === self::FINISHED, $order[$b['view']] ?? PHP_INT_MAX, $b['request_id']]);

        return [
            'references' => $references,
            'unresolved' => array_values(array_map(static fn (array $item): array => ['need_ids' => array_values($item['need_ids']), 'reason' => trim($item['reason'])], $decoded['unresolved'])),
            'interior' => $source['interior'],
            'needs' => $source['needs'],
            'decisions' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $packet
     * @return array<string, mixed>
     */
    private function planFingerprint(array $source, array $packet): array
    {
        return self::planSourceKeys($source) + [
            'catalog_hash' => hash('sha256', json_encode($source['catalog'], JSON_THROW_ON_ERROR)),
            'packet_hash' => self::packetHash($packet),
            'model' => (string) config('image_prompt.reference_plan.model'),
            'effort' => (string) config('image_prompt.reference_plan.effort'),
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed> the inputs a written plan must still match to be used
     */
    private static function planSourceKeys(array $source): array
    {
        return [
            'mode' => self::PLAN_MODE,
            'plan_version' => self::PLAN_VERSION,
            'prompt_version' => (string) config('image_prompt.reference_plan.prompt_version'),
            'skill_hash' => hash('sha256', (string) @file_get_contents((string) config('image_prompt.reference_plan.prompt_path'))),
            'storyboard_stage_id' => $source['storyboard_stage_id'],
            'storyboard_revision' => $source['storyboard_revision'],
            'screenplay_stage_id' => $source['screenplay_stage_id'],
            'screenplay_hash' => $source['screenplay_hash'],
            'design_stage_id' => $source['design_stage_id'],
            'design_revision' => $source['design_revision'],
            'design_content_hash' => $source['design_content_hash'],
            'anchor_artifact_id' => $source['anchor_artifact_id'],
            'anchor_sha256' => $source['anchor_sha256'],
            'needs_hash' => hash('sha256', json_encode([$source['needs'], $source['interior']], JSON_THROW_ON_ERROR)),
        ];
    }

    /** @return array<string, mixed> */
    private function planUsage(string $model, int $inputTokens, int $outputTokens): array
    {
        return [
            'model' => 'anthropic',
            'provider_model' => $model,
            'instruction_version' => (string) config('image_prompt.reference_plan.prompt_version'),
            'tokens_in' => $inputTokens,
            'tokens_out' => $outputTokens,
            'thinking_tokens' => null,
            'cost_usd' => 0.0,
        ];
    }

    private function planClient(): StructuredOutputLlmClient
    {
        return $this->planClient ??= app('video.reference_plan.client');
    }

    private function planSkill(): string
    {
        $path = (string) config('image_prompt.reference_plan.prompt_path');

        if (! is_file($path)) {
            throw new TextCompletionException('Reference plan skill not found: '.$path);
        }

        $skill = (string) file_get_contents($path);
        $position = mb_strpos($skill, self::PLAN_MARKER);

        return trim($position === false ? $skill : mb_substr($skill, 0, $position));
    }

    public function skillHash(): string
    {
        return hash('sha256', $this->skill());
    }

    /** @param array<string, mixed> $output */
    private static function contractOutput(array $output): bool
    {
        return ($output['contract'] ?? null) === self::PACKET_VERSION
            && is_string($output['view_geometry'] ?? null) && trim($output['view_geometry']) !== ''
            && is_array($output['conflicts'] ?? null);
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

        if ((string) $anchor->project_id !== $projectId || (string) $artifact->project_id !== $projectId) {
            return [null, 'reference_anchor_foreign'];
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
        $design = $fromDesign ? $screenplay : null;

        if ($design !== null) {
            $stamped = $spec['design_content_hash'] ?? null;

            if (is_string($stamped) && $stamped !== '' && ! hash_equals($stamped, VesselDesign::contentHash($design))) {
                return [null, 'reference_anchor_design_changed'];
            }

            $lock = app(VesselDesignService::class)->anchorSelection(VideoProject::query()->find($projectId));

            if (is_array($lock) && ($lock['design_stage_id'] ?? null) !== $designId) {
                return [null, 'reference_anchor_other_design'];
            }

            $screenplay = [
                'design_thesis' => $design['design_thesis'] ?? null,
                'principal_dimensions' => $design['principal_dimensions'] ?? null,
                'characters' => [VesselDesign::vesselRow($design)],
            ];
        }

        if ($design !== null && VesselDesign::hasCanonical($design)) {
            $broken = VesselDesign::extractIntegrityViolations($design, VesselDesign::lockedDesign($design, null));

            if ($broken !== []) {
                Log::error('reference-prompt: the locked design extract is incomplete, no model call made', [
                    'project_id' => $projectId,
                    'design_stage_id' => $stageId,
                    'violations' => $broken,
                ]);

                return [null, 'reference_design_extract_invalid'];
            }
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
            'source_kind' => $design === null ? self::SOURCE_SCREENPLAY : self::SOURCE_DESIGN,
            'design' => $design,
            'design_revision' => $design === null ? null : (int) $stage->planning_revision,
            'design_content_hash' => $design === null ? '' : VesselDesign::contentHash($design),
            'canonical' => $design !== null && VesselDesign::hasCanonical($design),
        ], 'ok'];
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function packet(array $base, ReferenceView $view): array
    {
        $request = [
            'view' => $view->value,
            'view_label' => $view->label(),
            'camera' => $view->cameraBlock(),
        ];

        if ($base['canonical']) {
            return [
                'request' => $request,
                'supplied_image_camera' => ReferenceView::suppliedImageCamera(ReferenceView::DESIGN_ANCHOR_VIEW),
                'reference_design' => VesselDesign::referenceDesign($base['design'], $view->value, $view->frame(), $view->proofViews()),
            ];
        }

        $screenplay = $base['screenplay'];
        $subject = $base['subject'];
        $subjectId = (string) $subject['id'];
        $thesis = is_array($screenplay['design_thesis'] ?? null) ? $screenplay['design_thesis'] : [];
        $dimensions = is_array($screenplay['principal_dimensions'] ?? null) ? $screenplay['principal_dimensions'] : [];
        $design = ['logline' => $screenplay['logline'] ?? null, 'design_thesis' => [], 'principal_dimensions' => []];

        foreach (['central_idea', 'visible_difference', 'spatial_consequence', 'coherence', 'realization'] as $key) {
            if ($key !== 'realization' || array_key_exists($key, $thesis)) {
                $design['design_thesis'][$key] = $thesis[$key] ?? null;
            }
        }

        foreach (['length_m', 'beam_m', 'rationale'] as $key) {
            $design['principal_dimensions'][$key] = $dimensions[$key] ?? null;
        }

        $packetSubject = [
            'id' => $subjectId,
            'name' => $subject['name'] ?? null,
            'kind' => $subject['kind'] ?? null,
            'description' => $subject['description'] ?? null,
            'appearance' => $subject['appearance'] ?? null,
        ];

        if (is_array($subject[ProtagonistProfile::CHARACTER_KEY] ?? null)) {
            $image = ProtagonistProfile::forImage($subject[ProtagonistProfile::CHARACTER_KEY]);
            $profile = array_intersect_key($image, array_flip(ProtagonistProfile::IMAGE_SECTIONS));
            $profile['figures'] = [];

            foreach ($image['figures'] as $figure) {
                $quantity = (string) ($figure['quantity'] ?? '');

                if (preg_match(self::ID_PATTERN, $quantity) === 1) {
                    $profile['figures'][$quantity] = trim(($figure['value'] ?? '').' '.($figure['unit'] ?? '')).' ('.($figure['origin'] ?? '').')';
                }
            }

            $profile['signature_features'] = $image['signature_features'];
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

            if (LocationProfile::isProfiled($location)) {
                $place = LocationProfile::forPrompt($location);
                $locations[$id]['spatial_relation'] = $place['spatial_relation'];

                if ($place['spatial_relation'] === LocationProfile::SUBJECT_PART && $place['subject_id'] === $subjectId) {
                    $locations[$id]['layout'] = $place['layout'];
                }
            }
        }

        return [
            'request' => $request,
            'design' => $design,
            'subject' => $packetSubject,
            'locations' => $locations,
            'scenes' => $scenes,
        ];
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
            'source_kind' => $base['source_kind'],
            'design_content_hash' => $base['design_content_hash'],
            'packet_version' => self::PACKET_VERSION,
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @return bool true when the subject comes from a vessel_design_v3 design, whose reference is the contact sheet, not the per-view writer
     */
    public static function v3Subject(array $base): bool
    {
        return VesselDesign::isV3($base['design'] ?? null) || VesselDesign::isV3Profile($base['subject'][ProtagonistProfile::CHARACTER_KEY] ?? null);
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

        if ($input['skill_hash'] !== $this->skillHash()
            || $input['prompt_version'] !== (string) config('image_prompt.reference.prompt_version')
            || ($input['packet_version'] ?? null) !== self::PACKET_VERSION
            || ($input['source_kind'] ?? null) !== $base['source_kind']
            || ! hash_equals((string) ($input['design_content_hash'] ?? ''), $base['design_content_hash'])) {
            return false;
        }

        return $input['view'] === $view->value
            && hash_equals($input['anchor_artifact_id'], $base['artifact_id'])
            && hash_equals($input['anchor_sha256'], $base['sha256'])
            && hash_equals($input['screenplay_stage_id'], $base['screenplay_stage_id'])
            && hash_equals($input['packet_hash'], self::packetHash($packet))
            && $input['preservation_version'] === IdentityPreservationPrompt::VERSION
            && in_array($input['camera_version'], ReferenceView::SUPPORTED_CAMERA_VERSIONS, true)
            && self::contractOutput($output);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{0: array<string, mixed>, 1: array<string, ?string>, 2: array<string, mixed>}
     */
    private function accounting(array $raw, string $model, int $inputTokens, int $outputTokens): array
    {
        [$usage, $pricing] = TextCompletionAccounting::measure(
            $raw, $model, $inputTokens, $outputTokens, (array) config('image_prompt.text_pricing'),
        );
        $priced = $pricing['pricing'] === 'estimated';

        return [
            [
                'model' => 'openai',
                'provider_model' => $usage['provider_model'],
                'instruction_version' => (string) config('image_prompt.reference.prompt_version'),
                'tokens_in' => $usage['tokens_in'],
                'tokens_out' => $usage['tokens_out'],
                'thinking_tokens' => is_int($usage['thinking_tokens']) ? $usage['thinking_tokens'] : null,
                'cost_usd' => $usage['cost_usd'],
            ],
            $pricing,
            $pricing + ['estimated_usd' => $priced ? $usage['cost_usd'] : null],
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
