<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\AnchorStage;
use App\Enums\ImageModel;
use App\Enums\ImageSize;
use App\Enums\PlanningStageName;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Video\Concept\Handoff\CompiledAnchorPrompt;
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
                'design_revision' => (int) $design->planning_revision,
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

        $character = $this->designs->vessel($design);
        $source = $this->designs->anchorSource($design);
        $broken = VesselDesign::extractIntegrityViolations((array) $design->output_json, (array) ($source['design'] ?? []));

        if ($broken !== []) {
            Log::error('anchor prompt: the locked design extract is incomplete, no model call made', [
                'project_id' => $projectId,
                'design_stage_id' => $design->id,
                'violations' => $broken,
            ]);

            return [null, 'anchor_design_incomplete', null];
        }

        return $this->write(
            $projectId,
            $characterId,
            $character,
            $this->objectAuthor,
            $source,
            GeometryPromptAuthor::downstreamFor($size),
            [
                'design_stage_id' => (string) $design->id,
                'design_content_hash' => (string) $character['design_content_hash'],
            ],
            $stage,
            $size,
            $model,
            $force,
        );
    }

    /**
     * @param  array<string, mixed>  $character
     * @param  array<string, mixed>  $source
     * @param  array<string, string>|null  $downstream
     * @param  array<string, string>  $origin
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
    ): array {
        $input = [
            'character_id' => $characterId,
        ] + $origin + [
            'source_hash' => hash('sha256', json_encode(
                $source,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            )),
            'skill_hash' => $author->skillHash(),
        ] + ($downstream === null ? [] : ['downstream' => $downstream]);

        [$claimed, $token, $reason] = $this->stageStore->claimProjectStage(
            $projectId,
            PlanningStageName::ANCHOR_PROMPT,
            $input,
            $force,
        );

        if ($reason === 'already_succeeded') {
            $compiled = $author->rehydrate($claimed->output_json ?? [], $stage, $size, $model);

            return $compiled === null
                ? [null, 'anchor_prompt_missing', $character]
                : [$compiled, 'cached', $character];
        }

        if ($token === null) {
            return [null, 'character_prompt_running', $character];
        }

        try {
            $result = $author->author($source, $stage, $size, $model, $downstream);
        } catch (\Throwable $e) {
            $this->stageStore->finishFailed($claimed->id, $token, $e->getMessage());

            Log::error('character-anchor-prompt: author failed', [
                'project_id' => $projectId,
                'character_id' => $characterId,
                'exception' => $e,
            ]);

            return [null, $e->getMessage(), $character];
        }

        $recorded = $this->stageStore->finishSucceeded(
            $claimed->id,
            $token,
            $result->compiled->prompt,
            $result->toStorage() + [
                'character_id' => $characterId,
                'character_name' => (string) ($character['name'] ?? $characterId),
                'character_kind' => (string) ($character['kind'] ?? ''),
            ] + $origin,
            [
                'model' => (string) config('canonical_concept.provider'),
                'provider_model' => $result->authorModel,
                'instruction_version' => $result->compiled->promptVersion,
                'tokens_in' => $result->inputTokens,
                'tokens_out' => $result->outputTokens,
                'cost_usd' => 0,
            ],
        );

        if (! $recorded) {
            Log::warning('character-anchor-prompt: claim lost, paid result not recorded', [
                'project_id' => $projectId,
                'character_id' => $characterId,
                'stage_id' => $claimed->id,
            ]);
        }

        return [$result->compiled, 'ok', $character];
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
