<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoDesignImage;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Video\Prompt\AnchorCoverage;
use App\Video\Prompt\Exceptions\TextCompletionException;
use App\Video\Prompt\Exceptions\TextCompletionRefusalException;
use App\Video\Prompt\OpenAiTextClient;
use App\Video\Prompt\TextCompletionAccounting;
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

    public const PACKET_VERSION = 'reference-packet-v8';

    public const SOURCE_DESIGN = 'vessel_design';

    public const SOURCE_SCREENPLAY = 'screenplay';

    public const MAX_GEOMETRY = 4000;

    public const MAX_CONFLICTS = 12;

    public const MAX_CONFLICT = 400;

    private const SPLIT_MARKER = 'SOURCE MATERIAL:';

    private const ID_PATTERN = '/^[A-Za-z0-9_]+$/';

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

            array_push($violations, ...self::oppositeDirections($geometry, ReferenceView::tryFrom((string) ($packet['request']['view'] ?? ''))));
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

    /** @return list<string> */
    private static function oppositeDirections(string $geometry, ?ReferenceView $view): array
    {
        if ($view === null) {
            return [];
        }

        $frame = $view->frame();
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
            $design['design_thesis'][$key] = $thesis[$key] ?? null;
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
