<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\AnchorStage;
use App\Enums\ImageModel;
use App\Enums\ImageSize;
use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Video\Concept\Handoff\CompiledAnchorPrompt;
use App\Video\Prompt\AnchorDesignPrompt;
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
                'anchor_blockers' => VesselDesign::anchorBlockers((array) $design->output_json),
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
    ): array {
        if (VesselDesign::isDesignFirst(VideoProject::query()->find($projectId))) {
            return $this->authorFromDesign($projectId, $characterId, $stage, $size, $model, $force);
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
    ): array {
        $design = $this->designs->currentStage($projectId);

        if ($design === null) {
            return [null, 'character_prompt_no_design', null];
        }

        if ($characterId !== VesselDesign::VESSEL_ID) {
            return [null, 'character_prompt_unknown_character', null];
        }

        $blockers = VesselDesign::anchorBlockers((array) $design->output_json);

        if ($blockers !== []) {
            return [null, $blockers[0], null];
        }

        $broken = VesselDesign::extractIntegrityViolations((array) $design->output_json, $this->designs->anchorExtract($design));

        if ($broken !== []) {
            Log::error('anchor prompt: the locked design extract is incomplete, no prompt compiled', [
                'project_id' => $projectId,
                'design_stage_id' => $design->id,
                'violations' => $broken,
            ]);

            return [null, 'anchor_design_incomplete', null];
        }

        $character = $this->designs->vessel($design);
        $source = $this->designs->anchorPromptSource($design);
        $prompt = $this->designPrompt->compile($source, (array) $design->output_json, $size);

        if (! $this->designPrompt->fits($prompt)) {
            return [null, 'anchor_prompt_too_long', null];
        }

        $origin = self::designOrigin($design);
        $input = $this->designInput($origin, $source, $size);
        [$claimed, $token, $reason] = $this->stageStore->claimProjectStage(
            $projectId,
            PlanningStageName::ANCHOR_PROMPT,
            $input,
            $force,
            ['anchor_source' => $source, 'downstream' => GeometryPromptAuthor::downstreamFor($size), 'pricing' => 'free'],
        );

        if ($claimed === null) {
            return [null, $reason, $character];
        }

        $stamped = $character + [
            'anchor_prompt_stage_id' => (string) $claimed->id,
            'source_hash' => $input['source_hash'],
            'skill_hash' => $input['skill_hash'],
        ];

        if ($reason === 'already_succeeded') {
            $stored = (string) ($claimed->output_json['prompt'] ?? '');

            return hash_equals(hash('sha256', $stored), hash('sha256', $prompt))
                ? [$this->designPrompt->compiled($prompt, $stage, $size, $model), 'cached', $stamped]
                : [null, 'anchor_prompt_source_changed', $character];
        }

        if ($token === null) {
            return [null, 'character_prompt_running', $character];
        }

        $recorded = $this->stageStore->finishSucceeded(
            $claimed->id,
            $token,
            $prompt,
            [
                'prompt' => $prompt,
                'author_model' => AnchorDesignPrompt::AUTHOR,
                'prompt_version' => $this->designPrompt->version,
                'character_id' => $characterId,
                'character_name' => (string) ($character['name'] ?? $characterId),
                'character_kind' => (string) ($character['kind'] ?? ''),
            ] + $origin,
            ['model' => AnchorDesignPrompt::AUTHOR, 'instruction_version' => $this->designPrompt->version],
            ['pricing' => 'free'],
        );

        if (! $recorded) {
            return [null, 'screenplay_claim_lost', $character];
        }

        return [$this->designPrompt->compiled($prompt, $stage, $size, $model), 'ok', $stamped];
    }

    /**
     * @param  array<string, string>  $origin
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function designInput(array $origin, array $source, ImageSize $size): array
    {
        return [
            'character_id' => VesselDesign::VESSEL_ID,
        ] + $origin + [
            'source_hash' => hash('sha256', json_encode($source, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'skill_hash' => $this->designPrompt->skillHash(),
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

        [$claimed, $token, $reason] = $this->stageStore->claimProjectStage(
            $projectId,
            PlanningStageName::ANCHOR_PROMPT,
            $input,
            $force,
            ['anchor_source' => $source, 'downstream' => $downstream, 'pricing' => 'unpriced'] + $metadata,
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
        [$design, $reason] = $this->designs->stageForSpec($projectId, $stamp);

        if ($design === null) {
            return [null, $reason];
        }

        $promptStageId = $stamp['anchor_prompt_stage_id'] ?? null;
        $prompt = $stamp['prompt'] ?? null;
        $promptSize = ImageSize::tryFrom((string) ($stamp['prompt_size'] ?? ''));

        if (! is_string($promptStageId) || $promptStageId === '' || ! is_string($prompt) || $promptSize === null) {
            return [null, 'anchor_prompt_unstamped'];
        }

        $promptHash = hash('sha256', $prompt);

        if (! hash_equals((string) ($stamp['prompt_sha256'] ?? ''), $promptHash)) {
            return [null, 'anchor_prompt_stale'];
        }

        $source = $this->designs->anchorPromptSource($design);
        $input = $this->designInput(self::designOrigin($design), $source, $promptSize);
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

        $expected = $this->designPrompt->compile($source, (array) $design->output_json, $size);

        if (! $this->designPrompt->fits($expected)) {
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

        if ($promptStage === null || $design === null
            || ($promptStage->output_json['author_model'] ?? null) !== AnchorDesignPrompt::AUTHOR
            || ! hash_equals(hash('sha256', (string) ($promptStage->output_json['prompt'] ?? '')), hash('sha256', $preview['prompt']))) {
            return [];
        }

        $source = $this->designs->anchorPromptSource($design);
        $prompts = [];

        foreach (ImageSize::cases() as $size) {
            $prompt = $this->designPrompt->compile($source, (array) $design->output_json, $size);

            if ($this->designPrompt->fits($prompt)) {
                $prompts[$size->value] = $prompt;
            }
        }

        return $prompts;
    }

    /** @param array<string, mixed> $preview */
    public function isCurrentDesignPrompt(array $preview): bool
    {
        return ($preview['lineage']['prompt_version'] ?? null) === $this->designPrompt->version
            && ($preview['lineage']['subject_state_version'] ?? null) === AnchorDesignPrompt::AUTHOR;
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
