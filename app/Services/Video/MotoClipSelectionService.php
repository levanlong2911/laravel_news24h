<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Models\VideoProject;
use App\Models\VideoRender;
use App\Models\VideoReviewDecision;
use App\Models\VideoSession;
use App\Models\VideoSessionEvent;
use App\Models\VideoShot;
use App\Video\Moto\MotoClipPlanBuilder;
use App\Video\Render\Enums\RenderStatus;
use App\Video\Render\Video\SceneClipDispatchService;
use App\Video\Render\Video\SceneShotFactory;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * What a clip of the shot flow is worth against the plan now: which results fit, which one a person approved, and the manifest Phase 11 cuts from.
 * A result keeps the source it was made from; a preview is never an approval, and a change of source never approves or re-renders anything.
 */
final class MotoClipSelectionService
{
    public const MANIFEST_FORMAT = 'moto-clip-manifest-v1';

    /** @var string how the hash of the manifest is taken: a change of what it covers is a new version, so a hash taken before is never read as one taken now */
    public const MANIFEST_HASH_VERSION = 'moto-clip-manifest-hash-v1';

    private const RUNNING = ['claimed', 'preparing', 'submitting', 'submitted', 'provider_running', 'polling', 'artifact_writing', 'checkpointing', 'provider_unknown'];

    public function __construct(
        private readonly MotoClipPlanService $plans,
        private readonly FilesystemFactory $storage,
    ) {}

    /**
     * @param  array<string, mixed>  $preview  the plan the sources build now
     * @return array{status: 'valid'|'stale', reasons: list<string>, fingerprint: string} whether this result fits the plan now, judged by what its request froze; a new revision of the template does not make it stale, since the prompt it was sent with is its own
     */
    public function verdict(VideoShot $shot, VideoRender $render, array $preview): array
    {
        $reasons = [];
        $request = json_decode((string) $render->render_request_json, true);
        $moto = is_array($request) && is_array($request['moto_clip'] ?? null) ? $request['moto_clip'] : null;
        $token = (array) $preview['plan']['source']['token'];
        $clip = collect($preview['plan']['clips'])->firstWhere('shot_id', $shot->shot_code);

        if ((string) $render->shot_id !== (string) $shot->id || (string) $render->execution_purpose !== SceneClipDispatchService::PURPOSE_PRODUCTION || $render->execution_status !== RenderStatus::SUCCEEDED) {
            $reasons[] = 'render_not_usable';
        }

        if (trim((string) $render->artifact_path) === '' || preg_match('/^[a-f0-9]{64}$/', (string) $render->primary_artifact_hash) !== 1) {
            $reasons[] = 'artifact_unverifiable';
        }

        if ($moto === null) {
            $reasons[] = 'request_snapshot_missing';
        } else {
            if (($moto['source']['token']['stage_id'] ?? null) !== $token['stage_id'] || ($moto['source']['token']['content_hash'] ?? null) !== $token['content_hash']) {
                $reasons[] = 'shot_plan_changed';
            }

            if ($clip === null) {
                $reasons[] = 'shot_not_in_plan';
            } elseif (($moto['keyframe']['artifact_id'] ?? null) !== ($clip['keyframe']['artifact_id'] ?? null) || ($moto['keyframe']['sha256'] ?? null) !== ($clip['keyframe']['sha256'] ?? null)) {
                $reasons[] = 'keyframe_changed';
            }

            if ((int) $render->duration_ms < (int) ($moto['used']['start_ms'] ?? 0) + (int) ($moto['used']['duration_ms'] ?? 0)) {
                $reasons[] = 'used_outside_file';
            }
        }

        return [
            'status' => $reasons === [] ? 'valid' : 'stale',
            'reasons' => $reasons,
            'fingerprint' => CanonicalJson::hash([
                'render_id' => (string) $render->id,
                'artifact_hash' => (string) $render->primary_artifact_hash,
                'request_hash' => (string) $render->request_hash,
                'stage_id' => $token['stage_id'],
                'content_hash' => $token['content_hash'],
                'keyframe_artifact_id' => $moto['keyframe']['artifact_id'] ?? null,
            ]),
        ];
    }

    /**
     * @param  ?string  $reviewerId  who decides
     * @return array{0: VideoShot, 1: string} approved or replayed: one click both selects the result for Phase 11 and records the approval of that very file
     *
     * @throws RuntimeException when the result no longer fits, its file changed, or another click changed the shot
     */
    public function approve(string $projectId, string $shotId, string $renderId, int $expectedIntent, string $operationId, ?string $reviewerId): array
    {
        if (! Str::isUuid($operationId)) {
            throw new RuntimeException('Mã thao tác duyệt không hợp lệ — tải lại trang.');
        }

        return DB::transaction(function () use ($projectId, $shotId, $renderId, $expectedIntent, $operationId, $reviewerId): array {
            VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            $payloadHash = hash('sha256', json_encode([$projectId, $shotId, $renderId, $expectedIntent], JSON_THROW_ON_ERROR));
            $replayed = VideoSessionEvent::query()
                ->whereIn('session_id', VideoSession::query()->where('project_id', $projectId)->select('id'))
                ->where('operation_id', $operationId)
                ->first();

            if ($replayed !== null) {
                if (! hash_equals((string) $replayed->operation_payload_hash, $payloadHash)) {
                    throw new RuntimeException('Mã thao tác này đã dùng cho một yêu cầu khác — tải lại trang.');
                }

                return [VideoShot::query()->findOrFail($replayed->entity_id), 'replayed'];
            }

            $preview = $this->plans->preview($projectId);
            $shot = $this->shotsOf($projectId, (string) $preview['plan']['source']['planning_stage_id'], [$shotId], true)->first();

            if ($shot === null) {
                throw new RuntimeException("Shot {$shotId} chưa có lượt render nào trong bản kế hoạch hiện hành.");
            }

            if ((int) $shot->intent_version !== $expectedIntent) {
                throw new RuntimeException('Clip đã thay đổi ở thao tác khác. Tải lại trang trước khi duyệt.');
            }

            $render = VideoRender::query()->whereKey($renderId)->where('shot_id', $shot->id)->first() ?? throw new RuntimeException('Kết quả này không thuộc shot đang duyệt.');
            $verdict = $this->verdict($shot, $render, $preview);

            if ($verdict['status'] !== 'valid') {
                throw new RuntimeException('Kết quả này không còn khớp nguồn hiện hành ('.implode(', ', $verdict['reasons']).') — không duyệt được.');
            }

            if ($this->fileProblem($render) !== null) {
                throw new RuntimeException('File của kết quả này không còn hoặc khác hash đã ghi — không duyệt được.');
            }

            $request = (array) json_decode((string) $render->render_request_json, true);
            $token = (array) $preview['plan']['source']['token'];
            $next = $expectedIntent + 1;
            $shot->forceFill([
                'video_render_id' => $render->id,
                'motion_spec_hash' => (string) ($request['motion_spec_hash'] ?? ''),
                'scene_status' => 'video_ready',
                'approved_at' => now(),
                'approved_artifact_hash' => (string) $render->primary_artifact_hash,
                'approved_by_admin_id' => $reviewerId,
                'intent_version' => $next,
                'auto_select_version' => null,
            ])->save();
            $event = VideoSessionEvent::create([
                'session_id' => $shot->session_id,
                'event_type' => 'shot_render_selected',
                'entity_type' => 'shot',
                'entity_id' => $shot->id,
                'payload_json' => ['render_id' => (string) $render->id, 'expected_intent_version' => $expectedIntent],
                'operation_id' => $operationId,
                'operation_payload_hash' => $payloadHash,
                'operation_result_json' => ['status' => 'approved', 'selected_render_id' => (string) $render->id, 'intent_version' => $next],
            ]);
            VideoReviewDecision::create([
                'project_id' => $projectId,
                'session_id' => $shot->session_id,
                'entity_type' => 'shot',
                'entity_id' => $shot->id,
                'decision' => 'approved',
                'revision' => 1 + (int) VideoReviewDecision::query()->where('entity_type', 'shot')->where('entity_id', $shot->id)->max('revision'),
                'reviewer_id' => $reviewerId,
                'reason' => 'Duyệt clip của shot cho Phase 11.',
                'metadata_json' => [
                    'operation_event_id' => (string) $event->id,
                    'selected_render_id' => (string) $render->id,
                    'artifact_hash' => (string) $render->primary_artifact_hash,
                    'request_hash' => (string) $render->request_hash,
                    'shot_plan_stage_id' => $token['stage_id'],
                    'content_hash' => $token['content_hash'],
                    'selection_version' => $token['selection_version'],
                    'keyframe_artifact_id' => $request['moto_clip']['keyframe']['artifact_id'] ?? null,
                    'selection_fingerprint' => $verdict['fingerprint'],
                    'intent_version' => $next,
                ],
            ]);

            return [$shot->refresh(), 'approved'];
        }, attempts: 3);
    }

    /**
     * @return array<string, mixed> what Phase 11 cuts from: the approved result of every shot in plan order with the evidence for it, read from the decisions and never from the latest clip, and its file read again from disk; a shot with no approval that still fits, or whose file is gone or changed, is listed as missing with why
     *
     * @throws RuntimeException when the shot plan cannot be used
     */
    public function manifest(string $projectId): array
    {
        $preview = $this->plans->preview($projectId);
        $source = $preview['source'];
        $rows = array_column((array) $source['plan']['shots'], null, 'id');
        $shots = $this->shotsOf($projectId, (string) $source['stage_id'], array_keys($rows))->keyBy('shot_code');
        $clips = [];
        $missing = [];
        $problems = [];

        foreach ($preview['plan']['clips'] as $clip) {
            $shot = $shots->get($clip['shot_id']);
            $approved = $shot === null ? null : $this->approvedOf($shot);
            $verdict = $approved === null ? null : $this->verdict($shot, $approved['render'], $preview);
            $problem = match (true) {
                $approved === null => 'not_approved',
                $verdict['status'] !== 'valid' => implode(',', $verdict['reasons']),
                default => $this->fileProblem($approved['render']),
            };

            if ($problem !== null) {
                $missing[] = $clip['shot_id'];
                $problems[$clip['shot_id']] = $problem;

                continue;
            }

            /** @var VideoRender $render */
            $render = $approved['render'];
            $file = (array) ($render->artifact_manifest['artifacts'][0] ?? []);
            $row = $rows[$clip['shot_id']];
            $clips[] = [
                'shot_id' => $clip['shot_id'],
                'scene_id' => $clip['scene_id'],
                'order' => $clip['order'],
                'render_id' => (string) $render->id,
                'artifact' => ['disk' => (string) config('video.veo.disk'), 'path' => (string) $render->artifact_path, 'sha256' => (string) $render->primary_artifact_hash],
                'request_hash' => (string) $render->request_hash,
                'measured' => ['duration_ms' => (int) $render->duration_ms, 'width' => (int) $render->width, 'height' => (int) $render->height, 'bytes' => (int) $render->bytes, 'has_audio' => $file['has_audio'] ?? null],
                'used' => $clip['used'],
                'decision' => [
                    'id' => (string) $approved['decision']->id,
                    'reviewer_id' => $approved['decision']->reviewer_id,
                    'decided_at' => $approved['decision']->created_at?->toIso8601String(),
                    'selection_version' => (int) $approved['decision']->metadata_json['selection_version'],
                    'intent_version' => (int) $approved['decision']->metadata_json['intent_version'],
                ],
                'tl_in_ms' => $row['tl_in_ms'] ?? null,
                'transition_in' => $row['transition_in'] ?? null,
                'cards' => $row['cards'] ?? [],
            ];
        }

        $manifest = [
            'format' => self::MANIFEST_FORMAT,
            'ready' => $missing === [],
            'missing' => $missing,
            'problems' => $problems,
            'source' => $preview['plan']['source'],
            'selection_version' => (int) $preview['plan']['source']['token']['selection_version'],
            'total_ms' => (int) $preview['plan']['total_ms'],
            'clips' => $clips,
        ];

        // The phase-10 revision every clip was judged against, so a reader holding that revision can tell it is the one used; it stays outside the hash, which covers the film's files.
        $contract = ['phase_id' => (string) $preview['plan']['contract']['phase_id'], 'phase_revision' => (int) $preview['plan']['contract']['phase_revision'], 'prompt_sha256' => (string) $preview['plan']['contract']['prompt_sha256']];

        return $manifest + ['contract' => $contract, 'hash_version' => self::MANIFEST_HASH_VERSION, 'hash' => self::manifestHash($manifest)];
    }

    /**
     * @param  array<string, mixed>  $manifest  manifest() without its hash
     * @return string the hash of what a film is built from: the shot plan by its token and, for every approved clip, its file, its request, the part it keeps, its decision and its place; who approved it and when, and why a shot is missing, stay outside, since they change nothing in the film
     */
    public static function manifestHash(array $manifest): string
    {
        return CanonicalJson::hash([
            'version' => self::MANIFEST_HASH_VERSION,
            'format' => $manifest['format'],
            'source' => $manifest['source'],
            'selection_version' => $manifest['selection_version'],
            'total_ms' => $manifest['total_ms'],
            'missing' => $manifest['missing'],
            'clips' => array_map(static fn (array $clip): array => ['decision' => array_diff_key($clip['decision'], ['reviewer_id' => true, 'decided_at' => true])] + $clip, $manifest['clips']),
        ]);
    }

    /**
     * @return array<string, mixed> what the Render video screen shows for the project; opening it reads only and writes nothing
     */
    public function screen(string $projectId): array
    {
        $base = ['scenes' => [], 'clips' => [], 'keyframes' => [], 'summary' => ['with_keyframe' => 0, 'running' => 0, 'done' => 0, 'failed' => 0], 'motoClips' => true];

        try {
            $preview = $this->plans->preview($projectId);
        } catch (RuntimeException $e) {
            return $base + ['clipModels' => [], 'problem' => $e->getMessage()];
        }

        $models = array_map(static fn (array $entry): array => ['id' => (string) $entry['id'], 'label' => (string) $entry['label'], 'default' => $entry['default'] === true, 'controls' => $entry['controls']], $preview['models']);
        $shots = $this->shotsOf($projectId, null, array_column($preview['plan']['clips'], 'shot_id'))->groupBy('shot_code');
        $renders = VideoRender::query()
            ->whereIn('shot_id', $shots->flatten()->pluck('id'))
            ->where('render_kind', 'video')
            ->where('execution_purpose', SceneClipDispatchService::PURPOSE_PRODUCTION)
            ->orderBy('attempt_no')
            ->get()
            ->groupBy('shot_id');
        $scenes = [];
        $clips = [];
        $keyframes = [];
        $summary = $base['summary'];
        $stageId = (string) $preview['plan']['source']['planning_stage_id'];

        foreach ($preview['plan']['clips'] as $clip) {
            $shotId = (string) $clip['shot_id'];
            $own = $shots->get($shotId, collect());
            $shot = $own->first(static fn (VideoShot $one): bool => self::stageOf($one) === $stageId);
            $cell = $shot === null ? null : $this->cell($projectId, $shotId, $shot, $renders->get((string) $shot->id, collect()), $preview);
            $seconds = (int) ($clip['render']['duration_ms'] / 1000);
            $entry = collect($models)->firstWhere('id', $clip['render']['model']);

            if ($cell !== null) {
                $cell['history'] = $this->history($own, $renders, $stageId);
                $clips[$shotId] = $cell;
            }

            $scenes[] = [
                'scene_id' => $shotId,
                'title' => $shotId.' · '.$clip['scene_id'].' · thứ tự '.$clip['order'],
                'video_prompt' => $clip['prompt']['text'] ?? null,
                'duration_seconds' => $seconds,
                'clip_blocked' => $clip['blocked']['message'] ?? null,
                'clip_hash' => $clip['hash'],
                // The page offers only the settings the hash was made for, so what a person confirms is what is sent.
                'clip_models' => $entry === null ? [] : [['controls' => ['resolutions' => [$clip['render']['resolution']], 'default_resolution' => $clip['render']['resolution']] + $entry['controls']] + $entry],
                'render_url' => route('video-projects.scene-clip-render', [$projectId, $shotId]),
                'meta_lines' => [
                    ...array_map(static fn (string $word): string => 'Cần xem trước khi gửi: prompt có chữ "'.$word.'", thường là chỉ dẫn dựng phim — kiểm lại câu nguồn; không bị chặn.', MotoClipPlanBuilder::editWarnings((string) ($clip['prompt']['text'] ?? ''))),
                    'Model: '.($clip['render']['model'] ?? 'chưa có model làm được').' · '.$clip['render']['mode'],
                    'Render '.$seconds.' s · '.($clip['render']['resolution'] ?? '—').' · phim giữ '.$clip['used']['start_ms'].'–'.($clip['used']['start_ms'] + $clip['used']['duration_ms']).' ms',
                    'Keyframe: '.($clip['keyframe'] === null ? ($clip['blocked']['code'] ?? 'chưa có') : 'đã duyệt · '.substr((string) $clip['keyframe']['sha256'], 0, 12)),
                ],
            ];

            if ($clip['keyframe'] !== null) {
                $keyframes[$shotId] = ['approved' => [
                    'id' => (string) $clip['keyframe']['image_id'],
                    'selected_artifact_id' => (string) $clip['keyframe']['artifact_id'],
                    'artifacts' => [['id' => (string) $clip['keyframe']['artifact_id'], 'url' => (string) $preview['keyframes'][$shotId]['url']]],
                ]];
                $summary['with_keyframe']++;
            }

            $summary['running'] += ($cell['running'] ?? false) ? 1 : 0;
            $summary['done'] += ($cell['approved'] ?? false) && ($cell['selected_validity'] ?? null) === 'valid' ? 1 : 0;
            $summary['failed'] += ($cell['status'] ?? null) === 'failed' ? 1 : 0;
        }

        return ['scenes' => $scenes, 'clips' => $clips, 'keyframes' => $keyframes, 'clipModels' => $models, 'summary' => $summary, 'problem' => null, 'motoClips' => true];
    }

    /**
     * @return array<string, mixed> one row's state after a click or a poll, enough for the screen to fetch the row again when it has settled
     *
     * @throws RuntimeException when the shot of the render belongs to another project
     */
    public function state(string $projectId, VideoRender $render, string $reason): array
    {
        $shot = $render->shot()->first();
        $status = $render->execution_status?->value;
        $running = in_array($status, self::RUNNING, true);
        $state = match (true) {
            $status === 'succeeded' => 'succeeded',
            $running => 'running',
            $status === 'failed' => 'failed',
            default => 'idle',
        };

        return [
            'state' => $state,
            'reason' => $reason,
            'note' => match ($state) {
                'running' => $status === 'provider_unknown'
                    ? 'chưa rõ provider đã nhận hay chưa — không tự gửi lại'
                    : 'đang dựng · đã hỏi '.$render->provider_poll_count.' lần',
                'failed' => (string) ($render->failure_message ?? $reason),
                'succeeded' => 'Lượt này đã xong — xem rồi bấm Duyệt clip.',
                default => $reason,
            },
            'poll_url' => route('video-projects.scene-clip-poll', [$projectId, $render->id]),
            'file_url' => null,
            'meta' => $render->width && $render->height ? $render->width.'×'.$render->height.($render->duration_ms ? ' · '.round($render->duration_ms / 1000, 1).'s' : '') : '',
            'intent_version' => $shot?->intent_version,
            'selected_render_id' => $shot?->video_render_id,
            'current_is_selected' => false,
            'next_operation_id' => (string) Str::uuid(),
            'reload_row' => $state !== 'running',
        ];
    }

    /**
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException when the render is not a video of a moto clip of this project
     */
    public function ownedRender(string $projectId, string $renderId): VideoRender
    {
        return VideoRender::query()
            ->whereKey($renderId)
            ->where('render_kind', 'video')
            ->whereHas('shot', static fn ($shot) => $shot->where('shot_type', MotoClipRenderService::SHOT_TYPE)->whereHas('session', static fn ($session) => $session->where('project_id', $projectId)))
            ->firstOrFail();
    }

    /**
     * @param  Collection<int, VideoRender>  $renders  the results of the shot, oldest first
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed> the cell the screen draws: current says what is running, selected says what to look at; it is the approved result, or the newest result that fits as a preview, and only the first counts as approved
     */
    private function cell(string $projectId, string $shotId, VideoShot $shot, Collection $renders, array $preview): array
    {
        $current = $shot->current_render_id === null ? null : $renders->firstWhere('id', $shot->current_render_id);
        $options = [];
        $newestValid = null;

        foreach ($renders as $render) {
            if ($render->execution_status !== RenderStatus::SUCCEEDED) {
                continue;
            }

            $verdict = $this->verdict($shot, $render, $preview);
            $options[] = ['render_id' => (string) $render->id, 'attempt_no' => (int) $render->attempt_no, 'validity' => $verdict['status'], 'reasons' => $verdict['reasons']];
            $newestValid = $verdict['status'] === 'valid' ? $render : $newestValid;
        }

        $approved = $this->approvedOf($shot);
        $selected = $approved['render'] ?? $newestValid;
        $verdict = $selected === null ? null : $this->verdict($shot, $selected, $preview);
        $status = $current?->execution_status?->value;
        $running = in_array($status, self::RUNNING, true);

        return [
            'shot_id' => $shotId,
            'scene_status' => $shot->scene_status,
            'intent_version' => (int) $shot->intent_version,
            'render_id' => $current?->id,
            'status' => $status,
            'running' => $running,
            'note' => $status === 'provider_unknown' ? 'chưa rõ provider đã nhận hay chưa — không tự gửi lại' : null,
            'poll_count' => (int) ($current?->provider_poll_count ?? 0),
            'error' => $current?->failure_message,
            'duration_ms' => $current?->duration_ms,
            'width' => $current?->width,
            'height' => $current?->height,
            'artifact_path' => $current?->artifact_path,
            'poll_url' => $current === null ? null : route('video-projects.scene-clip-poll', [$projectId, $current->id]),
            'approve_url' => route('video-projects.scene-clip-select', [$projectId, $shotId]),
            'approved' => $approved !== null,
            'selected_render_id' => $selected?->id,
            'selected_file_url' => $selected === null ? null : route('video-projects.scene-clip-file', [$projectId, $selected->id]),
            'selected_status' => $selected?->execution_status?->value,
            'selected_duration_ms' => $selected?->duration_ms,
            'selected_width' => $selected?->width,
            'selected_height' => $selected?->height,
            'selected_artifact_path' => $selected?->artifact_path,
            'selected_validity' => $verdict['status'] ?? null,
            'selected_validity_reasons' => $verdict['reasons'] ?? [],
            'selected_fingerprint' => $verdict['fingerprint'] ?? null,
            'successful_options' => $options,
            'sent_prompt' => $current === null ? null : (string) $current->sent_prompt,
        ];
    }

    /**
     * @param  Collection<int, VideoShot>  $shots  every revision of the shot
     * @param  Collection<string, Collection<int, VideoRender>>  $renders  results by shot
     * @param  string  $stageId  the shot plan in force
     * @return list<array<string, mixed>> every attempt of the shot with what it did and cost, newest first: a result of another shot plan stays here and is never offered for approval
     */
    private function history(Collection $shots, Collection $renders, string $stageId): array
    {
        $lines = [];

        foreach ($shots as $shot) {
            foreach ($renders->get((string) $shot->id, collect()) as $render) {
                $lines[] = [
                    'at' => $render->created_at?->timestamp ?? 0,
                    'attempt_no' => (int) $render->attempt_no,
                    'revision' => (int) $shot->plan_revision,
                    'current_plan' => self::stageOf($shot) === $stageId,
                    'status' => $render->execution_status?->value,
                    'error' => $render->failure_message,
                    'model' => (string) $render->model,
                    'prompt' => (string) $render->sent_prompt,
                    'cost' => $render->cost_usd === null ? 'chưa có dữ liệu' : '$'.number_format((float) $render->cost_usd, 4),
                ];
            }
        }

        usort($lines, static fn (array $a, array $b): int => [$b['at'], $b['attempt_no']] <=> [$a['at'], $a['attempt_no']]);

        return $lines;
    }

    /** @return ?string why the file of a result cannot be handed on: file_missing when it is no longer on the disk, file_hash_mismatch when its bytes are not the ones recorded, null when they are */
    private function fileProblem(VideoRender $render): ?string
    {
        $disk = $this->storage->disk((string) config('video.veo.disk'));
        $path = (string) $render->artifact_path;

        if ($path === '' || ! $disk->exists($path)) {
            return 'file_missing';
        }

        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            return 'file_missing';
        }

        $hash = hash_init('sha256');
        hash_update_stream($hash, $stream);
        fclose($stream);

        return hash_equals((string) $render->primary_artifact_hash, hash_final($hash)) ? null : 'file_hash_mismatch';
    }

    /** @return ?array{render: VideoRender, decision: VideoReviewDecision} the result of the shot a person approved, with the decision taken on that very file; a decision never carries over to another file */
    private function approvedOf(VideoShot $shot): ?array
    {
        if ($shot->approved_at === null || $shot->video_render_id === null) {
            return null;
        }

        $render = VideoRender::query()->whereKey($shot->video_render_id)->where('shot_id', $shot->id)->first();
        $decision = $render === null ? null : VideoReviewDecision::query()
            ->where('entity_type', 'shot')
            ->where('entity_id', $shot->id)
            ->where('decision', 'approved')
            ->where('metadata_json->selected_render_id', (string) $render->id)
            ->orderByDesc('revision')
            ->first();

        if ($decision === null || ! hash_equals((string) $render->primary_artifact_hash, (string) ($decision->metadata_json['artifact_hash'] ?? '')) || ! hash_equals((string) $render->primary_artifact_hash, (string) $shot->approved_artifact_hash)) {
            return null;
        }

        return ['render' => $render, 'decision' => $decision];
    }

    /**
     * @param  ?string  $stageId  the shot plan the shots belong to, as their own metadata names it, or every plan when null
     * @param  list<string>  $shotIds
     * @return Collection<int, VideoShot> the shots of the flow with these ids; a shot is told apart by its project, its shot plan and its id, never by a revision number
     */
    private function shotsOf(string $projectId, ?string $stageId, array $shotIds, bool $lock = false): Collection
    {
        $query = VideoShot::query()
            ->whereIn('session_id', VideoSession::query()->where('project_id', $projectId)->select('id'))
            ->where('shot_type', MotoClipRenderService::SHOT_TYPE)
            ->where('kind', SceneShotFactory::KIND)
            ->whereIn('shot_code', $shotIds);

        if ($stageId !== null) {
            $query->where('metadata_json->moto_clip->shot_plan_stage_id', $stageId);
        }

        return ($lock ? $query->lockForUpdate() : $query)->get();
    }

    /** @return ?string the shot plan a shot of the flow was made for */
    private static function stageOf(VideoShot $shot): ?string
    {
        $stage = $shot->metadata_json['moto_clip']['shot_plan_stage_id'] ?? null;

        return is_string($stage) ? $stage : null;
    }
}
