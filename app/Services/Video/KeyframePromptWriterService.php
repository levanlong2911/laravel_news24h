<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Models\VideoDesignImage;
use App\Models\VideoPlanningStage;
use App\Repositories\Interfaces\AiPhaseRepositoryInterface;
use App\Repositories\Interfaces\VideoArtifactRepositoryInterface;
use App\Repositories\Interfaces\VideoPlanningStageRepositoryInterface;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Repositories\Interfaces\VideoReviewDecisionRepositoryInterface;
use App\Video\Llm\LlmClient;
use App\Video\Moto\MotoContract;
use App\Video\Moto\MotoKeyframePrompt;
use App\Video\Script\ScriptEvaluation;
use App\Video\Superyacht\Spec\PhaseResolver;
use App\Video\Superyacht\Support\CanonicalJson;
use RuntimeException;

final class KeyframePromptWriterService extends ShotPromptWriterService
{
    /** @var string the key of the project metadata that holds, by shot plan and shot, the keyframe prompt a person approved */
    public const SELECTION = 'moto_keyframe_prompts';

    /** @var string the key of the project metadata that holds, by shot plan, shot and the candidate to edit, the edit prompt a person approved for that candidate; it never takes the place of the prompt the shot composes from */
    public const EDIT_SELECTION = 'moto_keyframe_edit_prompts';

    public const ENTITY_TYPE = 'keyframe_prompt';

    public const EDIT_ENTITY_TYPE = 'keyframe_edit_prompt';

    protected const STAGE = PlanningStageName::KEYFRAME_PROMPT;

    protected const STEP = 'keyframe_prompts';

    protected const LABEL = 'viết prompt ảnh';

    protected const NOUN = 'prompt ảnh';

    protected const TASK = 'keyframe prompt';

    protected const PHASE = 'Phase 9';

    protected const WORKFLOWS = 'video.keyframe_prompts.workflows';

    protected const ADAPTER = MotoKeyframePrompt::class;

    public const TEXT_KEY = 'image_prompt';

    /** @var array<string, array{context: array<string, mixed>, built: array<string, mixed>, frame: array<string, mixed>}> by project and shot, the keyframe context, the cards and the image settings a screen has already read, so the stored runs of the shot are verified from them and not read again */
    private array $given = [];

    /** @var array<string, array<string, mixed>> the package of a stored run by project, shot and the contract the run pinned, kept for one reading of the screen only */
    private array $stored = [];

    /** @var ?array<string, mixed> the edit a stored run was written for, while that run is prepared again; null for a run that composes */
    private ?array $editing = null;

    /** @var array<string, array<string, list<VideoPlanningStage>>> the edit runs of a shot by the candidate each edits, newest first, kept for one reading of the screen */
    private array $editRuns = [];

    public function __construct(
        PlanningStageStore $stageStore,
        LlmClient $llm,
        private readonly MotoKeyframeSourceService $frames,
        PromptSelectionReader $selections,
        VideoProjectRepositoryInterface $projects,
        VideoPlanningStageRepositoryInterface $stages,
        VideoReviewDecisionRepositoryInterface $decisions,
        VideoArtifactRepositoryInterface $artifacts,
        AiPhaseRepositoryInterface $phases,
        PhaseResolver $resolver,
        ScriptEvaluation $evaluation,
    ) {
        parent::__construct($stageStore, $llm, $selections, $projects, $stages, $decisions, $artifacts, $phases, $resolver, $evaluation);
    }

    /**
     * @param  array<string, mixed>  $context  MotoKeyframeSourceService::context()
     * @param  array<string, mixed>  $shot  a stored shot
     * @param  array<string, mixed>  $built  MotoKeyframeSourceService::cards() of the shot
     * @param  array<string, mixed>  $frame  the image settings of the shot, MotoKeyframeService::settingsFor()
     * @return array<string, mixed> what the Prompt column shows for the shot: the approved prompt and whether its sources still hold, the newest run and the run shown with its check, its history, what a new run sends and costs, and why one cannot be sent; reading it writes nothing
     */
    public function screen(string $projectId, array $context, array $shot, array $built, array $frame): array
    {
        $shotId = (string) $shot['id'];
        $this->given = ["{$projectId}|{$shotId}" => ['context' => $context, 'built' => $built, 'frame' => $frame]];
        $this->stored = [];

        try {
            return $this->promptScreen($projectId, (string) $context['stage']->id, $shotId, $context['prompts'][$shotId] ?? null, fn (): array => $this->package($projectId, $shotId, $context, $built, $frame));
        } finally {
            $this->given = [];
            $this->stored = [];
        }
    }

    /**
     * @param  array<string, mixed>  $context  MotoKeyframeSourceService::context()
     * @param  array<string, mixed>  $shot  a stored shot
     * @param  array<string, mixed>  $built  MotoKeyframeSourceService::cards() of the shot
     * @param  array<string, mixed>  $frame  the image settings of the shot
     * @param  string  $artifactId  the rendered file of the candidate a person may ask to edit
     * @return array<string, mixed> what the candidate shows of its edit: the edit prompt approved for it and whether its sources still hold, the newest edit run and the one shown with its check and the request it was written for, and whether a new one can be asked; no estimate is given before a person names what to edit. Reading it writes nothing
     */
    public function editScreen(string $projectId, array $context, array $shot, array $built, array $frame, string $artifactId): array
    {
        $shotId = (string) $shot['id'];
        $shotStageId = (string) $context['stage']->id;
        $this->given = ["{$projectId}|{$shotId}" => ['context' => $context, 'built' => $built, 'frame' => $frame]];
        $this->stored = [];

        try {
            $runs = ($this->editRuns["{$projectId}|{$shotStageId}|{$shotId}"] ??= $this->editStagesOf($projectId, $shotStageId, $shotId))[$artifactId] ?? [];
            $screen = $this->promptScreen($projectId, $shotStageId, $shotId, $context['edit_prompts'][$shotId][$artifactId] ?? null, null, $runs);
            $shown = $screen['shown'] === null ? null : collect($runs)->first(static fn (VideoPlanningStage $stage): bool => (string) $stage->id === $screen['shown']['id']);

            return $screen + ['request' => $shown === null ? null : array_intersect_key((array) ($shown->input_json['source']['edit'] ?? []), array_flip(['faults', 'targets', 'note', 'references']))];
        } finally {
            $this->given = [];
            $this->stored = [];
        }
    }

    /**
     * @param  array{artifact_id: string, faults: list<string>, targets: list<array{type: string, id: string}>, note: ?string, references: list<string>}  $edit  the candidate to edit, what the person named as wrong and the reference images they keep
     * @param  ?array{id: string, name: string}  $requester
     * @return array{0: ?VideoPlanningStage, 1: string} reason queued|replayed|ok, or why nothing ran; every request is a run of its own, and the run never takes the place of the prompt the shot composes from
     */
    public function runEdit(string $projectId, string $shotId, array $edit, string $operationId, ?array $requester = null): array
    {
        $this->forget($projectId);

        return (new SpecStageRunner($this->stageStore, $this->stages, $this->evaluation))->submit(
            static::STEP,
            $projectId,
            static::STAGE,
            fn (): array => $this->prepared($this->package($projectId, $shotId, null, null, null, null, $edit)),
            true,
            $operationId,
            $this->llm,
            fn (array $run, array $evaluation): array => $this->output($run, $evaluation),
            'viết prompt sửa ảnh',
            static::TASK,
            $requester,
        );
    }

    /** What a screen has already read of the shot is used for the package, and the package is kept for that one reading; an approval and a queued run read every source again. A run written for an edit is prepared again for that same edit. */
    protected function contractPackage(string $projectId, string $shotId, array $contract): array
    {
        $given = $this->given["{$projectId}|{$shotId}"] ?? null;

        if ($given === null) {
            return $this->package($projectId, $shotId, null, null, null, $contract, $this->editing);
        }

        $key = implode('|', [$projectId, $shotId, (string) $contract['inputs'], (string) $contract['phase_id'], (string) $contract['prompt_sha256'], CanonicalJson::hash($this->editing)]);

        return $this->stored[$key] ??= $this->package($projectId, $shotId, $given['context'], $given['built'], $given['frame'], $contract, $this->editing);
    }

    /** A stored run of an edit keeps the candidate, the request and the reference images it was written for with its source; it is prepared again for those, so a candidate or a reference that changed gives another fingerprint. */
    protected function storedPackage(string $projectId, VideoPlanningStage $stage): array
    {
        $edit = $stage->input_json['source']['edit'] ?? null;
        $this->editing = is_array($edit) ? $edit : null;

        try {
            return parent::storedPackage($projectId, $stage);
        } finally {
            $this->editing = null;
        }
    }

    /** @return void nothing of the project is kept: an approval, a new run and a queued run read every source again */
    protected function forget(string $projectId): void
    {
        $this->given = [];
        $this->stored = [];
        $this->editRuns = [];
        parent::forget($projectId);
    }

    protected function shotStageOf(string $projectId, string $shotId): ?string
    {
        $context = $this->frames->context($projectId);

        return isset($context['shots'][$shotId]) ? (string) $context['stage']->id : null;
    }

    /** @return string a decision on an edit run is recorded as one on an edit prompt, never as one on the prompt of the shot */
    protected function entityTypeOf(VideoPlanningStage $stage): string
    {
        return isset($stage->input_json['edit_of']) ? self::EDIT_ENTITY_TYPE : self::ENTITY_TYPE;
    }

    /** An approved edit run is kept for the candidate it edits; the prompt the shot composes from, and the keyframe in use, stay as they were. */
    protected function kept(array $metadata, string $shotStageId, string $shotId, VideoPlanningStage $stage, array $entry): array
    {
        $editOf = $stage->input_json['edit_of'] ?? null;

        if (! is_string($editOf) || $editOf === '') {
            return parent::kept($metadata, $shotStageId, $shotId, $stage, $entry);
        }

        $metadata[self::EDIT_SELECTION][$shotStageId][$shotId][$editOf] = $entry;

        return $metadata;
    }

    /** @return list<VideoPlanningStage> every run that composes the prompt of the shot in this shot plan, newest first; the runs written for an edit of a candidate are not among them */
    protected function stagesOf(string $projectId, string $shotStageId, string $shotId): array
    {
        return $this->stages->findKeyframeComposeRunsOfShot($projectId, $shotStageId, $shotId);
    }

    /** @return array<string, list<VideoPlanningStage>> every edit run of the shot in this shot plan by the rendered file it edits, newest first */
    private function editStagesOf(string $projectId, string $shotStageId, string $shotId): array
    {
        $runs = [];

        foreach (VideoPlanningStage::query()->withoutSnapshot()->where('project_id', $projectId)
            ->where('stage', static::STAGE->value)->where('input_json->shot_stage_id', $shotStageId)
            ->where('input_json->shot_id', $shotId)->whereNotNull('input_json->edit_of')->orderByDesc('planning_revision')->get() as $stage) {
            $runs[(string) $stage->input_json['edit_of']][] = $stage;
        }

        return $runs;
    }

    /**
     * @param  ?array<string, mixed>  $context  MotoKeyframeSourceService::context(), read again when not given
     * @param  ?array<string, mixed>  $built  the cards of the shot, built again when not given
     * @param  ?array<string, mixed>  $frame  the image settings of the shot, read again when not given
     * @param  ?array{inputs: string, phase_id: string, prompt_sha256: string, bundle_hash: string, manifest: array<string, mixed>}  $contract  the contract a stored run pinned, to prepare its package again; null prepares a new run under the current revision and input format
     * @param  ?array<string, mixed>  $edit  the candidate to edit by its rendered file, what a person named as wrong and the reference images they keep; null composes the prompt of the shot
     * @return array{run: array<string, mixed>, sources: list<array<string, mixed>>, fingerprint: string} the run of one shot without its image bytes, the images it sends and the fingerprint of everything it is written from. A run of an edit sends the candidate first and then the kept reference images, counted again, and its fingerprint holds the candidate and the request
     *
     * @throws RuntimeException when it cannot be prepared, the stored run pinned an input format this code no longer prepares, or an edit is asked under an input format that has none, for a file that is no candidate of the shot, with a request that names what the shot does not have, or with more images than the image model takes
     */
    protected function package(string $projectId, string $shotId, ?array $context = null, ?array $built = null, ?array $frame = null, ?array $contract = null, ?array $edit = null): array
    {
        $flow = $this->workflowFor($projectId);

        if ($contract === null) {
            $pins = $this->pins($projectId, $flow);
        } else {
            if (! in_array($contract['inputs'], MotoKeyframePrompt::INPUT_FORMATS, true)) {
                throw new RuntimeException("Lượt này viết theo đầu vào {$contract['inputs']} mà code không còn dựng lại — không xác minh được nguồn.");
            }

            $flow['adapter'] = $flow['adapter']->reading((string) $contract['inputs']);
            $pins = $this->pinsUnder($this->sourcePins($projectId), $contract, $flow['adapter']);
        }

        $settings = $flow['adapter']->settings();
        $context ??= $this->frames->context($projectId);
        $shot = $context['shots'][$shotId] ?? throw new RuntimeException("Shot {$shotId} không có trong kế hoạch shot đã duyệt.");
        $built ??= $this->frames->cards($context, $shot);

        if ($edit === null && $built['problems'] !== []) {
            throw new RuntimeException('Chưa đủ nguồn ảnh của shot: '.implode(' · ', $built['problems']));
        }

        $frame ??= app(MotoKeyframeService::class)->settingsFor($context, $shot);

        if ((string) ($frame['model'] ?? '') === '' || (string) ($frame['size'] ?? '') === '') {
            throw new RuntimeException('Shot chưa có model và size ảnh dùng được — chọn ở cột References trước.');
        }

        $sixth = $flow['adapter']->inputsVersion() === MotoContract::KEYFRAME_PROMPT_INPUT_V6;
        $pinned = (array) (PlanningStageStore::metadataOf(is_array($context['stage']->input_json) ? $context['stage']->input_json : null)['context'] ?? []);
        $sources = array_values((array) $built['sources']);
        $base = null;
        $request = null;

        if ($edit !== null) {
            if (! $sixth) {
                throw new RuntimeException('Đầu vào '.$flow['adapter']->inputsVersion().' của '.static::PHASE.' chưa có chế độ sửa ảnh.');
            }

            $artifactId = (string) ($edit['artifact_id'] ?? '');
            $base = collect($context['images'][$shotId] ?? [])->first(static fn (VideoDesignImage $image): bool => $artifactId !== '' && (string) MotoKeyframeFramingService::master($image)?->id === $artifactId)
                ?? throw new RuntimeException('Ảnh cần sửa không phải ứng viên của shot này.');
            $environment = collect($sources)->firstWhere('kind', 'environment');
            $request = [
                'faults' => array_values((array) ($edit['faults'] ?? [])),
                'targets' => array_values(array_map(static fn (mixed $target): array => is_array($target) ? ['type' => (string) ($target['type'] ?? ''), 'id' => (string) ($target['id'] ?? '')] : [], (array) ($edit['targets'] ?? []))),
                'note' => is_string($edit['note'] ?? null) && trim($edit['note']) !== '' ? trim($edit['note']) : null,
            ];
            $problem = MotoKeyframePrompt::editRequestProblem($request, $shot, array_column((array) ($pinned['configurations'] ?? []), null, 'id'), $environment === null ? null : (string) $environment['target']);

            if ($problem !== null) {
                throw new RuntimeException($problem);
            }

            $manifest = $this->frames->editSources($context, $shot, $base, $built, array_values(array_map('strval', (array) ($edit['references'] ?? []))));

            if ($manifest['problems'] !== []) {
                throw new RuntimeException(implode(' · ', $manifest['problems']));
            }

            $sources = $manifest['sources'];
        }

        $images = array_map(static fn (array $image): array => ['position' => (int) $image['position'] + 1, 'kind' => (string) $image['kind'], 'role' => (string) $image['role'], 'usage' => (string) $image['usage'], 'chosen_by' => (string) $image['chosen'], 'name' => (string) $image['image_code'], 'artifact_id' => (string) $image['artifact_id'], 'sha256' => (string) $image['sha256']]
            + ($image['kind'] === 'environment' ? ['background' => (string) $image['target'], 'background_name' => (string) ($image['title'] ?? '')] : [])
            + array_intersect_key($image, array_flip(['parts', 'shot', 'character', 'view', 'cell_code', 'cell_row', 'cell_column', 'items'])), $sources);
        $policy = (array) $settings['composition_policy'];
        $framing = $sixth && is_array($context['framing'] ?? null) ? array_intersect_key($context['framing'], array_flip(['version', 'master_aspect_ratio', 'delivery_aspect_ratio', 'delivery_width', 'delivery_height'])) : null;
        $inputs = MotoKeyframePrompt::inputs($shot, $pinned, $pins['design'], $pins['script'], $images, $policy, '', (int) $settings['max_prompt_chars'], $flow['adapter']->inputsVersion(), $framing,
            $base === null ? null : ['base' => array_intersect_key($images[0], array_flip(['position', 'name', 'artifact_id', 'sha256'])), 'background' => $environment === null ? null : ['background' => (string) $environment['target'], 'background_name' => (string) ($environment['title'] ?? ''), 'chosen_by' => (string) $environment['chosen']]] + $request);
        $source = [
            'inputs' => $flow['adapter']->inputsVersion(),
            'phase' => ['id' => (string) $pins['phase']->id, 'prompt_sha256' => (string) $pins['phase']->content_sha256],
            'shots' => ['stage_id' => (string) $context['approved']['token']['stage_id'], 'content_hash' => (string) $context['approved']['token']['content_hash']],
            'shot_id' => $shotId,
            'script' => $pins['script_token'],
            'design' => $pins['design_pin'],
            'images' => $images,
            'policy' => $policy,
        ] + ($framing === null ? [] : ['framing' => $framing])
            + ($base === null ? [] : ['edit' => ['image_id' => (string) $base->id, 'artifact_id' => (string) $sources[0]['artifact_id'], 'sha256' => (string) $sources[0]['sha256'], 'references' => array_values(array_column(array_slice($sources, 1), 'artifact_id'))] + $request]);
        $package = ['sources' => $sources] + $this->runOf($projectId, (string) $context['stage']->id, $shotId, $flow, $pins, $inputs, $source);

        if ($base !== null) {
            $package['run']['input']['edit_of'] = (string) $sources[0]['artifact_id'];
        }

        return $package;
    }
}
