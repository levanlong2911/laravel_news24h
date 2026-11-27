<?php

namespace App\Services\Video;

use App\Enums\DesignImageStatus;
use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoDesignImage;
use App\Models\VideoPlanningStage;
use App\Models\VideoRender;
use App\Repositories\Interfaces\VideoDesignImageRepositoryInterface;
use App\Repositories\Interfaces\VideoPlanningStageRepositoryInterface;
use App\Repositories\Interfaces\VideoRenderRepositoryInterface;
use App\Video\Moto\MotoCheckRules;
use Illuminate\Support\Str;

final class VideoTaskService
{
    public function __construct(
        private readonly VideoPlanningStageRepositoryInterface $stages,
        private readonly VideoDesignImageRepositoryInterface $images,
        private readonly VideoRenderRepositoryInterface $renders,
        private readonly ShotSourceService $shots,
    ) {}

    /** @return ?array<string, mixed> what a page shows of one run of the project while it waits, runs or has ended; null when the project holds no run of that id */
    public function stage(string $projectId, string $stageId): ?array
    {
        $row = $this->stages->findStageForProject($projectId, $stageId);

        return $row === null ? null : $this->stageState($projectId, $row);
    }

    /**
     * @param  array{stages: list<string>, images: list<string>, clips: list<string>}  $ids  the runs, cell renders and clip renders a page follows
     * @return array{stages: array<string, array<string, mixed>>, images: array<string, array<string, mixed>>, clips: array<string, array<string, mixed>>} what the page shows of each of them that belongs to the project, by id; reading them never sends anything to a provider
     */
    public function tasks(string $projectId, array $ids): array
    {
        return [
            'stages' => $this->stages->findStagesForProject($projectId, $ids['stages'])
                ->mapWithKeys(fn (VideoPlanningStage $row): array => [(string) $row->id => $this->stageState($projectId, $row)])
                ->all(),
            'images' => $this->images->findTaskImages($projectId, $ids['images'])
                ->mapWithKeys(fn (VideoDesignImage $image): array => [(string) $image->id => $this->imageState($projectId, $image)])
                ->all(),
            'clips' => $ids['clips'] === [] ? [] : $this->renders->findMotoClipTasks($projectId, $ids['clips'])
                ->mapWithKeys(fn (VideoRender $render): array => [(string) $render->id => $this->clipTaskState($projectId, $render)])
                ->all(),
        ];
    }

    /** @return array<string, mixed> what the Render video screen shows of a clip render while it runs, read from the row alone: following it with the provider is the background poll's work or a person's click, never this read */
    private function clipTaskState(string $id, VideoRender $render): array
    {
        $technical = MotoClipSelectionService::technical($render);
        $running = $technical['state'] === 'running';
        $waiting = in_array($render->execution_status?->value, ['queued', 'retry_wait'], true);

        return [
            'render_id' => (string) $render->id,
            'status' => $running ? ($waiting ? 'pending' : 'running') : $technical['state'],
            'done' => ! $running,
            'requested_by' => null,
            'queued_at' => $render->created_at?->toIso8601String(),
            'started_at' => $render->execution_started_at?->format(DATE_ATOM),
            'retry_at' => null,
            'progress' => null,
            'error' => $technical['state'] === 'failed' ? Str::limit((string) $technical['note'], 500) : null,
            'result_url' => $running ? null : route('video-projects.render-video', $id).'#clip-'.($render->shot?->shot_code ?? ''),
        ];
    }

    /** @return array<string, mixed> what a page shows of a cell render while a job waits for it or a worker sends it, and where its result is shown once it ended */
    private function imageState(string $id, VideoDesignImage $image): array
    {
        $task = ImageRenderQueue::taskOf($image);
        $failed = $task === null && $image->status === DesignImageStatus::FAILED->value;

        return [
            'image_id' => (string) $image->id,
            'status' => $task['status'] ?? ($failed ? 'failed' : (string) $image->status),
            'done' => $task === null,
            'requested_by' => $task['requested_by'] ?? null,
            'queued_at' => $task['queued_at'] ?? null,
            'started_at' => $task['started_at'] ?? null,
            'retry_at' => $task['retry_at'] ?? null,
            'progress' => null,
            'error' => $failed ? Str::limit((string) $image->render_error, 500) : null,
            'result_url' => $task !== null ? null : match (true) {
                $image->image_type === DesignImageStore::LOCATION_REFERENCE_TYPE => route('video-projects.locations', $id).'#ref_'.(((array) $image->prompt_spec_json)['request_id'] ?? ''),
                is_string(((array) $image->prompt_spec_json)['shot_id'] ?? null) => route('video-projects.keyframes', $id).'#shot-'.((array) $image->prompt_spec_json)['shot_id'],
                default => route('video-projects.anchor', $id).'#modelSheetPanel',
            },
        ];
    }

    /** @return array<string, mixed> what a page shows of a run while it waits, runs or has ended; never its snapshot */
    private function stageState(string $id, VideoPlanningStage $row): array
    {
        $task = PlanningStageStore::taskOf($row);
        $done = in_array($row->status, [VideoPlanningStageStatus::SUCCEEDED->value, VideoPlanningStageStatus::FAILED->value], true);
        $stream = PlanningStageStore::streamOf($row);

        return [
            'stage_id' => (string) $row->id,
            'step' => (string) $row->stage,
            'revision' => (int) $row->planning_revision,
            'status' => (string) $row->status,
            'done' => $done,
            'requested_by' => $task['requested_by'] ?? (PlanningStageStore::metadataOf(is_array($row->input_json) ? $row->input_json : null)['requested_by']['name'] ?? null),
            'queued_at' => $task['queued_at'] ?? null,
            'started_at' => $row->started_at?->toIso8601String(),
            'finished_at' => $row->finished_at?->toIso8601String(),
            'retry_at' => $task['retry_at'] ?? null,
            'progress' => $stream === null ? null : array_intersect_key($stream, array_flip(['state', 'phase', 'text_chars', 'thinking_chars', 'output_tokens', 'elapsed_seconds'])),
            'error' => $row->status === VideoPlanningStageStatus::FAILED->value ? Str::limit((string) $row->error_message, 500) : null,
            'result_url' => $done ? $this->resultUrl($id, $row) : null,
        ];
    }

    /** @return string the page that shows a finished run: the run itself when it succeeded, the step page when it failed */
    private function resultUrl(string $id, VideoPlanningStage $row): string
    {
        $succeeded = $row->status === VideoPlanningStageStatus::SUCCEEDED->value;

        return match ($row->stage) {
            PlanningStageName::LOCATIONS->value => route('video-projects.locations', $succeeded ? ['id' => $id, 'stage' => $row->id] : ['id' => $id]),
            PlanningStageName::SCRIPT->value => route('video-projects.anchor', $succeeded ? ['id' => $id, 'script' => $row->id] : ['id' => $id]).'#scriptPanel',
            PlanningStageName::SCENES->value => route('video-projects.scene', $succeeded ? ['id' => $id, 'stage' => $row->id] : ['id' => $id]),
            PlanningStageName::SHOTS->value => $this->shotPlanChosen($id, $row)
                ? route('video-projects.reference', ['id' => $id, 'shots_approved' => (string) $row->id])
                : route('video-projects.scene', $succeeded ? ['id' => $id, 'shots' => $row->id] : ['id' => $id]),
            PlanningStageName::YACHT_DESIGN->value => route('video-projects.anchor', $id).($succeeded && MotoCheckRules::usable((array) ($row->output_json['check'] ?? [])) ? '#modelSheetPanel' : ''),
            PlanningStageName::MODEL_SHEET_PROMPT->value => route('video-projects.anchor', $id).'#modelSheetPanel',
            PlanningStageName::CLIP_PROMPT->value => route('video-projects.render-video', $id).'#clip-'.($row->input_json['shot_id'] ?? ''),
            default => route('video-projects.anchor', $id),
        };
    }

    /** @return bool whether the shot run is the shot plan the project uses now: a run that passed was approved by rule pass when it finished */
    private function shotPlanChosen(string $id, VideoPlanningStage $stage): bool
    {
        return $stage->status === VideoPlanningStageStatus::SUCCEEDED->value
            && ($this->shots->selection($id)['stage_id'] ?? null) === (string) $stage->id;
    }
}
