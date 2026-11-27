<?php

namespace App\Services\Video;

use App\Models\VideoRender;
use App\Repositories\Interfaces\VideoArtifactRepositoryInterface;
use App\Repositories\Interfaces\VideoRenderRepositoryInterface;
use App\Repositories\Interfaces\VideoRenderSceneRepositoryInterface;
use App\Repositories\Interfaces\VideoShotRepositoryInterface;
use App\Services\VideoProjectService;
use App\Video\Media\VideoModelRegistry;
use App\Video\Moto\MotoClipPlanBuilder;
use App\Video\Render\Video\SceneClipDispatchService;
use App\Video\Render\Video\SceneShotFactory;
use App\Video\Render\Video\VideoRenderExecutionService;
use App\Video\Scene\FilmCutAuthor;
use App\Video\Scene\Services\ShotIntentService;
use App\Video\Scene\Services\ShotSelectionReconciler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class RenderVideoService
{
    /** @var string a render of the scene clip route on a project whose clips are cut by shot: the scene id is the code of a shot of the approved shot plan */
    public const SHOT_CLIP = 'shot_clip';

    /** @var string a render of the scene clip route for a scene of the film cut */
    public const FILM_SEGMENT = 'film_segment';

    /** @var string a render or a choice of the scene clip routes on the earlier flow of scenes */
    public const SCENE_CLIP = 'scene_clip';

    public function __construct(
        private readonly VideoProjectService $videoProjectService,
        private readonly MotoClipSourceService $sources,
        private readonly MotoClipSelectionService $selection,
        private readonly MotoClipRenderService $renders,
        private readonly ClipPromptWriterService $writer,
        private readonly MotoClipReviewFramesService $frames,
        private readonly SceneClipDispatchService $clips,
        private readonly VideoRenderExecutionService $clipExecution,
        private readonly SceneShotFactory $shots,
        private readonly ShotIntentService $shotIntents,
        private readonly ShotSelectionReconciler $shotSelections,
        private readonly VideoModelRegistry $registry,
        private readonly VideoRenderSceneRepositoryInterface $scenes,
        private readonly VideoArtifactRepositoryInterface $artifacts,
        private readonly VideoShotRepositoryInterface $videoShots,
        private readonly VideoRenderRepositoryInterface $videoRenders,
    ) {}

    /**
     * Du an cat clip theo shot: prompt, schema va quyet dinh duyet doc tu DB, theo workflow cua chu de trong
     * `video.clips.workflows`. Chu de nao co workflow o do thi chay luong nay tren chinh cac route clip; chua co thi chay luong cu.
     */
    public function usesShotClipFlow(string $projectId): bool
    {
        return $this->sources->supports($projectId);
    }

    /** @return bool whether the release of the project declares an execution policy for its clips, whose Render video screen works shot by shot; a policy it declares but this code cannot read still opens that screen, which then says why */
    public function usesClipExecution(string $projectId): bool
    {
        try {
            return $this->sources->execution($projectId) !== null;
        } catch (RuntimeException) {
            return true;
        }
    }

    /** @return array{view: string, data: array<string, mixed>} the Render video screen of the project and what it shows: shot by shot under an execution policy, the earlier screen of clips by shot, or the screen of the earlier flow of scenes */
    public function page(string $projectId): array
    {
        if ($this->usesShotClipFlow($projectId) && $this->usesClipExecution($projectId)) {
            return ['view' => 'video-projects.render-video', 'data' => ['id' => $projectId, 'motoClips' => $this->selection->panel($projectId)]];
        }

        if ($this->usesShotClipFlow($projectId)) {
            return ['view' => 'video-projects.show', 'data' => ['id' => $projectId] + $this->selection->screen($projectId)];
        }

        $plan = $this->videoProjectService->selectedScenePlan($projectId);
        $revision = (int) $plan['revision'];
        $scenes = $plan['scenes'] ?? [];
        $clips = $this->videoProjectService->sceneClipCells($projectId, $revision);
        $keyframes = $this->videoProjectService->sceneKeyframeCells($projectId, $revision);
        $segments = $this->videoProjectService->filmSegments($projectId, $revision);

        if ($segments !== []) {
            $scenes = array_map(static fn(array $segment): array => [
                'scene_id' => (string) $segment['owner']->id,
                'title' => $segment['title'],
                'video_prompt' => $segment['prompt'],
                'duration_seconds' => $segment['duration_seconds'],
                'film_cut' => true,
                'film_keyframes' => $segment['keyframes'],
                'film_references' => array_map(static fn(array $reference): array => [
                    'role' => (string) $reference['role'],
                    'label' => (string) $reference['label'],
                    'url' => route('video-artifacts.show', (string) $reference['artifact_id']),
                ], $segment['references']),
                'film_views' => $segment['views'],
                'film_blocked' => $segment['blocked'],
            ], $segments);
        }

        return ['view' => 'video-projects.show', 'data' => [
            'id' => $projectId,
            'scenes' => $scenes,
            'clips' => $clips,
            'keyframes' => $keyframes,
            'clipModels' => $segments === []
                ? $this->clipModels()
                : $this->clipModels(SceneClipDispatchService::FILM_TASK, (int) $segments[0]['duration_seconds']),
            'summary' => $this->clipSummary($scenes, $clips, $keyframes),
        ]];
    }

    /** @return array<string, mixed> the part of one shot on the Render video screen of a release with an execution policy, as MotoClipSelectionService::panel() reads it now */
    public function shotPanel(string $projectId, string $shotId): array
    {
        return $this->selection->panel($projectId, $shotId);
    }

    /**
     * @param  ?array{stage_id: string, content_hash: string}  $pageSource  the shot plan the page was drawn from (expected_source_stage, expected_source_hash)
     * @return ?string why that shot plan is not the approved one now, or why the approved one cannot be read; null when the page is still drawn from it
     */
    public function sourceProblem(string $projectId, ?array $pageSource): ?string
    {
        try {
            return MotoClipSourceService::sourceProblem($this->sources->source($projectId), $pageSource);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    }

    /**
     * @param  string  $action  under an execution policy: settings, render, approve, reject, revoke, poll, prompt or prompt-approve; on the earlier screen: input, prompt or prompt-approve
     * @param  array<string, mixed>  $data  the validated form, each field as it was sent: a field the form did not send is absent, one it sent empty is null
     * @param  ?string  $actor  the id of the signed-in admin, null when nobody is signed in
     * @param  ?array{id: string, name: string}  $requester  who asked, for a prompt run
     * @param  bool  $execution  whether the action is one of the Render video screen of a release with an execution policy, shot by shot
     * @param  ?array{stage_id: string, content_hash: string}  $pageSource  the shot plan the page was drawn from, null when the form names none
     * @param  array{crop_at_ms: bool, images_sent: bool}  $sent  whether the crop form was sent (crop_at_ms filled) and whether the settings form showed the reference images (images_sent true)
     * @param  bool  $withShot  whether the answer carries the part of the shot (the page script sent the form)
     * @return array{ok: bool, status: int, message: string, panel: ?array<string, mixed>} the notice of the action; under an execution policy also the status it is answered with (409 for a save made from an older revision of the settings) and the part of the shot read again after it, null when the answer carries none: the page script did not ask for it, or the page was drawn from another approved shot plan, which takes the notice alone
     */
    public function action(string $projectId, string $shotId, string $action, array $data, ?string $actor, ?array $requester, bool $execution, ?array $pageSource, array $sent, bool $withShot): array
    {
        if (! $execution) {
            return $this->earlierAction($projectId, $shotId, $action, $data, $actor, $requester);
        }

        try {
            $message = match ($action) {
                'settings' => (function () use ($projectId, $shotId, $data, $actor, $pageSource, $sent): string {
                    if (($changed = MotoClipSourceService::sourceProblem($this->sources->source($projectId), $pageSource)) !== null) {
                        throw new RuntimeException($changed, MotoClipSourceService::CONFLICT);
                    }

                    $saved = $this->sources->saveSettings($projectId, $shotId, $this->clipSettingsChanges($this->sources->settings($projectId, $shotId), $data, $sent), (int) $data['expected_revision'], $actor, $pageSource);

                    return 'Đã lưu thiết lập clip của '.$shotId.' (revision '.$saved['revision'].') — chưa viết prompt, chưa render; clip đang dùng không đổi.';
                })(),
                'render' => (function () use ($projectId, $shotId, $data, $actor): string {
                    try {
                        [$render, $ok, $reason] = $this->renders->render($projectId, $shotId, [
                            'model_id' => (string) $data['model_id'],
                            'resolution' => (string) $data['resolution'],
                            'aspect_ratio' => (string) $data['aspect_ratio'],
                            'duration_seconds' => (int) $data['duration_seconds'],
                        ], (int) $data['expected_intent_version'], (string) $data['operation_id'], (string) $data['expected_clip_hash'], (int) $data['expected_settings'], $actor);
                    } catch (RuntimeException $e) {
                        throw new RuntimeException(match ($e->getMessage()) {
                            'shot_dispatch_conflict' => 'Clip đã thay đổi ở thao tác khác. Tải lại trang trước khi render.',
                            'shot_dispatch_operation_conflict' => 'Mã thao tác render đã được dùng cho một yêu cầu khác.',
                            'shot_dispatch_replay_missing' => 'Không còn tìm thấy lượt render của thao tác trước.',
                            default => $e->getMessage(),
                        }, 0, $e);
                    }

                    $local = $render->provider === MotoClipPlanBuilder::LOCAL;

                    return match (true) {
                        $reason === MotoClipRenderService::REPLAYED => 'Thao tác render này của '.$shotId.' đã được ghi trước đó — không gửi lại, không tạo lại. Lượt đó hiện: '.MotoClipSelectionService::technical($render)['label'].'; clip đang dùng không đổi.',
                        $reason === 'queued' =>'Đã xếp hàng tạo clip Dynamic Crop của '.$shotId.' trên máy này — trang tự cập nhật khi xong; clip đang dùng không đổi.',
                        $local && $ok => 'Đã tạo clip Dynamic Crop của '.$shotId.' trên máy này — xem, kiểm dải ảnh rồi duyệt; clip đang dùng không đổi.',
                        $local => throw new RuntimeException('Tạo clip trên máy này không thành công ('.$reason.'): '.($render->failure_message ?? 'xem lịch sử lượt render.')),
                        $ok => 'Đã gửi render '.$shotId.' — kết quả là một ứng viên mới, clip đang dùng không đổi; trang tự cập nhật khi xong.',
                        default => throw new RuntimeException('Render: '.$reason.' — '.($render->failure_message ?? 'xem lịch sử lượt render.')),
                    };
                })(),
                'approve' => (function () use ($projectId, $shotId, $data, $actor): string {
                    [, $result] = $this->selection->approve($projectId, $shotId, (string) $data['render_id'], (int) $data['expected_intent_version'], (string) $data['operation_id'], $actor, $data['review_key'] ?? null);

                    return $result === 'replayed' ? 'Thao tác duyệt này đã được ghi trước đó.' : 'Đã kiểm và duyệt clip của '.$shotId.' — đây là clip bàn giao cho Phase 11.';
                })(),
                'reject' => (function () use ($projectId, $shotId, $data, $actor): string {
                    $marks = trim((string) ($data['marks'] ?? '')) === '' ? [] : preg_split('/[\s,;]+/', trim((string) $data['marks']));

                    if (array_filter($marks, static fn (string $mark): bool => preg_match('/^\d{1,6}$/', $mark) !== 1) !== []) {
                        throw new RuntimeException('Mốc thời gian phải là số mili giây, cách nhau bằng dấu phẩy.');
                    }

                    [, $result] = $this->selection->reject($projectId, $shotId, (string) $data['render_id'], (string) $data['operation_id'], $actor, array_values((array) $data['faults']), $data['note'] ?? null, array_map('intval', $marks), $data['review_key'] ?? null);

                    return $result === 'replayed' ? 'Thao tác này đã được ghi trước đó.' : 'Đã ghi Không đạt cho lượt này của '.$shotId.' — clip đang dùng không đổi, không render lại.';
                })(),
                'revoke' => (function () use ($projectId, $shotId, $data, $actor): string {
                    [, $result] = $this->selection->revoke($projectId, $shotId, (string) $data['render_id'], (int) $data['expected_intent_version'], (string) $data['operation_id'], $actor, $data['note'] ?? null);

                    return $result === 'replayed' ? 'Thao tác này đã được ghi trước đó.' : 'Đã thu hồi clip đang dùng của '.$shotId.' — shot chưa có clip bàn giao cho Phase 11 tới khi duyệt một clip khác.';
                })(),
                'poll' => (function () use ($projectId, $data): string {
                    $owned = $this->selection->ownedRender($projectId, (string) $data['render_id']);

                    if ($owned->provider === MotoClipPlanBuilder::LOCAL) {
                        return 'Lượt này chạy trên máy này — không có provider để hỏi.';
                    }

                    [, $reason] = $this->renders->track((string) $owned->id);

                    return 'Đã hỏi provider một lần: '.$reason.' — không gửi lại request nào.';
                })(),
                'prompt' => (function () use ($projectId, $shotId, $data, $requester): string {
                    [$stage, $reason] = $this->writer->run($projectId, $shotId, (bool) ($data['regenerate'] ?? false), $data['operation_id'], $requester);

                    return match (true) {
                        $stage === null => throw new RuntimeException($reason),
                        $reason === 'queued', $reason === 'requeued' => 'Đã xếp hàng viết prompt video cho '.$shotId.' — máy chạy nền gọi AI.',
                        $reason === 'cached' => 'Đã có lượt viết prompt video cho đúng nguồn này — không gọi AI thêm. Bấm Viết lại nếu cần bản khác.',
                        default => 'Đã viết prompt video cho '.$shotId.'. Đọc rồi duyệt trước khi render.',
                    };
                })(),
                'prompt-approve' => (function () use ($projectId, $shotId, $data, $actor): string {
                    $this->writer->approve($projectId, $data['stage_id'], $actor, $shotId);

                    return 'Đã duyệt prompt video cho '.$shotId.' — chưa render.';
                })(),
            };

            return ['ok' => true, 'status' => 200, 'message' => $message, 'panel' => $withShot ? $this->selection->panel($projectId, $shotId) : null];
        } catch (RuntimeException $e) {
            $status = $action === 'settings' && $e->getCode() === MotoClipSourceService::CONFLICT ? 409 : 200;
            $notice = match (true) {
                ! $e instanceof ModelNotFoundException => $e->getMessage(),
                $e->getModel() === VideoRender::class => 'Không tìm thấy lượt render này trong dự án.',
                default => 'Không tìm thấy dữ liệu của thao tác này trong dự án — tải lại trang.',
            };
            $otherPlan = $status === 409 && $this->sourceProblem($projectId, $pageSource) !== null;

            return ['ok' => false, 'status' => $status, 'message' => $notice, 'panel' => $withShot && ! $otherPlan ? $this->selection->panel($projectId, $shotId) : null];
        }
    }

    /**
     * @param  array<string, mixed>  $data  the validated form of input, prompt or prompt-approve
     * @param  ?array{id: string, name: string}  $requester
     * @return array{ok: bool, status: int, message: string, panel: null} an action of the earlier Render video screen: how a shot is rendered (input), a prompt run or its approval
     */
    private function earlierAction(string $projectId, string $shotId, string $action, array $data, ?string $actor, ?array $requester): array
    {
        $answer = static fn (bool $ok, string $message): array => ['ok' => $ok, 'status' => 200, 'message' => $message, 'panel' => null];

        try {
            if ($action === 'input' && $this->usesClipExecution($projectId)) {
                $this->sources->saveInput($projectId, $shotId, $data['mode'], (array) ($data['include'] ?? []), isset($data['expected_revision']) ? (int) $data['expected_revision'] : null,
                    isset($data['expected_source_stage'], $data['expected_source_hash']) ? ['stage_id' => (string) $data['expected_source_stage'], 'content_hash' => (string) $data['expected_source_hash']] : null);

                return $answer(true, 'Đã lưu cách render của '.$shotId.': '.($data['mode'] === 'references' ? 'từ ảnh tham chiếu' : 'từ keyframe').' — chưa viết prompt, chưa render.');
            }

            if ($action === 'input') {
                DB::transaction(fn () => $this->sources->saveInput($projectId, $shotId, $data['mode'], (array) ($data['include'] ?? [])));

                return $answer(true,'Đã lưu cách render của '.$shotId.': '.($data['mode'] === 'references' ? 'từ ảnh tham chiếu' : 'từ keyframe').' — chưa viết prompt, chưa render.');
            }

            if ($action === 'prompt-approve') {
                $this->writer->approve($projectId, $data['stage_id'], $actor, $shotId);

                return $answer(true, 'Đã duyệt prompt video cho '.$shotId.' — chưa render.');
            }

            [$stage, $reason] = $this->writer->run($projectId, $shotId, (bool) ($data['regenerate'] ?? false), $data['operation_id'], $requester);

            return $answer(true, match (true) {
                $stage === null => throw new RuntimeException($reason),
                $reason === 'queued', $reason === 'requeued' => 'Đã xếp hàng viết prompt video cho '.$shotId.' — máy chạy nền gọi AI.',
                $reason === 'cached' => 'Đã có lượt viết prompt video cho đúng nguồn này — không gọi AI thêm. Bấm Viết lại nếu cần bản khác.',
                default => 'Đã viết prompt video cho '.$shotId.'. Đọc rồi duyệt trước khi render.',
            });
        } catch (RuntimeException $e) {
            return $answer(false, $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $current  the settings of the shot as stored now
     * @param  array<string, mixed>  $data  the validated form, each field as it was sent
     * @param  array{crop_at_ms: bool, images_sent: bool}  $sent  whether the crop form was sent (crop_at_ms filled) and whether the settings form showed the reference images (images_sent true)
     * @return array<string, mixed> the fields of MotoClipSourceService::saveSettings() the form changes: the crop path when the crop form was sent (its first window the approved one, its end the window and mark a person set; a protected region saved before is kept), else the choices of the settings form, where a new way of moving or input takes the model, resolution and audio rule the policy gives, and a new model the resolution it gives unless a resolution was chosen with it; the reference images only when the form showed them
     */
    private function clipSettingsChanges(array $current, array $data, array $sent): array
    {
        if ($sent['crop_at_ms']) {
            $point = static fn (string $at, string $suffix): array => ['at_ms' => (int) $at, 'x' => (int) ($data['crop_x'.$suffix] ?? -1), 'y' => (int) ($data['crop_y'.$suffix] ?? -1), 'width' => (int) ($data['crop_w'.$suffix] ?? 0), 'height' => (int) ($data['crop_h'.$suffix] ?? 0)];

            return ['crop_plan' => ['path' => [$point('0', '0'), $point((string) $data['crop_at_ms'], '1')]] + (is_array($current['crop_plan']['protected'] ?? null) ? ['protected' => $current['crop_plan']['protected']] : [])];
        }

        $value = static fn (string $key): ?string => isset($data[$key]) && $data[$key] !== '' ? (string) $data[$key] : null;
        $changes = [
            'motion_method' => $value('motion_method') ?? (string) $current['motion_method'],
            'input_mode' => $value('input_mode') ?? (string) $current['input_mode'],
            'model' => $value('model'),
            'resolution' => $value('resolution'),
            'audio_policy' => $value('audio_policy'),
        ];

        if ($changes['motion_method'] !== $current['motion_method'] || $changes['input_mode'] !== $current['input_mode']) {
            $changes = array_replace($changes, ['model' => null, 'resolution' => null, 'audio_policy' => $changes['motion_method'] !== $current['motion_method'] ? null : $changes['audio_policy']]);
        } elseif ($changes['model'] !== $current['model'] && $changes['resolution'] === $current['resolution']) {
            $changes['resolution'] = null;
        }

        if ($sent['images_sent']) {
            $changes['images'] = array_values(array_unique(array_map('strval', (array) ($data['images'] ?? []))));
        }

        return $changes;
    }

    /**
     * @return ?array{strip: ?array<string, mixed>, error: ?string} the strip of review stills of one render of a shot of this project, or why the render has none; null when the project renders no clips by shot or the render is not of that shot
     *
     * @throws ModelNotFoundException when the render is not a clip of this project
     */
    public function review(string $projectId, string $shotId, string $renderId): ?array
    {
        if (! $this->usesShotClipFlow($projectId)) {
            return null;
        }

        $owned = $this->selection->ownedRender($projectId, $renderId);

        if ((string) $this->videoShots->findShotCodeOfRender($owned) !== $shotId) {
            return null;
        }

        try {
            $strip = $this->frames->strip($projectId, (string) $owned->id);
            $error = null;
        } catch (RuntimeException $e) {
            $strip = null;
            $error = $e->getMessage();
        }

        return ['strip' => $strip, 'error' => $error];
    }

    /** @return ?array<string, mixed> one still of a strip of a render of this project (disk, path, mime), as MotoClipReviewFramesService::still() gives it; null when the project renders no clips by shot, or the render, the strip or the still is not one of this project */
    public function still(string $projectId, string $renderId, string $key, int $index): ?array
    {
        if (! $this->usesShotClipFlow($projectId)) {
            return null;
        }

        try {
            return $this->frames->still($projectId, $renderId, $key, $index);
        } catch (ModelNotFoundException|RuntimeException) {
            return null;
        }
    }

    /**
     * @return array{disk: string, path: string} where the file of a clip render of this project is kept: a moto clip of this project on a project whose clips are cut by shot, else a video render of a shot or a session of this project
     *
     * @throws ModelNotFoundException when the render is not one of this project
     */
    public function clipFile(string $projectId, string $renderId): array
    {
        if ($this->usesShotClipFlow($projectId)) {
            $owned = $this->selection->ownedRender($projectId, $renderId);

            return ['disk' => (string) config('video.veo.disk'), 'path' => (string) $owned->artifact_path];
        }

        $owned = $this->videoRenders->findOwnedLegacyVideoRenderOrFail($projectId, $renderId);

        return ['disk' => (string) config('video.veo.disk'), 'path' => (string) $owned->artifact_path];
    }

    /** @return bool whether the scene of the project, on the earlier flow of scenes, is a scene of the film cut, rendered as a film segment */
    public function rendersFilmSegment(string $projectId, string $sceneId): bool
    {
        $row = $this->scenes->findSceneForProject($projectId, $sceneId);

        return FilmCutAuthor::isFilmShape($row?->state_json['film_cut_shape'] ?? null);
    }

    /**
     * @param  string  $flow  SHOT_CLIP, FILM_SEGMENT or SCENE_CLIP
     * @param  array<string, mixed>  $data  the validated form
     * @param  ?string  $actor  who asked, for a film segment
     * @param  bool  $wantsJson  whether the answer is read by the page script (expectsJson), which then takes the state of the render
     * @return array{refused: ?string, state: ?array<string, mixed>, ok: bool, reason: string} refused: why nothing was sent, as the render path said it (an exception message, or the reason a film segment gave), null when a render was made; state: the state of the render for the page script (always for a shot clip), null when the form takes the outcome of its submit (ok, reason)
     */
    public function sceneRender(string $projectId, string $sceneId, string $flow, array $data, ?string $actor, bool $wantsJson): array
    {
        if ($flow === self::SHOT_CLIP) {
            try {
                [$render, , $reason] = $this->renders->render($projectId, $sceneId, [
                    'model_id' => (string) $data['model_id'],
                    'resolution' => (string) $data['resolution'],
                    'aspect_ratio' => (string) $data['aspect_ratio'],
                    'duration_seconds' => (int) $data['duration_seconds'],
                ], (int) $data['expected_intent_version'], (string) $data['operation_id'], (string) $data['expected_clip_hash']);
            } catch (Throwable $e) {
                return ['refused' => $e->getMessage(), 'state' => null, 'ok' => false, 'reason' => ''];
            }

            return ['refused' => null, 'state' => $this->selection->state($projectId, $render, $reason), 'ok' => true, 'reason' => $reason];
        }

        if ($flow === self::FILM_SEGMENT) {
            [$render, $reason] = $this->videoProjectService->renderFilmSegment(
                $projectId,
                $actor,
                $sceneId,
                Arr::only($data, ['model_id', 'resolution', 'aspect_ratio', 'duration_seconds']),
                (int) $data['expected_intent_version'],
                (string) $data['operation_id'],
            );

            if ($render === null) {
                return ['refused' => $reason, 'state' => null, 'ok' => false, 'reason' => ''];
            }

            return $this->submitted($projectId, $render, $wantsJson);
        }

        try {
            $plan = $this->videoProjectService->selectedScenePlan($projectId);
            $revision = (int) $plan['revision'];

            $scene = $this->scenes->findSceneForRevision($projectId, $sceneId, $revision);

            if ($scene === null) {
                throw new RuntimeException('Scene khong thuoc ban ke hoach dang hien cua du an nay.');
            }

            $row = collect($plan['scenes'] ?? [])->firstWhere('scene_id', $sceneId);
            $approved = $this->videoProjectService->sceneKeyframeCells($projectId, $revision)[$sceneId]['approved'] ?? null;

            if ($row === null) {
                throw new RuntimeException('Scene nay khong nam trong ban ke hoach dang hien.');
            }

            if ($approved === null) {
                throw new RuntimeException('Scene chua co anh duyet — duyet o man hinh Scenes truoc.');
            }

            $source = $this->artifacts->findArtifactOfImage($approved['selected_artifact_id'], $approved['id']);

            if ($source === null) {
                throw new RuntimeException('Khong tim thay file anh da duyet cua scene nay.');
            }

            $review = $this->videoProjectService->clipSourcesNeedReview($scene);

            if ($review !== null) {
                throw new RuntimeException($review.' — render và duyệt lại keyframe trước khi render clip.');
            }

            $shot = $this->shots->forScene($scene, $row);
            $render = $this->clips->create(
                $shot,
                $source,
                (string) $data['model_id'],
                Arr::except($data, ['model_id', 'expected_intent_version', 'operation_id']),
                (int) $data['expected_intent_version'],
                (string) $data['operation_id'],
            );
        } catch (Throwable $e) {
            return ['refused' => $e->getMessage(), 'state' => null, 'ok' => false, 'reason' => ''];
        }

        return $this->submitted($projectId, $render, $wantsJson);
    }

    /** @return array{refused: null, state: ?array<string, mixed>, ok: bool, reason: string} a render of the earlier flow made: it is submitted now, and the page script takes its state */
    private function submitted(string $projectId, VideoRender $render, bool $wantsJson): array
    {
        [$ok, $reason] = $this->clipExecution->submit($render->id);

        return ['refused' => null, 'state' => $wantsJson ? $this->clipState($projectId, $this->videoRenders->refreshRender($render), $reason) : null, 'ok' => $ok, 'reason' => $reason];
    }

    /**
     * @param  string  $flow  SHOT_CLIP: the result is approved for the final step; SCENE_CLIP: it is chosen for the timeline
     * @param  array{render_id: string, expected_intent_version: int|string, operation_id: string}  $data  the validated form
     * @param  ?string  $actor  who decides
     * @return array{ok: bool, message: string} the notice of the choice
     *
     * @throws ModelNotFoundException on the earlier flow, when the shot or the render is not one of this project
     */
    public function sceneSelect(string $projectId, string $shotId, string $flow, array $data, ?string $actor): array
    {
        if ($flow === self::SHOT_CLIP) {
            try {
                [, $reason] = $this->selection->approve($projectId, $shotId, (string) $data['render_id'], (int) $data['expected_intent_version'], (string) $data['operation_id'], $actor);
            } catch (RuntimeException $e) {
                return ['ok' => false, 'message' => $e->getMessage()];
            }

            return ['ok' => true, 'message' => $reason === 'replayed' ? 'Thao tác duyệt này đã được ghi trước đó.' : 'Đã duyệt clip của '.$shotId.'.'];
        }

        $shot = $this->videoShots->findSceneShotOfProjectOrFail($projectId, $shotId);
        $render = $this->videoRenders->findProductionRenderOfShotOrFail($data['render_id'], $shot->id);
        [$selected, $reason] = $this->shotIntents->manualSelect(
            $shot,
            $render,
            (int) $data['expected_intent_version'],
            $data['operation_id'],
            $actor,
        );

        if ($selected === null) {
            return ['ok' => false, 'message' => match ($reason) {
                'shot_selection_conflict' => 'Clip đã thay đổi ở một thao tác khác. Tải lại trang trước khi chọn.',
                'shot_selection_incompatible' => 'Clip này không còn khớp đầu vào hiện tại của shot.',
                'shot_selection_operation_conflict' => 'Mã thao tác đã được dùng cho một lựa chọn khác.',
                default => 'Không thể chọn clip này.',
            }];
        }

        return ['ok' => true, 'message' => $reason === 'replayed'
            ? 'Thao tác chọn clip này đã được ghi trước đó; lựa chọn hiện tại không bị thay đổi.'
            : 'Đã chọn clip dùng cho timeline.'];
    }

    /**
     * @param  bool  $wantsJson  whether the answer is read by the page script (expectsJson)
     * @return array{state: ?array<string, mixed>, ok: bool, reason: string} one round of following a clip render of this project with its provider: the state of the render for the page script (always for a moto clip), else the outcome of the poll for the page
     *
     * @throws ModelNotFoundException when the render is not one of this project
     */
    public function scenePoll(string $projectId, string $renderId, bool $wantsJson): array
    {
        if ($this->usesShotClipFlow($projectId)) {
            $owned = $this->selection->ownedRender($projectId, $renderId);
            [, $reason] = $this->renders->track((string) $owned->id);

            return ['state' => $this->selection->state($projectId, $this->videoRenders->refreshRender($owned), $reason), 'ok' => true, 'reason' => $reason];
        }

        // Clip thuoc ve shot (CHECK video_renders_one_owner cho dung mot chu), nen
        // duong so huu di qua shot. Nhanh `session` giu cho hang cu.
        $owned = $this->videoRenders->findOwnedLegacyVideoRenderOrFail($projectId, $renderId);

        [$ok, $reason] = $this->clipExecution->poll($owned->id);

        return ['state' => $wantsJson ? $this->clipState($projectId, $this->videoRenders->refreshRender($owned), $reason) : null, 'ok' => $ok, 'reason' => $reason];
    }

    /**
     * @param  list<array<string, mixed>>  $scenes
     * @param  array<string, array<string, mixed>>  $clips
     * @param  array<string, array<string, mixed>>  $keyframes
     * @return array{with_keyframe: int, running: int, done: int, failed: int}
     */
    private function clipSummary(array $scenes, array $clips, array $keyframes): array
    {
        $summary = ['with_keyframe' => 0, 'running' => 0, 'done' => 0, 'failed' => 0];

        foreach ($scenes as $scene) {
            $key = (string) ($scene['scene_id'] ?? '');

            if (array_key_exists('film_blocked', $scene) ? $scene['film_blocked'] === null : ($keyframes[$key]['approved'] ?? null) !== null) {
                $summary['with_keyframe']++;
            }

            $status = $clips[$key]['status'] ?? null;

            $summary['running'] += in_array($status, ['submitting', 'submitted', 'provider_running', 'polling'], true) ? 1 : 0;
            $summary['done'] += ($clips[$key]['selected_status'] ?? null) === 'succeeded'
                && ($clips[$key]['selected_validity'] ?? null) === 'valid' ? 1 : 0;
            $summary['failed'] += $status === 'failed' ? 1 : 0;
        }

        return $summary;
    }

    /**
     * Mot dong trang thai du de man hinh ve lai dung o do, khong phai tai lai trang.
     *
     * @return array<string, mixed>
     */
    private function clipState(string $id, VideoRender $render, string $reason): array
    {
        $shot = $this->videoShots->findShotOfRender($render);
        $currentIsSelected = $shot !== null
            && (string) $shot->video_render_id === (string) $render->id;
        $currentSelectionValid = $currentIsSelected
            && $this->shotSelections->verdict($shot, $render)['status'] === 'valid';
        $status = $render->execution_status?->value;
        $running = in_array($status, ['submitting', 'submitted', 'provider_running', 'polling'], true);

        $state = match (true) {
            $status === 'succeeded' => 'succeeded',
            $running => 'running',
            $status === 'failed' => 'failed',
            default => 'idle',
        };

        $note = match ($state) {
            'running' => 'đang dựng · đã hỏi ' . $render->provider_poll_count . ' lần',
            'failed' => (string) ($render->failure_message ?? $reason)
                . ($shot?->video_render_id !== null ? ' Clip đã chọn vẫn được giữ.' : ''),
            'succeeded' => match (true) {
                $currentSelectionValid => '',
                $shot?->video_render_id !== null => 'Lượt mới đã xong; clip đã chọn vẫn được giữ.',
                default => 'Lượt render đã xong nhưng không đủ điều kiện tự chọn.',
            },
            default => $reason,
        };

        return [
            'state' => $state,
            'reason' => $reason,
            'note' => $note,
            'poll_url' => route('video-projects.scene-clip-poll', [$id, $render->id]),
            'file_url' => $state === 'succeeded' && $currentSelectionValid
                ? route('video-projects.scene-clip-file', [$id, $render->id])
                : null,
            'meta' => $render->width && $render->height
                ? $render->width . '×' . $render->height
                . ($render->duration_ms ? ' · ' . round($render->duration_ms / 1000, 1) . 's' : '')
                : '',
            'intent_version' => $shot?->intent_version,
            'selected_render_id' => $shot?->video_render_id,
            'current_is_selected' => $currentSelectionValid,
            'next_operation_id' => (string) Str::uuid(),
        ];
    }

    /**
     * Danh sach model clip duoc phep chon. Rong nghia la chua model nao duoc chung
     * minh bang `video:list-gemini-models` — man hinh phai noi that, khong duoc bay
     * ra mot nut bam vao thi hong.
     *
     * @return list<array{id: string, label: string, default: bool}>
     */
    private function clipModels(string $task = SceneClipDispatchService::TASK, ?int $segmentSeconds = null): array
    {
        $registry = $this->registry;

        if (! $registry->available($task)) {
            return [];
        }

        try {
            $entries = $registry->forTask($task);
        } catch (InvalidArgumentException $e) {
            Log::warning('Registry clip hong: ' . $e->getMessage());

            return [];
        }

        return array_map(static fn(array $entry): array => [
            'id' => (string) $entry['id'],
            'label' => (string) $entry['label'],
            'default' => $entry['default'] === true,
            'controls' => $segmentSeconds === null
                ? $entry['controls']
                : $entry['controls'] + ['durations' => [$segmentSeconds], 'default_duration' => $segmentSeconds],
        ], $entries);
    }
}
