<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\AnchorStage;
use App\Enums\ImageModel;
use App\Enums\ImageSize;
use App\Enums\PlanningStageName;
use App\Services\PromptLibrary\PromptSource;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Video\Concept\Contracts\StructuredOutputLlmClient;
use App\Video\Concept\Exceptions\AnthropicRefusalException;
use App\Video\Concept\Exceptions\AnthropicTruncatedOutputException;
use App\Video\Concept\Handoff\CompiledAnchorPrompt;
use App\Video\Prompt\AnchorDesignPrompt;
use App\Video\Reference\ReferenceView;
use App\Video\Prompt\GeometryPromptAuthor;
use App\Video\Screenplay\ProtagonistProfile;
use App\Video\Screenplay\VesselDesign;
use Illuminate\Support\Facades\Log;

final class CharacterAnchorPromptService
{
    /** @var list<string> */
    private const FOUNDATION_CONTEXT = ['logline', 'design_thesis', 'principal_dimensions'];

    public const PRODUCTION_SOURCE = 'production_character';

    /** @var list<string> */
    private const PARTICIPANT_FIELDS = ['id', 'name', 'role', 'kind', 'description', 'personality', 'appearance'];

    /** @var list<string> */
    private const EXTERIOR_PROFILE_SECTIONS = ['form_and_proportions', 'deck_organization', 'windows_and_glazing'];

    /** @var list<string> */
    private const EXTERIOR_FEATURE_FIELDS = ['name', 'region', 'location', 'standard_state', 'standard_geometry'];

    public const GEOMETRY_WRITER = 'anchor-geometry';

    private const GEOMETRY_MARKER = 'PLANNING INPUT:';

    public const READY = 'ready';

    public const NEEDS_REVIEW = 'needs_review';

    public const USED = 'used';

    public const MIXED = 'mixed';

    public const EXCLUDED = 'excluded';

    public const UNRESOLVED = 'unresolved';

    /** @var list<string> */
    public const DECISIONS = [self::USED, self::MIXED, self::EXCLUDED, self::UNRESOLVED];

    /** @var list<string> */
    public const FINDING_KINDS = ['note', 'missing', 'conflict', 'policy_conflict'];

    /** @var list<string> */
    public const BLOCKING_FINDINGS = ['missing', 'conflict', 'policy_conflict'];

    private const MAX_APPEARANCES = 8;

    private const ACTION_CHARS = 400;

    public function __construct(
        private readonly PlanningStageStore $stageStore,
        private readonly GeometryPromptAuthor $objectAuthor,
        private readonly GeometryPromptAuthor $peopleAuthor,
        private readonly ProductionSelectionService $selection,
        private readonly ScreenplaySubjectService $subjects,
        private readonly VesselDesignService $designs,
        private readonly AnchorDesignPrompt $designPrompt,
        private ?StructuredOutputLlmClient $geometryClient = null,
    ) {}

    public static function anchorModel(): ImageModel
    {
        return ImageModel::tryFrom((string) config('image_prompt.anchor_model')) ?? ImageModel::GPT_IMAGE_2_5_FLARE;
    }

    public function screenplayStage(string $projectId): ?VideoPlanningStage
    {
        $project = VideoProject::query()->find($projectId);

        return $project === null ? null : $this->selection->selectedScreenplay($project);
    }

    /**
     * @return list<array{id: string, name: string, kind: string, role: string, appearance: string, profile: ?array<string, mixed>}>
     */
    public function characters(string $projectId): array
    {
        if (VesselDesign::isDesignFirst(VideoProject::query()->find($projectId))) {
            $design = $this->designs->currentStage($projectId);

            if ($design === null) {
                return [];
            }

            $vessel = $this->designs->vessel($design);
            $blockers = self::geometryBlockers((array) $design->output_json);

            return [[
                'id' => VesselDesign::VESSEL_ID,
                'name' => $vessel['name'] !== '' ? $vessel['name'] : VesselDesign::VESSEL_ID,
                'kind' => 'object',
                'role' => 'protagonist',
                'appearance' => $vessel['appearance'],
                'profile' => is_array($vessel[ProtagonistProfile::CHARACTER_KEY] ?? null) ? $vessel[ProtagonistProfile::CHARACTER_KEY] : null,
                'design_stage_id' => $vessel['design_stage_id'],
                'design_content_hash' => $vessel['design_content_hash'],
                'design_revision' => (int) $design->planning_revision,
                'anchor_blockers' => $blockers,
                'anchor_unclassified' => [],
            ]];
        }

        $stage = $this->screenplayStage($projectId);
        $characters = [];

        foreach ($stage === null ? [] : $this->subjects->mainCharacters($stage) as $row) {
            if ($row['id'] === '') {
                continue;
            }

            $characters[] = [
                'id' => $row['id'],
                'name' => (string) ($row['name'] ?? $row['id']),
                'kind' => (string) ($row['kind'] ?? ''),
                'role' => (string) ($row['role'] ?? ''),
                'appearance' => (string) ($row['appearance'] ?? ''),
                'profile' => is_array($row[ProtagonistProfile::CHARACTER_KEY] ?? null) ? $row[ProtagonistProfile::CHARACTER_KEY] : null,
            ];
        }

        return $characters;
    }

    /**
     * @return array{0: ?CompiledAnchorPrompt, 1: string, 2: ?array<string, mixed>}
     */
    public function author(
        string $projectId,
        string $characterId,
        AnchorStage $stage,
        ImageSize $size,
        ImageModel $model,
        bool $force = false,
        bool $call = true,
    ): array {
        if (VesselDesign::isDesignFirst(VideoProject::query()->find($projectId))) {
            return $this->authorFromDesign($projectId, $characterId, $stage, $size, $model, $force, $call);
        }

        $screenplay = $this->screenplayStage($projectId);

        if ($screenplay === null || ! is_array($screenplay->output_json)) {
            return [null, 'character_prompt_no_screenplay', null];
        }

        $character = collect($screenplay->output_json['characters'] ?? [])
            ->first(static fn (mixed $row): bool => is_array($row) && ($row['id'] ?? null) === $characterId);

        if (! is_array($character)) {
            return [null, 'character_prompt_unknown_character', null];
        }

        if (! ScreenplaySubjectService::isMain($character)) {
            return [null, 'character_not_main', $character];
        }

        if (VesselDesign::isV3Profile($character[ProtagonistProfile::CHARACTER_KEY] ?? null)) {
            return [null, 'character_prompt_v3_profile_uses_design', $character];
        }

        $character['screenplay_stage_id'] = (string) $screenplay->id;

        $isObject = ($character['kind'] ?? null) === 'object';
        $author = $isObject ? $this->objectAuthor : $this->peopleAuthor;
        $source = $isObject
            ? $this->objectSource($screenplay->output_json, $character)
            : $this->peopleSource($screenplay->output_json, $character);
        $downstream = $isObject ? GeometryPromptAuthor::downstreamFor($size) : null;

        return $this->write(
            $projectId, $characterId, $character, $author, $source, $downstream,
            ['screenplay_stage_id' => (string) $screenplay->id], $stage, $size, $model, $force,
        );
    }

    /**
     * @return array{0: ?CompiledAnchorPrompt, 1: string, 2: ?array<string, mixed>}
     */
    private function authorFromDesign(
        string $projectId,
        string $characterId,
        AnchorStage $stage,
        ImageSize $size,
        ImageModel $model,
        bool $force,
        bool $call,
    ): array {
        $design = $this->designs->currentStage($projectId);

        if ($design === null) {
            return [null, 'character_prompt_no_design', null];
        }

        if ($characterId !== VesselDesign::VESSEL_ID) {
            return [null, 'character_prompt_unknown_character', null];
        }

        $output = (array) $design->output_json;
        $blockers = self::geometryBlockers($output);

        if ($blockers !== []) {
            return [null, $blockers[0], null];
        }

        $character = $this->designs->vessel($design);
        $input = $this->geometryInput($design);
        $existing = $this->geometryStage($projectId, $input);

        if ($existing !== null && ! $force) {
            return $this->geometryResult($existing, $output, $character, $stage, $size, $model, 'cached');
        }

        if (! $call) {
            return [null, 'anchor_prompt_not_written', $character];
        }

        // Skill anchor geometry + template dung de tinh input o tren: pin ca hai vao stage.
        [$promptMeta, $pinPrompt] = app(PromptSource::class)->claimFor(['image.anchor_geometry', 'image.design_anchor']);

        [$claimed, $token, $reason] = $this->stageStore->claimProjectStage(
            $projectId, PlanningStageName::ANCHOR_PROMPT, $input, $force, ['pricing' => 'unpriced'] + $promptMeta, $pinPrompt,
        );

        if ($claimed === null) {
            return [null, $reason, $character];
        }

        if ($reason === 'already_succeeded') {
            return $this->geometryResult($claimed, $output, $character, $stage, $size, $model, 'cached');
        }

        if ($token === null) {
            return [null, 'character_prompt_running', $character];
        }

        $units = AnchorDesignPrompt::sourceUnits($output);
        $designPrompt = $this->designPrompt->forDesign($output);
        $policy = $designPrompt->policy($output, ImageSize::LANDSCAPE);
        $unpriced = ['pricing' => 'unpriced', 'pricing_version' => null];

        try {
            $response = $this->geometryClient()->create(
                model: (string) config('image_prompt.anchor_geometry.model'),
                system: $this->geometrySkill(),
                messages: [['role' => 'user', 'content' => self::GEOMETRY_MARKER."\n\n".json_encode([
                    'camera' => $policy['camera'],
                    'anchor_policy' => $policy['state'],
                    'source_units' => $units,
                    'design' => array_diff_key($output, ['author_model' => true]),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]],
                outputSchema: self::geometrySchema(),
                maxTokens: (int) config('image_prompt.anchor_geometry.max_tokens'),
            );
        } catch (\Throwable $e) {
            $failed = $e instanceof AnthropicRefusalException || $e instanceof AnthropicTruncatedOutputException ? $e->response : null;
            $this->stageStore->finishFailed((string) $claimed->id, $token, $e->getMessage(),
                $failed === null ? [] : self::geometryUsage($failed->model, $failed->inputTokens, $failed->outputTokens), (string) ($failed?->rawText ?? ''), [], $unpriced);
            Log::error('anchor geometry: author call failed', ['project_id' => $projectId, 'stage_id' => $claimed->id, 'exception' => $e]);

            return [null, 'anchor_geometry_call_failed', $character];
        }

        $usage = self::geometryUsage($response->model, $response->inputTokens, $response->outputTokens);
        $decoded = json_decode($response->rawText, true);
        $violations = is_array($decoded) ? self::geometryViolations($decoded, $output, $units) : ['the answer is not a JSON object'];

        if ($violations === [] && ! $designPrompt->fits($designPrompt->compileProse($output, (string) $decoded['geometry_prose'], $size))) {
            $violations[] = 'the anchor prompt with this geometry prose is longer than the image model accepts';
        }

        $current = $this->designs->currentStage($projectId);
        $changed = $current === null || (string) $current->id !== (string) $design->id
            || ! hash_equals($input['design_content_hash'], VesselDesign::contentHash((array) $current->output_json));

        if ($violations === [] && $changed) {
            $violations[] = 'the design changed during the call; the answer is kept and not applied';
        }

        $result = [
            'author_model' => $response->model,
            'usage' => $usage,
            'character_id' => $characterId,
            'character_name' => (string) ($character['name'] ?? $characterId),
            'character_kind' => (string) ($character['kind'] ?? ''),
        ] + self::designOrigin($design);

        if ($violations !== []) {
            $this->stageStore->finishFailed((string) $claimed->id, $token, 'Anchor geometry failed validation: '.implode('; ', $violations),
                $usage, $response->rawText, $result + ['violations' => $violations], $unpriced);

            return [null, $changed ? 'anchor_geometry_source_changed' : 'anchor_geometry_invalid', $character];
        }

        $recorded = $this->stageStore->finishSucceeded((string) $claimed->id, $token, $response->rawText, $result + [
            'prompt' => $designPrompt->compileProse($output, (string) $decoded['geometry_prose'], ImageSize::LANDSCAPE),
            'prompt_version' => $designPrompt->geometryVersion(),
            'geometry_prose' => trim((string) $decoded['geometry_prose']),
            'status' => $decoded['status'],
            'coverage' => array_values($decoded['coverage']),
            'findings' => array_values($decoded['findings']),
        ], $usage, $unpriced);

        if (! $recorded) {
            return [null, 'screenplay_claim_lost', $character];
        }

        return $this->geometryResult($claimed->fresh(), $output, $character, $stage, $size, $model, 'ok');
    }

    /**
     * @param  array<string, mixed>  $output
     * @return list<string> why the design cannot be written into an anchor prompt at all; the geometry writer reports every finer gap itself
     */
    public static function geometryBlockers(array $output): array
    {
        return array_keys(array_filter([
            'anchor_design_unresolved' => VesselDesign::unresolvedDecisions($output) !== [],
            'anchor_design_not_canonical' => ! VesselDesign::hasCanonical($output),
        ]));
    }

    /**
     * @param  array<string, mixed>  $character
     * @return array{0: ?CompiledAnchorPrompt, 1: string, 2: ?array<string, mixed>}
     */
    private function geometryResult(VideoPlanningStage $promptStage, array $design, array $character, AnchorStage $stage, ImageSize $size, ImageModel $model, string $reason): array
    {
        $designPrompt = $this->designPrompt->forDesign($design);
        $prompt = $designPrompt->compileProse($design, (string) ($promptStage->output_json['geometry_prose'] ?? ''), $size);

        if (! $designPrompt->fits($prompt)) {
            return [null, 'anchor_prompt_too_long', $character];
        }

        return [$designPrompt->compiled($prompt, $stage, $size, $model, (string) $promptStage->output_json['author_model']), $reason, $character + [
            'anchor_prompt_stage_id' => (string) $promptStage->id,
            'source_hash' => (string) $promptStage->input_json['units_hash'],
            'skill_hash' => (string) $promptStage->input_json['skill_hash'],
            'geometry_status' => (string) ($promptStage->output_json['status'] ?? ''),
        ]];
    }

    /**
     * @return array<string, mixed> what a geometry prompt is written from: the design revision, the writer's instructions and the fixed frame
     */
    private function geometryInput(VideoPlanningStage $design): array
    {
        $output = (array) $design->output_json;
        $designPrompt = $this->designPrompt->forDesign($output);

        return [
            'character_id' => VesselDesign::VESSEL_ID,
            'writer' => self::GEOMETRY_WRITER,
            'design_stage_id' => (string) $design->id,
            'design_revision' => (int) $design->planning_revision,
            'design_content_hash' => VesselDesign::contentHash($output),
            'prompt_version' => (string) config('image_prompt.anchor_geometry.prompt_version'),
            'skill_hash' => hash('sha256', $this->geometrySkillText()),
            'template_version' => (string) $designPrompt->geometryVersion(),
            'template_hash' => $designPrompt->geometryTemplateHash(),
            'units_hash' => hash('sha256', json_encode(AnchorDesignPrompt::sourceUnits($output), JSON_THROW_ON_ERROR)),
            'model' => (string) config('image_prompt.anchor_geometry.model'),
            'effort' => (string) config('image_prompt.anchor_geometry.effort'),
        ];
    }

    /** @param array<string, mixed> $input */
    private function geometryStage(string $projectId, array $input): ?VideoPlanningStage
    {
        return VideoPlanningStage::query()
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::ANCHOR_PROMPT->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->where('input_json->writer', self::GEOMETRY_WRITER)
            ->where('input_json->design_stage_id', $input['design_stage_id'])
            ->orderByDesc('planning_revision')
            ->get()
            ->first(static fn (VideoPlanningStage $row): bool => array_intersect_key((array) $row->input_json, $input) == $input);
    }

    /** @return array<string, mixed> */
    public static function geometrySchema(): array
    {
        $text = ['type' => 'string'];
        $texts = ['type' => 'array', 'items' => $text];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['status', 'geometry_prose', 'coverage', 'findings'],
            'properties' => [
                'status' => ['type' => 'string', 'enum' => [self::READY, self::NEEDS_REVIEW]],
                'geometry_prose' => $text,
                'coverage' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['path', 'decision', 'reason'],
                    'properties' => ['path' => $text, 'decision' => ['type' => 'string', 'enum' => self::DECISIONS], 'reason' => $text],
                ]],
                'findings' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['kind', 'text', 'paths'],
                    'properties' => ['kind' => ['type' => 'string', 'enum' => self::FINDING_KINDS], 'text' => $text, 'paths' => $texts],
                ]],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  array<string, mixed>  $design
     * @param  list<string>  $units
     * @return list<string> what code can see wrong in the answer; an empty list proves the accounting, not that the prose means the design
     */
    public static function geometryViolations(array $decoded, array $design, array $units): array
    {
        $prose = is_string($decoded['geometry_prose'] ?? null) ? trim($decoded['geometry_prose']) : '';
        $coverage = $decoded['coverage'] ?? null;
        $findings = $decoded['findings'] ?? null;

        if ($prose === '' || ! in_array($decoded['status'] ?? null, [self::READY, self::NEEDS_REVIEW], true)
            || ! is_array($coverage) || ! array_is_list($coverage) || ! is_array($findings) || ! array_is_list($findings)) {
            return ['status, geometry_prose, coverage and findings must take their listed shapes'];
        }

        $known = array_flip($units);
        $decisions = [];
        $violations = [];

        foreach ($coverage as $index => $entry) {
            $path = is_array($entry) && is_string($entry['path'] ?? null) ? $entry['path'] : '';
            $decision = is_array($entry) ? ($entry['decision'] ?? null) : null;
            $reasoned = is_array($entry) && is_string($entry['reason'] ?? null) && trim($entry['reason']) !== '';
            $at = 'coverage['.$index.'] '.$path;

            if (! isset($known[$path])) {
                $violations[] = $at.': is not a unit of the design';

                continue;
            }

            if (isset($decisions[$path])) {
                $violations[] = $at.': is accounted twice';

                continue;
            }

            $decisions[$path] = $decision;
            $wrong = match ($decision) {
                self::USED => null,
                self::MIXED, self::EXCLUDED, self::UNRESOLVED => $reasoned ? null : $decision.' gives its reason',
                default => 'decision must be used, mixed, excluded or unresolved',
            };

            if ($wrong !== null) {
                $violations[] = $at.': '.$wrong;
            }
        }

        foreach (array_diff($units, array_keys($decisions)) as $missing) {
            $violations[] = 'source unit '.$missing.' has no coverage entry';
        }

        $cited = array_values(array_map(
            static fn (string $path): mixed => ReferencePromptWriter::designPath($design, $path),
            array_keys(array_filter($decisions, static fn (mixed $decision): bool => in_array($decision, [self::USED, self::MIXED], true))),
        ));

        $blocking = in_array(self::UNRESOLVED, $decisions, true);

        foreach ($findings as $index => $finding) {
            $kind = is_array($finding) ? ($finding['kind'] ?? null) : null;

            if (! in_array($kind, self::FINDING_KINDS, true) || ! is_string($finding['text'] ?? null) || trim($finding['text']) === ''
                || ! is_array($finding['paths'] ?? null) || array_filter($finding['paths'], static fn (mixed $path): bool => ! is_string($path)
                    || ReferencePromptWriter::designPath($design, $path) === null) !== []) {
                $violations[] = 'findings['.$index.']: needs a listed kind, a text and design paths that exist';

                continue;
            }

            $blocking = $blocking || in_array($kind, self::BLOCKING_FINDINGS, true);
        }

        if ($blocking && $decoded['status'] === self::READY) {
            $violations[] = 'status is ready although a unit is unresolved or a finding is missing, conflict or policy_conflict';
        }

        if (! $blocking && $decoded['status'] === self::NEEDS_REVIEW) {
            $violations[] = 'status is needs_review without an unresolved unit or a blocking finding';
        }

        foreach (ReferencePromptWriter::proseViolations($prose, ReferenceView::DESIGN_ANCHOR_FRAME, $design, $cited) as $line) {
            $violations[] = 'geometry_prose: '.str_replace('the description', 'the prose', $line);
        }

        return array_values(array_unique($violations));
    }

    /** @return array<string, mixed> */
    private static function geometryUsage(string $model, int $inputTokens, int $outputTokens): array
    {
        return [
            'model' => 'anthropic',
            'provider_model' => $model,
            'instruction_version' => (string) config('image_prompt.anchor_geometry.prompt_version'),
            'tokens_in' => $inputTokens,
            'tokens_out' => $outputTokens,
            'thinking_tokens' => null,
            'cost_usd' => 0.0,
        ];
    }

    private function geometryClient(): StructuredOutputLlmClient
    {
        return $this->geometryClient ??= app('video.anchor_geometry.client');
    }

    /**
     * Toan van file skill anchor geometry. Che do database doc tu thu vien (mot
     * lan moi request/job, cung ban voi skill_hash luc claim); che do file giu
     * nguyen cach doc cu, ke ca tra chuoi rong khi thieu file.
     */
    private function geometrySkillText(): string
    {
        $prompt = app(PromptSource::class)->resolve('image.anchor_geometry');

        return $prompt !== null
            ? $prompt->content
            : (string) @file_get_contents((string) config('image_prompt.anchor_geometry.prompt_path'));
    }

    private function geometrySkill(): string
    {
        $skill = $this->geometrySkillText();
        $position = mb_strpos($skill, self::GEOMETRY_MARKER);

        if (trim($skill) === '') {
            throw new \RuntimeException('Anchor geometry skill not found.');
        }

        return trim($position === false ? $skill : mb_substr($skill, 0, $position));
    }

    /**
     * @param  array<string, string>  $origin
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function designInput(array $origin, array $source, ImageSize $size, AnchorDesignPrompt $designPrompt): array
    {
        return [
            'character_id' => VesselDesign::VESSEL_ID,
        ] + $origin + [
            'source_hash' => hash('sha256', json_encode($source, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'skill_hash' => $designPrompt->skillHash(),
            'downstream' => GeometryPromptAuthor::downstreamFor($size),
        ];
    }

    /** @return array<string, string> */
    private static function designOrigin(VideoPlanningStage $design): array
    {
        $scope = VesselDesign::anchorScope((array) $design->output_json);

        return [
            'design_stage_id' => (string) $design->id,
            'design_content_hash' => VesselDesign::contentHash((array) $design->output_json),
            'anchor_scope_version' => $scope['version'],
            'anchor_scope_hash' => hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];
    }

    /**
     * @param  array<string, mixed>  $character
     * @param  array<string, mixed>  $source
     * @param  array<string, string>|null  $downstream
     * @param  array<string, string>  $origin
     * @param  array<string, mixed>  $metadata
     * @return array{0: ?CompiledAnchorPrompt, 1: string, 2: ?array<string, mixed>}
     */
    private function write(
        string $projectId,
        string $characterId,
        array $character,
        GeometryPromptAuthor $author,
        array $source,
        ?array $downstream,
        array $origin,
        AnchorStage $stage,
        ImageSize $size,
        ImageModel $model,
        bool $force,
        array $metadata = [],
    ): array {
        $input = self::promptInput($characterId, $origin, $source, $author, $downstream);
        [$promptMeta, $pinPrompt] = $author->promptConsumer() === null
            ? [[], null]
            : app(PromptSource::class)->claimFor([$author->promptConsumer()]);

        [$claimed, $token, $reason] = $this->stageStore->claimProjectStage(
            $projectId,
            PlanningStageName::ANCHOR_PROMPT,
            $input,
            $force,
            ['anchor_source' => $source, 'downstream' => $downstream, 'pricing' => 'unpriced'] + $metadata + $promptMeta,
            $pinPrompt,
        );

        $stamped = $claimed === null ? $character : $character + [
            'anchor_prompt_stage_id' => (string) $claimed->id,
            'source_hash' => $input['source_hash'],
            'skill_hash' => $input['skill_hash'],
        ];

        if ($reason === 'already_succeeded') {
            if (isset($source['design']['canonical_design']) && \App\Video\Prompt\AnchorCoverage::violations($claimed->output_json ?? [], $source) !== []) {
                return [null, 'anchor_prompt_coverage_failed', $character];
            }
            $compiled = $author->rehydrate($claimed->output_json ?? [], $stage, $size, $model);

            return $compiled === null
                ? [null, 'anchor_prompt_missing', $character]
                : [$compiled, 'cached', $stamped];
        }

        if ($token === null) {
            return [null, 'character_prompt_running', $character];
        }

        try {
            $result = $author->author($source, $stage, $size, $model, $downstream);
        } catch (\Throwable $e) {
            $usage = [];
            $raw = '';
            $pricing = ['pricing' => 'unpriced', 'pricing_version' => null];
            if ($e instanceof \App\Video\Prompt\Exceptions\TextCompletionException) {
                $raw = $e->raw;
                [$usage, $pricing] = \App\Video\Prompt\TextCompletionAccounting::measure(
                    $e->usage, (string) $e->model,
                    (int) ($e->usage['prompt_tokens'] ?? $e->usage['input_tokens'] ?? 0),
                    (int) ($e->usage['completion_tokens'] ?? $e->usage['output_tokens'] ?? 0),
                    (array) config('image_prompt.text_pricing'),
                );
            }
            $this->stageStore->finishFailed($claimed->id, $token, $e->getMessage(), $usage, $raw,
                $e instanceof \App\Video\Prompt\Exceptions\TextCompletionException ? ['usage' => $e->usage] : [], $pricing);

            Log::error('character-anchor-prompt: author failed', [
                'project_id' => $projectId,
                'character_id' => $characterId,
                'exception' => $e,
            ]);

            return [null, $e->getMessage(), $character];
        }

        [$usage, $pricing] = \App\Video\Prompt\TextCompletionAccounting::measure(
            $result->usage, $result->authorModel, $result->inputTokens, $result->outputTokens,
            (array) config('image_prompt.text_pricing'),
        );
        $usage['instruction_version'] = $result->compiled->promptVersion;

        if (isset($source['design']['canonical_design'])) {
            $errors = \App\Video\Prompt\AnchorCoverage::violations($result->toStorage(), $source);
            if ($errors !== []) {
                $this->stageStore->finishFailed($claimed->id, $token, implode('; ', $errors), $usage,
                    $result->rawResponse ?? $result->compiled->prompt, $result->toStorage(), $pricing);
                return [null, 'anchor_prompt_coverage_failed: '.implode('; ', $errors), $character];
            }
        }

        $recorded = $this->stageStore->finishSucceeded(
            $claimed->id,
            $token,
            $result->rawResponse ?? $result->compiled->prompt,
            $result->toStorage() + [
                'character_id' => $characterId,
                'character_name' => (string) ($character['name'] ?? $characterId),
                'character_kind' => (string) ($character['kind'] ?? ''),
            ] + $origin,
            $usage,
            $pricing,
        );

        if (! $recorded) {
            Log::warning('character-anchor-prompt: claim lost, paid result not recorded', [
                'project_id' => $projectId,
                'character_id' => $characterId,
                'stage_id' => $claimed->id,
            ]);
            return [null, 'screenplay_claim_lost', $character];
        }

        return [$result->compiled, 'ok', $stamped];
    }

    /**
     * @param  array<string, mixed>  $stamp
     * @return array{0: ?VideoPlanningStage, 1: string}
     */
    public function designPromptCheck(string $projectId, array $stamp, ImageSize $size): array
    {
        $promptStageId = $stamp['anchor_prompt_stage_id'] ?? null;
        $written = is_string($promptStageId) && $promptStageId !== '' ? $this->succeededPromptStage($projectId, $promptStageId) : null;
        $geometry = ($written?->input_json['writer'] ?? null) === self::GEOMETRY_WRITER;
        [$design, $reason] = $this->designs->stageForSpec($projectId, $stamp, ! $geometry);

        if ($design === null) {
            return [null, $reason];
        }

        $prompt = $stamp['prompt'] ?? null;
        $promptSize = ImageSize::tryFrom((string) ($stamp['prompt_size'] ?? ''));

        if (! is_string($promptStageId) || $promptStageId === '' || ! is_string($prompt) || $promptSize === null) {
            return [null, 'anchor_prompt_unstamped'];
        }

        $promptHash = hash('sha256', $prompt);

        if (! hash_equals((string) ($stamp['prompt_sha256'] ?? ''), $promptHash)) {
            return [null, 'anchor_prompt_stale'];
        }

        if ($geometry) {
            $input = $this->geometryInput($design);

            if (array_intersect_key((array) $written->input_json, $input) != $input) {
                return [null, 'anchor_prompt_source_changed'];
            }

            if (($written->output_json['status'] ?? null) !== self::READY) {
                return [null, 'anchor_prompt_needs_review'];
            }

            $designPrompt = $this->designPrompt->forDesign((array) $design->output_json);
            $expected = $designPrompt->compileProse((array) $design->output_json, (string) ($written->output_json['geometry_prose'] ?? ''), $size);

            if (! $designPrompt->fits($expected)) {
                return [null, 'anchor_prompt_size_unsupported'];
            }

            return hash_equals($promptHash, hash('sha256', $expected)) ? [$design, 'ok'] : [null, 'anchor_prompt_source_changed'];
        }

        $source = $this->designs->anchorPromptSource($design);
        $designPrompt = $this->designPrompt->forDesign((array) $design->output_json);
        $input = $this->designInput(self::designOrigin($design), $source, $promptSize, $designPrompt);
        $promptStage = $this->succeededPromptStage($projectId, $promptStageId);
        $stored = is_array($promptStage?->input_json) ? $promptStage->input_json : [];

        if ($promptStage === null
            || ! $this->stageStore->hasSucceededForProject($projectId, PlanningStageName::ANCHOR_PROMPT, $input)
            || ($stored['source_hash'] ?? null) !== $input['source_hash']
            || ($stored['skill_hash'] ?? null) !== $input['skill_hash']
            || ($stored['design_content_hash'] ?? null) !== $input['design_content_hash']
            || ($stored['downstream'] ?? null) != $input['downstream']) {
            return [null, 'anchor_prompt_source_changed'];
        }

        $expected = $designPrompt->compile($source, (array) $design->output_json, $size);

        if (! $designPrompt->fits($expected)) {
            return [null, 'anchor_prompt_size_unsupported'];
        }

        if (! hash_equals($promptHash, hash('sha256', $expected))) {
            return [null, 'anchor_prompt_source_changed'];
        }

        return [$design, 'ok'];
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return array<string, string>
     */
    public function previewPrompts(string $projectId, array $preview): array
    {
        $written = ImageSize::tryFrom((string) ($preview['size'] ?? ''));
        $promptStageId = $preview['anchor_prompt_stage_id'] ?? null;
        $designStageId = $preview['design_stage_id'] ?? null;

        if ($written === null || ! is_string($preview['prompt'] ?? null) || ! is_string($promptStageId) || ! is_string($designStageId)) {
            return [];
        }

        $promptStage = $this->succeededPromptStage($projectId, $promptStageId);
        $design = VideoPlanningStage::query()
            ->whereKey($designStageId)
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::VESSEL_DESIGN->value)
            ->first();

        if ($promptStage !== null && $design !== null && ($promptStage->input_json['writer'] ?? null) === self::GEOMETRY_WRITER) {
            $output = (array) $design->output_json;
            $designPrompt = $this->designPrompt->forDesign($output);
            $prose = (string) ($promptStage->output_json['geometry_prose'] ?? '');

            if (! hash_equals(hash('sha256', $designPrompt->compileProse($output, $prose, $written)), hash('sha256', $preview['prompt']))) {
                return [];
            }

            $prompts = [];

            foreach (ImageSize::cases() as $size) {
                $prompt = $designPrompt->compileProse($output, $prose, $size);

                if ($designPrompt->fits($prompt)) {
                    $prompts[$size->value] = $prompt;
                }
            }

            return $prompts;
        }

        if ($promptStage === null || $design === null
            || ($promptStage->output_json['author_model'] ?? null) !== AnchorDesignPrompt::AUTHOR
            || ! hash_equals(hash('sha256', (string) ($promptStage->output_json['prompt'] ?? '')), hash('sha256', $preview['prompt']))) {
            return [];
        }

        $source = $this->designs->anchorPromptSource($design);
        $designPrompt = $this->designPrompt->forDesign((array) $design->output_json);
        $prompts = [];

        foreach (ImageSize::cases() as $size) {
            $prompt = $designPrompt->compile($source, (array) $design->output_json, $size);

            if ($designPrompt->fits($prompt)) {
                $prompts[$size->value] = $prompt;
            }
        }

        return $prompts;
    }

    /** @param array<string, mixed> $preview */
    public function isCurrentDesignPrompt(array $preview): bool
    {
        return ($preview['lineage']['prompt_version'] ?? null) === $this->designPrompt->geometryVersion()
            && ($preview['lineage']['subject_state_version'] ?? null) !== AnchorDesignPrompt::AUTHOR;
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return array{usage: mixed, blocks: list<string>}|null the call's usage and why the geometry prompt in use cannot be rendered (each blocking finding and unresolved unit); null when it is not a geometry prompt
     */
    public function geometryReview(string $projectId, array $preview): ?array
    {
        $promptStageId = $preview['anchor_prompt_stage_id'] ?? null;
        $stage = is_string($promptStageId) ? $this->succeededPromptStage($projectId, $promptStageId) : null;

        if ($stage === null || ($stage->input_json['writer'] ?? null) !== self::GEOMETRY_WRITER) {
            return null;
        }

        $output = (array) $stage->output_json;

        return ['usage' => $output['usage'] ?? null, 'blocks' => ($output['status'] ?? null) !== self::NEEDS_REVIEW ? [] : [
            ...array_map(static fn (array $finding): string => $finding['kind'].': '.$finding['text'],
                array_values(array_filter((array) ($output['findings'] ?? []), static fn (array $finding): bool => in_array($finding['kind'] ?? null, self::BLOCKING_FINDINGS, true)))),
            ...array_map(static fn (array $entry): string => self::UNRESOLVED.' '.$entry['path'].': '.$entry['reason'],
                array_values(array_filter((array) ($output['coverage'] ?? []), static fn (array $entry): bool => ($entry['decision'] ?? null) === self::UNRESOLVED))),
        ]];
    }

    /** @param array<string, mixed> $preview */
    public function designSourceChanged(string $projectId, array $preview): bool
    {
        $size = ImageSize::tryFrom((string) ($preview['size'] ?? ''));

        return $size !== null
            && $this->designPromptCheck($projectId, ['prompt_size' => $size->value] + $preview, $size)[1] === 'anchor_prompt_source_changed';
    }

    private function succeededPromptStage(string $projectId, string $promptStageId): ?VideoPlanningStage
    {
        return VideoPlanningStage::query()
            ->whereKey($promptStageId)
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::ANCHOR_PROMPT->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->first();
    }

    /**
     * @param  array<string, string>  $origin
     * @param  array<string, mixed>  $source
     * @param  array<string, string>|null  $downstream
     * @return array<string, mixed>
     */
    private static function promptInput(
        string $characterId,
        array $origin,
        array $source,
        GeometryPromptAuthor $author,
        ?array $downstream,
    ): array {
        return [
            'character_id' => $characterId,
        ] + $origin + [
            'source_hash' => hash('sha256', json_encode(
                $source,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            )),
            'skill_hash' => $author->skillHash(),
        ] + ($downstream === null ? [] : ['downstream' => $downstream]);
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @param  array<string, mixed>  $character
     * @return array<string, mixed>
     */
    private function objectSource(array $screenplay, array $character): array
    {
        $context = [];

        foreach (self::FOUNDATION_CONTEXT as $key) {
            $context[$key] = $screenplay[$key] ?? null;
        }

        $participant = array_intersect_key($character, array_flip(self::PARTICIPANT_FIELDS));
        $profile = $character[ProtagonistProfile::CHARACTER_KEY] ?? null;

        return [
            'source_kind' => self::PRODUCTION_SOURCE,
            'participant' => is_array($profile)
                ? $participant + [ProtagonistProfile::CHARACTER_KEY => $this->exteriorProfile($profile)]
                : $participant,
            'design' => $context,
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function exteriorProfile(array $profile): array
    {
        $exterior = array_intersect_key($profile, array_flip(self::EXTERIOR_PROFILE_SECTIONS));
        $exterior['signature_features'] = array_values(array_map(
            static fn (mixed $feature): array => array_intersect_key(
                is_array($feature) ? $feature : [],
                array_flip(self::EXTERIOR_FEATURE_FIELDS),
            ),
            (array) ($profile['signature_features'] ?? []),
        ));

        return $exterior;
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @param  array<string, mixed>  $character
     * @return array<string, mixed>
     */
    private function peopleSource(array $screenplay, array $character): array
    {
        $id = (string) ($character['id'] ?? '');
        $appearances = [];

        foreach ($screenplay['scenes'] ?? [] as $scene) {
            if (! is_array($scene) || ! in_array($id, (array) ($scene['character_ids'] ?? []), true)) {
                continue;
            }

            $appearances[] = [
                'stage' => (string) ($scene['stage'] ?? ''),
                'action' => mb_substr((string) ($scene['action'] ?? ''), 0, self::ACTION_CHARS),
            ];

            if (count($appearances) >= self::MAX_APPEARANCES) {
                break;
            }
        }

        return [
            'participant' => $this->participant($character),
            'film' => ['logline' => $screenplay['logline'] ?? null],
            'appearances' => $appearances,
        ];
    }

    /**
     * @param  array<string, mixed>  $character
     * @return array<string, mixed>
     */
    private function participant(array $character): array
    {
        $participant = array_intersect_key($character, array_flip(self::PARTICIPANT_FIELDS));

        return is_array($character[ProtagonistProfile::CHARACTER_KEY] ?? null)
            ? $participant + [ProtagonistProfile::CHARACTER_KEY => ProtagonistProfile::forImage($character[ProtagonistProfile::CHARACTER_KEY])]
            : $participant;
    }
}
