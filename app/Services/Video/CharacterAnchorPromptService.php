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
use Illuminate\Support\Facades\Log;

final class CharacterAnchorPromptService
{
    /** @var list<string> */
    private const FOUNDATION_CONTEXT = ['logline', 'design_thesis', 'principal_dimensions'];

    private const MAX_APPEARANCES = 8;

    private const ACTION_CHARS = 400;

    public function __construct(
        private readonly PlanningStageStore $stageStore,
        private readonly GeometryPromptAuthor $objectAuthor,
        private readonly GeometryPromptAuthor $peopleAuthor,
        private readonly ProductionSelectionService $selection,
        private readonly ScreenplaySubjectService $subjects,
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
     * @return list<array{id: string, name: string, kind: string, role: string}>
     */
    public function characters(string $projectId): array
    {
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

        $input = [
            'character_id' => $characterId,
            'screenplay_stage_id' => (string) $screenplay->id,
            'source_hash' => hash('sha256', json_encode(
                $source,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            )),
            'skill_hash' => $author->skillHash(),
        ];

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
            $result = $author->author($source, $stage, $size, $model);
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
                'screenplay_stage_id' => (string) $screenplay->id,
            ],
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

        return [
            'participant' => $this->participant($character),
            'design' => $context,
        ];
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
        return array_intersect_key(
            $character,
            array_flip(['id', 'name', 'role', 'kind', 'description', 'personality', 'appearance']),
        );
    }
}
