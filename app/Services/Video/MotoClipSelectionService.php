<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Models\VideoRender;
use App\Models\VideoReviewDecision;
use App\Models\VideoSessionEvent;
use App\Models\VideoShot;
use App\Repositories\Interfaces\VideoArtifactRepositoryInterface;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Repositories\Interfaces\VideoRenderRepositoryInterface;
use App\Repositories\Interfaces\VideoReviewDecisionRepositoryInterface;
use App\Repositories\Interfaces\VideoSessionEventRepositoryInterface;
use App\Repositories\Interfaces\VideoShotRepositoryInterface;
use App\Video\Moto\MotoClipPlanBuilder;
use App\Video\Moto\MotoContract;
use App\Video\Render\Enums\RenderStatus;
use App\Video\Render\Video\SceneClipDispatchService;
use App\Video\Render\Video\VideoRenderExecutionService;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * What a clip of the shot flow is worth against the plan now: which results fit, which one a person approved, and the manifest Phase 11 cuts from.
 * A result keeps the source it was made from; a preview is never an approval, and a change of source never approves or re-renders anything.
 * A render of an execution plan (request moto-clip-request-v5) is judged by what its own request pinned, never by the settings a person is preparing for the next one; it only becomes the clip in use when a person approves it, and stops being it when a person approves another or revokes it.
 */
final class MotoClipSelectionService
{
    public const MANIFEST_FORMAT = 'moto-clip-manifest-v1';

    /** @var string how the hash of the manifest is taken: a change of what it covers is a new version, so a hash taken before is never read as one taken now */
    public const MANIFEST_HASH_VERSION = 'moto-clip-manifest-hash-v1';

    /** @var array<string, string> the four groups a person accepts together with one click, and names one by one when a clip does not pass (spec §13) */
    public const REVIEW_GROUPS = [
        'action' => 'Hành động, kết quả, thời điểm',
        'identity' => 'Nhận diện, hình học, thiết kế',
        'motion' => 'Chuyển động, vật lý, ánh sáng, continuity',
        'frame' => 'Bố cục 9:16 và chủ thể bắt buộc',
    ];

    /** @var int the longest note a decision keeps */
    public const NOTE_MAX_CHARS = 1000;

    /** @var int the most time marks a rejection keeps */
    public const MARKS_MAX = 12;

    /** @var array<string, string> what each reason a result does not fit means, as the screen says it */
    public const REASONS = [
        'render_not_usable' => 'lượt này chưa xong hoặc không thuộc shot',
        'artifact_unverifiable' => 'không có file đã kiểm',
        'media_check_failed' => 'file không đạt kiểm kỹ thuật',
        'request_snapshot_missing' => 'không đọc được yêu cầu đã ghim',
        'shot_plan_changed' => 'kế hoạch shot đã đổi',
        'shot_not_in_plan' => 'shot không còn trong kế hoạch',
        'keyframe_changed' => 'bộ keyframe đã duyệt của shot đã đổi hoặc bị thu hồi',
        'keyframe_unapproved' => 'bộ keyframe không còn quyết định duyệt',
        'input_changed' => 'ảnh đầu vào đã đổi',
        'references_changed' => 'ảnh tham chiếu đã ghim không còn trong bộ ảnh của shot hoặc đã đổi file',
        'prompt_withdrawn' => 'prompt video đã ghim không còn hoặc không còn quyết định duyệt',
        'used_outside_file' => 'phần phim giữ nằm ngoài file',
        'file_missing' => 'file clip không còn trên đĩa',
        'file_hash_mismatch' => 'file clip khác hash đã ghi',
    ];

    private const RUNNING = ['claimed', 'preparing', 'submitting', 'submitted', 'provider_running', 'polling', 'artifact_writing', 'checkpointing', 'provider_unknown'];

    public function __construct(
        private readonly MotoClipPlanService $plans,
        private readonly FilesystemFactory $storage,
        private readonly ClipPromptWriterService $writer,
        private readonly MotoClipReviewFramesService $frames,
        private readonly MotoClipRenderService $clipRender,
        private readonly VideoProjectRepositoryInterface $projects,
        private readonly VideoShotRepositoryInterface $shots,
        private readonly VideoRenderRepositoryInterface $renders,
        private readonly VideoSessionEventRepositoryInterface $events,
        private readonly VideoReviewDecisionRepositoryInterface $decisions,
        private readonly VideoArtifactRepositoryInterface $artifacts,
    ) {}

    /**
     * @param  array<string, mixed>  $preview  the plan the sources build now
     * @return array{status: 'valid'|'stale', reasons: list<string>, fingerprint: string} whether this result fits the plan now, judged by what its request froze; a new revision of the template does not make it stale, since the prompt it was sent with is its own. A render of an execution plan is judged by its own pins alone: its shot plan, the keyframe set, reference images and approved prompt it was made from, which must all still stand, and its checked file; what a person now prepares for the shot never makes it stale
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
        } elseif (self::pinned($moto)) {
            $file = $render->artifact_manifest['artifacts'][0] ?? [];
            $reasons = [...$reasons, ...self::pinnedProblems($moto, [
                'duration_ms' => (int) $render->duration_ms,
                'media_check' => ($file['media_check']['passed'] ?? null) === true,
                'proof' => (string) $render->proof_method,
            ], $this->sourcesNow((string) $shot->shot_code, $preview, $moto))];
        } else {
            if (($moto['source']['token']['stage_id'] ?? null) !== $token['stage_id'] || ($moto['source']['token']['content_hash'] ?? null) !== $token['content_hash']) {
                $reasons[] = 'shot_plan_changed';
            }

            if ($clip === null) {
                $reasons[] = 'shot_not_in_plan';
            } elseif (($moto['keyframe']['artifact_id'] ?? null) !== ($clip['keyframe']['artifact_id'] ?? null) || ($moto['keyframe']['sha256'] ?? null) !== ($clip['keyframe']['sha256'] ?? null)) {
                $reasons[] = 'keyframe_changed';
            } elseif (($moto['input']['mode'] ?? 'keyframe') !== $clip['input_mode']
                || array_map(static fn (array $image): array => [$image['artifact_id'], $image['sha256']], (array) ($moto['input']['images'] ?? [])) !== array_map(static fn (array $image): array => [$image['artifact_id'], $image['sha256']], $clip['images'])) {
                $reasons[] = 'input_changed';
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
     * @param  mixed  $moto  the moto_clip block of a frozen request
     * @return bool whether the request is one of an execution plan (MotoClipRenderService::REQUEST_VERSION_V3), which is judged by its own pins and decided by render
     */
    public static function pinned(mixed $moto): bool
    {
        return is_array($moto) && ($moto['version'] ?? null) === MotoClipRenderService::REQUEST_VERSION_V3;
    }

    /**
     * @param  array<string, mixed>  $moto  the moto_clip block a render of an execution plan froze
     * @param  array{duration_ms: int, media_check: bool, proof: string}  $file  what the render recorded of its file: its length, whether the media check it froze passed, and how it was proven
     * @param  array{token: array<string, mixed>, keyframe: ?array<string, mixed>, references: array<string, string>, prompt: bool}  $now  the sources as they stand now: the approved shot plan, keyframes() of the shot, the sha256 of every reference image the shot still offers whole (by artifact id), and whether the prompt run the request pinned still holds its approval
     * @return list<string> why the render no longer stands on what it pinned: a failed file check, another shot plan, a keyframe set that is no longer the approved one of the shot or whose files changed, a reference image the shot no longer offers or whose file changed, a prompt that lost its approval, or a part kept outside the file; empty while every pin holds
     */
    public static function pinnedProblems(array $moto, array $file, array $now): array
    {
        $reasons = [];
        $token = (array) $now['token'];

        if ($file['media_check'] !== true || $file['proof'] !== VideoRenderExecutionService::PROOF_DECODED) {
            $reasons[] = 'media_check_failed';
        }

        if (($moto['source']['token']['stage_id'] ?? null) !== ($token['stage_id'] ?? null) || ($moto['source']['token']['content_hash'] ?? null) !== ($token['content_hash'] ?? null)) {
            $reasons[] = 'shot_plan_changed';
        }

        if (is_array($moto['keyframe'] ?? null)) {
            $current = (array) ($now['keyframe'] ?? []);
            $set = is_array($current['set'] ?? null) ? $current['set'] : null;
            $reasons[] = match (true) {
                is_array($current['blocked'] ?? null) => (string) $current['blocked']['code'],
                $set === null => 'keyframe_changed',
                ! is_string($set['decision_id'] ?? null) || $set['decision_id'] === '' => 'keyframe_unapproved',
                self::setPins($set) !== self::setPins((array) $moto['keyframe']) => 'keyframe_changed',
                default => '',
            };
        }

        if (($moto['input_mode'] ?? null) === MotoClipPlanBuilder::REFERENCES) {
            $moved = array_filter((array) ($moto['images'] ?? []), static fn (array $image): bool => ($now['references'][(string) ($image['artifact_id'] ?? '')] ?? null) !== ($image['sha256'] ?? null));
            $reasons[] = $moved === [] && ($moto['images'] ?? []) !== [] ? '' : 'references_changed';
        }

        if (is_array($moto['prompt'] ?? null) && $now['prompt'] !== true) {
            $reasons[] = 'prompt_withdrawn';
        }

        if ($file['duration_ms'] < (int) ($moto['used']['start_ms'] ?? 0) + (int) ($moto['used']['duration_ms'] ?? 0)) {
            $reasons[] = 'used_outside_file';
        }

        return array_values(array_filter($reasons, static fn (string $reason): bool => $reason !== ''));
    }

    /**
     * @param  array<string, mixed>  $set  a keyframe set as keyframes() gives it, or as a request pinned it
     * @return array<string, mixed> what makes it the same set: the image, its master and its delivery image by artifact and hash; the decision that approved it is left out, so approving the same files again keeps a clip made from them
     */
    private static function setPins(array $set): array
    {
        return [
            (string) ($set['image_id'] ?? ''),
            (string) ($set['master']['artifact_id'] ?? ''), (string) ($set['master']['sha256'] ?? ''),
            (string) ($set['delivery']['artifact_id'] ?? ''), (string) ($set['delivery']['sha256'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $preview  the plan the sources build now
     * @param  array<string, mixed>  $moto  the moto_clip block of the render
     * @return array{token: array<string, mixed>, keyframe: ?array<string, mixed>, references: array<string, string>, prompt: bool} the sources pinnedProblems() judges the render by, read as they stand now and never through the settings of the shot
     */
    private function sourcesNow(string $shotId, array $preview, array $moto): array
    {
        $projectId = (string) $preview['plan']['project_id'];
        $references = [];

        if (($moto['input_mode'] ?? null) === MotoClipPlanBuilder::REFERENCES) {
            $offered = array_values(array_filter(array_map(static fn (array $card): ?string => $card['problem'] === null && is_string($card['artifact_id'] ?? null) ? $card['artifact_id'] : null, (array) ($preview['inputs'][$shotId]['cards'] ?? [])), 'is_string'));
            $wanted = array_values(array_intersect(array_map('strval', array_column((array) ($moto['images'] ?? []), 'artifact_id')), $offered));
            $references = $wanted === [] ? [] : $this->artifacts->pluckOwnedShaByIds($projectId, $wanted);
        }

        $prompt = is_array($moto['prompt'] ?? null) ? $this->writer->approvedRunText($projectId, (string) ($moto['prompt']['stage_id'] ?? ''), (string) ($moto['prompt']['decision_id'] ?? '')) : null;

        return [
            'token' => (array) $preview['plan']['source']['token'],
            'keyframe' => is_array($preview['keyframes'][$shotId] ?? null) ? $preview['keyframes'][$shotId] : null,
            'references' => $references,
            'prompt' => $prompt !== null && hash_equals((string) ($moto['prompt']['sha256'] ?? ''), hash('sha256', $prompt)),
        ];
    }

    /**
     * @param  ?string  $reviewerId  who decides
     * @param  ?string  $stillsKey  for a render of an execution plan, the key of the strip of review stills the page showed beside the player; the decision lists those stills as what was offered to look at, never as proof that each frame was looked at
     * @return array{0: VideoShot, 1: string} approved or replayed: one click both selects the result for Phase 11 and records the approval of that very file. For a render of an execution plan the click accepts the four groups of the content review together and the approved render replaces the clip in use
     *
     * @throws RuntimeException when the result no longer fits, its file changed, or another click changed the shot
     */
    public function approve(string $projectId, string $shotId, string $renderId, int $expectedIntent, string $operationId, ?string $reviewerId, ?string $stillsKey = null): array
    {
        if (! Str::isUuid($operationId)) {
            throw new RuntimeException('Mã thao tác duyệt không hợp lệ — tải lại trang.');
        }

        return DB::transaction(function () use ($projectId, $shotId, $renderId, $expectedIntent, $operationId, $reviewerId, $stillsKey): array {
            $this->projects->lockProject($projectId);
            $payloadHash = hash('sha256', json_encode([$projectId, $shotId, $renderId, $expectedIntent], JSON_THROW_ON_ERROR));
            $replayed = $this->replayedOperation($projectId, $operationId);

            if ($replayed !== null) {
                if (! hash_equals((string) $replayed->operation_payload_hash, $payloadHash)) {
                    throw new RuntimeException('Mã thao tác này đã dùng cho một yêu cầu khác — tải lại trang.');
                }

                return [$this->shots->findShotOrFail($replayed->entity_id), 'replayed'];
            }

            $preview = $this->plans->preview($projectId);
            $shot = $this->shotsOf($projectId, (string) $preview['plan']['source']['planning_stage_id'], [$shotId], true)->first();

            if ($shot === null) {
                throw new RuntimeException("Shot {$shotId} chưa có lượt render nào trong bản kế hoạch hiện hành.");
            }

            if ((int) $shot->intent_version !== $expectedIntent) {
                throw new RuntimeException('Clip đã thay đổi ở thao tác khác. Tải lại trang trước khi duyệt.');
            }

            $render = $this->renders->findRenderOfShot($renderId, $shot->id) ?? throw new RuntimeException('Kết quả này không thuộc shot đang duyệt.');
            $verdict = $this->verdict($shot, $render, $preview);

            if ($verdict['status'] !== 'valid') {
                throw new RuntimeException('Kết quả này không còn khớp nguồn hiện hành ('.implode(', ', $verdict['reasons']).') — không duyệt được.');
            }

            if ($this->fileProblem($render) !== null) {
                throw new RuntimeException('File của kết quả này không còn hoặc khác hash đã ghi — không duyệt được.');
            }

            $request = (array) json_decode((string) $render->render_request_json, true);
            $token = (array) $preview['plan']['source']['token'];
            $pinned = self::pinned($request['moto_clip'] ?? null);
            $stills = null;

            if ($pinned) {
                if ((string) $shot->video_render_id === (string) $render->id && $this->approvedOf($shot) !== null) {
                    throw new RuntimeException('Clip này đang được dùng cho shot '.$shotId.' — không cần duyệt lại.');
                }

                $stills = $stillsKey === null || $stillsKey === '' ? null : $this->frames->shown($projectId, (string) $render->id, $stillsKey);
            }

            $next = $expectedIntent + 1;
            $replaced = $shot->video_render_id === null ? null : (string) $shot->video_render_id;
            $this->shots->saveShot($shot, [
                'video_render_id' => $render->id,
                'motion_spec_hash' => (string) ($request['motion_spec_hash'] ?? ''),
                'scene_status' => 'video_ready',
                'approved_at' => now(),
                'approved_artifact_hash' => (string) $render->primary_artifact_hash,
                'approved_by_admin_id' => $reviewerId,
                'intent_version' => $next,
                'auto_select_version' => null,
            ]);
            $event = $this->events->createEvent([
                'session_id' => $shot->session_id,
                'event_type' => 'shot_render_selected',
                'entity_type' => 'shot',
                'entity_id' => $shot->id,
                'payload_json' => ['render_id' => (string) $render->id, 'expected_intent_version' => $expectedIntent],
                'operation_id' => $operationId,
                'operation_payload_hash' => $payloadHash,
                'operation_result_json' => ['status' => 'approved', 'selected_render_id' => (string) $render->id, 'intent_version' => $next],
            ]);
            $selection = [
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
            ];
            $this->decisions->createDecision([
                'project_id' => $projectId,
                'session_id' => $shot->session_id,
                'entity_type' => 'shot',
                'entity_id' => $shot->id,
                'decision' => 'approved',
                'revision' => $this->nextRevision($shot),
                'reviewer_id' => $reviewerId,
                'reason' => 'Duyệt clip của shot cho Phase 11.',
                'metadata_json' => $pinned
                    ? self::reviewMetadata($render, $request, ['result' => 'approved', 'accepted' => array_keys(self::REVIEW_GROUPS), 'faults' => [], 'note' => null, 'marks_ms' => []], $stills) + $selection + ['replaces_render_id' => $replaced]
                    : $selection,
            ]);

            return [$this->shots->refreshShot($shot), 'approved'];
        }, attempts: 3);
    }

    /**
     * @param  list<string>  $faults  the groups of REVIEW_GROUPS the clip fails, at least one
     * @param  ?string  $note  what a person wrote, at most NOTE_MAX_CHARS
     * @param  list<int>  $marks  times in the file, in milliseconds, a person pointed at
     * @param  ?string  $stillsKey  the key of the strip of review stills the page showed
     * @return array{0: VideoShot, 1: string} rejected or replayed: a decision that names this render as not passing the content review, with what fails; the shot, the clip in use and every other render are left as they are, and nothing is rendered again
     *
     * @throws RuntimeException when the render is not a checked candidate of this shot under an execution plan, is the clip in use (revoke it instead), or the faults, note or marks are not ones a decision keeps
     */
    public function reject(string $projectId, string $shotId, string $renderId, string $operationId, ?string $reviewerId, array $faults, ?string $note = null, array $marks = [], ?string $stillsKey = null): array
    {
        $faults = array_values(array_unique(array_map('strval', $faults)));
        $note = $note === null || trim($note) === '' ? null : trim($note);
        $marks = array_values(array_unique(array_map('intval', $marks)));
        sort($marks);

        if (! Str::isUuid($operationId)) {
            throw new RuntimeException('Mã thao tác không hợp lệ — tải lại trang.');
        }

        if ($faults === [] || array_diff($faults, array_keys(self::REVIEW_GROUPS)) !== []) {
            throw new RuntimeException('Chọn ít nhất một nhóm lỗi trong '.implode(' · ', self::REVIEW_GROUPS).'.');
        }

        if ($note !== null && mb_strlen($note) > self::NOTE_MAX_CHARS) {
            throw new RuntimeException('Ghi chú dài quá '.self::NOTE_MAX_CHARS.' ký tự.');
        }

        if (count($marks) > self::MARKS_MAX || array_filter($marks, static fn (int $ms): bool => $ms < 0) !== []) {
            throw new RuntimeException('Mốc thời gian phải là số mili giây không âm, tối đa '.self::MARKS_MAX.' mốc.');
        }

        return DB::transaction(function () use ($projectId, $shotId, $renderId, $operationId, $reviewerId, $faults, $note, $marks, $stillsKey): array {
            $this->projects->lockProject($projectId);
            $payloadHash = hash('sha256', json_encode(['rejected', $projectId, $shotId, $renderId, $faults, $note, $marks], JSON_THROW_ON_ERROR));
            [$shot, $render, $request, $replayed] = $this->decided($projectId, $shotId, $renderId, $operationId, $payloadHash);

            if ($replayed) {
                return [$shot, 'replayed'];
            }

            if ($render->execution_status !== RenderStatus::SUCCEEDED || preg_match('/^[a-f0-9]{64}$/', (string) $render->primary_artifact_hash) !== 1) {
                throw new RuntimeException('Lượt này không có file đạt kiểm kỹ thuật — không cần kiểm nội dung.');
            }

            if ((string) $shot->video_render_id === (string) $render->id) {
                throw new RuntimeException('Clip này đang được dùng cho shot '.$shotId.' — bấm Thu hồi nếu nó không còn đạt.');
            }

            if ($marks !== [] && end($marks) > (int) $render->duration_ms) {
                throw new RuntimeException('Mốc '.end($marks).' ms nằm ngoài file dài '.(int) $render->duration_ms.' ms.');
            }

            $stills = $stillsKey === null || $stillsKey === '' ? null : $this->frames->shown($projectId, (string) $render->id, $stillsKey);
            $event = $this->event($shot, 'shot_render_rejected', $render, $operationId, $payloadHash, 'rejected');
            $this->decisions->createDecision([
                'project_id' => $projectId,
                'session_id' => $shot->session_id,
                'entity_type' => 'shot',
                'entity_id' => $shot->id,
                'decision' => 'rejected',
                'revision' => $this->nextRevision($shot),
                'reviewer_id' => $reviewerId,
                'reason' => $note ?? 'Không đạt: '.implode(' · ', array_map(static fn (string $fault): string => self::REVIEW_GROUPS[$fault], $faults)).'.',
                'metadata_json' => self::reviewMetadata($render, $request, ['result' => 'rejected', 'accepted' => [], 'faults' => $faults, 'note' => $note, 'marks_ms' => $marks], $stills)
                    + ['operation_event_id' => (string) $event->id, 'intent_version' => (int) $shot->intent_version],
            ]);

            return [$this->shots->refreshShot($shot), 'rejected'];
        }, attempts: 3);
    }

    /**
     * @param  ?string  $note  why, at most NOTE_MAX_CHARS
     * @return array{0: VideoShot, 1: string} revoked or replayed: the clip in use stops being handed over — the shot has no clip in use until a person approves one, and no other render is chosen for it; the render and its history stay
     *
     * @throws RuntimeException when the render is not the clip in use of this shot under an execution plan, or another click changed the shot
     */
    public function revoke(string $projectId, string $shotId, string $renderId, int $expectedIntent, string $operationId, ?string $reviewerId, ?string $note = null): array
    {
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if (! Str::isUuid($operationId)) {
            throw new RuntimeException('Mã thao tác không hợp lệ — tải lại trang.');
        }

        if ($note !== null && mb_strlen($note) > self::NOTE_MAX_CHARS) {
            throw new RuntimeException('Ghi chú dài quá '.self::NOTE_MAX_CHARS.' ký tự.');
        }

        return DB::transaction(function () use ($projectId, $shotId, $renderId, $expectedIntent, $operationId, $reviewerId, $note): array {
            $this->projects->lockProject($projectId);
            $payloadHash = hash('sha256', json_encode(['revoked', $projectId, $shotId, $renderId, $expectedIntent], JSON_THROW_ON_ERROR));
            [$shot, $render, $request, $replayed] = $this->decided($projectId, $shotId, $renderId, $operationId, $payloadHash);

            if ($replayed) {
                return [$shot, 'replayed'];
            }

            if ((int) $shot->intent_version !== $expectedIntent) {
                throw new RuntimeException('Clip đã thay đổi ở thao tác khác. Tải lại trang trước khi thu hồi.');
            }

            if ((string) $shot->video_render_id !== (string) $render->id) {
                throw new RuntimeException('Kết quả này không phải clip đang dùng của shot '.$shotId.' — không có gì để thu hồi.');
            }

            $next = $expectedIntent + 1;
            $this->shots->saveShot($shot, [
                'video_render_id' => null,
                'scene_status' => 'keyframe_ready',
                'approved_at' => null,
                'approved_artifact_hash' => null,
                'approved_by_admin_id' => null,
                'intent_version' => $next,
                'auto_select_version' => null,
            ]);
            $event = $this->event($shot, 'shot_render_revoked', $render, $operationId, $payloadHash, 'revoked');
            $this->decisions->createDecision([
                'project_id' => $projectId,
                'session_id' => $shot->session_id,
                'entity_type' => 'shot',
                'entity_id' => $shot->id,
                'decision' => 'revoked',
                'revision' => $this->nextRevision($shot),
                'reviewer_id' => $reviewerId,
                'reason' => $note ?? 'Thu hồi clip đang dùng của shot.',
                'metadata_json' => self::reviewMetadata($render, $request, ['result' => 'revoked', 'accepted' => [], 'faults' => [], 'note' => $note, 'marks_ms' => []], null)
                    + ['operation_event_id' => (string) $event->id, 'intent_version' => $next],
            ]);

            return [$this->shots->refreshShot($shot), 'revoked'];
        }, attempts: 3);
    }

    /**
     * @return array{0: VideoShot, 1: VideoRender, 2: array<string, mixed>, 3: bool} the shot of the plan in force, the render of that shot a decision names, its request, and whether this operation was already recorded with this very payload
     *
     * @throws RuntimeException when the operation was used for something else, the shot or render is not of this project and plan, or the render is not of an execution plan
     */
    private function decided(string $projectId, string $shotId, string $renderId, string $operationId, string $payloadHash): array
    {
        $replayed = $this->replayedOperation($projectId, $operationId);

        if ($replayed !== null) {
            if (! hash_equals((string) $replayed->operation_payload_hash, $payloadHash)) {
                throw new RuntimeException('Mã thao tác này đã dùng cho một yêu cầu khác — tải lại trang.');
            }

            $shot = $this->shots->findShotOrFail($replayed->entity_id);

            return [$shot, $this->renders->findReplayedRenderOrFail($replayed->payload_json['render_id'] ?? null), [], true];
        }

        $preview = $this->plans->preview($projectId);
        $shot = $this->shotsOf($projectId, (string) $preview['plan']['source']['planning_stage_id'], [$shotId], true)->first()
            ?? throw new RuntimeException("Shot {$shotId} chưa có lượt render nào trong bản kế hoạch hiện hành.");
        $render = $this->renders->findProductionRenderOfShot($renderId, $shot->id)
            ?? throw new RuntimeException('Kết quả này không thuộc shot đang duyệt.');
        $request = (array) json_decode((string) $render->render_request_json, true);

        if (! self::pinned($request['moto_clip'] ?? null)) {
            throw new RuntimeException('Chỉ lượt render theo hợp đồng thực thi clip ('.MotoClipRenderService::REQUEST_VERSION_V3.') mới ghi được quyết định này.');
        }

        return [$shot, $render, $request, false];
    }

    private function replayedOperation(string $projectId, string $operationId): ?VideoSessionEvent
    {
        return $this->events->findOperationForProject($projectId, $operationId);
    }

    private function event(VideoShot $shot, string $type, VideoRender $render, string $operationId, string $payloadHash, string $status): VideoSessionEvent
    {
        return $this->events->createEvent([
            'session_id' => $shot->session_id,
            'event_type' => $type,
            'entity_type' => 'shot',
            'entity_id' => $shot->id,
            'payload_json' => ['render_id' => (string) $render->id, 'intent_version' => (int) $shot->intent_version],
            'operation_id' => $operationId,
            'operation_payload_hash' => $payloadHash,
            'operation_result_json' => ['status' => $status, 'render_id' => (string) $render->id],
        ]);
    }

    private function nextRevision(VideoShot $shot): int
    {
        return 1 + $this->decisions->maxRevisionFor('shot', $shot->id);
    }

    /**
     * @param  array<string, mixed>  $request  the frozen request of a render of an execution plan
     * @param  array{result: string, accepted: list<string>, faults: list<string>, note: ?string, marks_ms: list<int>}  $review  what the person decided of the content
     * @param  ?array<string, mixed>  $stills  MotoClipReviewFramesService::shown() of the strip the page showed, null when none was shown
     * @return array<string, mixed> the decision as moto-clip-review-v1 keeps it, immutable: the render, its file and request hashes, the shot plan it pinned, the images it was made from, the execution it froze, the content review and the review stills offered beside the player (not proof that each frame was looked at)
     */
    private static function reviewMetadata(VideoRender $render, array $request, array $review, ?array $stills): array
    {
        $moto = (array) $request['moto_clip'];

        return [
            'version' => MotoContract::CLIP_REVIEW,
            'render_id' => (string) $render->id,
            'artifact_hash' => (string) $render->primary_artifact_hash,
            'request_hash' => (string) $render->request_hash,
            'shot_plan' => ['stage_id' => $moto['source']['token']['stage_id'] ?? null, 'content_hash' => $moto['source']['token']['content_hash'] ?? null, 'planning_stage_id' => $moto['source']['planning_stage_id'] ?? null],
            'sources' => [
                'keyframe' => $moto['keyframe'] ?? null,
                'images' => array_values((array) ($moto['images'] ?? [])),
                'crop_plan' => $moto['crop_plan'] ?? null,
                'prompt' => $moto['prompt'] ?? null,
            ],
            'execution' => [
                'policy' => $moto['execution_policy'] ?? null,
                'settings' => $moto['settings'] ?? null,
                'motion_method' => $moto['motion_method'] ?? null,
                'input_mode' => $moto['input_mode'] ?? null,
                'content_duration_ms' => $moto['content_duration_ms'] ?? null,
                'render' => $moto['render'] ?? null,
                'used' => $moto['used'] ?? null,
                'audio_policy' => $moto['audio_policy'] ?? null,
                'media_policy' => $request['media_policy'] ?? null,
            ],
            'content_review' => $review + ['groups' => self::REVIEW_GROUPS],
            'review_stills' => $stills === null
                ? ['offered' => false, 'note' => 'không có dải ảnh hỗ trợ duyệt kèm quyết định này']
                : ['offered' => true, 'note' => 'ảnh hỗ trợ duyệt hiện cạnh trình phát — không phải bằng chứng đã xem từng frame'] + $stills,
        ];
    }

    /**
     * @return array<string, mixed> what Phase 11 cuts from: the approved result of every shot in plan order with the evidence for it, read from the decisions and never from the latest clip, and its file read again from disk; a shot with no approval that still fits, or whose file is gone or changed, is listed as missing with why. A clip of an execution plan hands the part its own request kept — [0, 3000) of a 4 s or 8 s file — with its measured metadata, its audio rule and the sources it pinned
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
            $request = (array) json_decode((string) $render->render_request_json, true);
            $moto = self::pinned($request['moto_clip'] ?? null) ? (array) $request['moto_clip'] : null;
            $entry = [
                'shot_id' => $clip['shot_id'],
                'scene_id' => $clip['scene_id'],
                'order' => $clip['order'],
                'render_id' => (string) $render->id,
                'artifact' => ['disk' => (string) config('video.veo.disk'), 'path' => (string) $render->artifact_path, 'sha256' => (string) $render->primary_artifact_hash],
                'request_hash' => (string) $render->request_hash,
                'measured' => ['duration_ms' => (int) $render->duration_ms, 'width' => (int) $render->width, 'height' => (int) $render->height, 'bytes' => (int) $render->bytes, 'has_audio' => $file['has_audio'] ?? null],
                'used' => $moto === null ? $clip['used'] : ['start_ms' => (int) $moto['used']['start_ms'], 'duration_ms' => (int) $moto['used']['duration_ms']],
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

            if ($moto !== null) {
                $measured = (array) ($file['media_check']['measured'] ?? []);
                $entry['measured'] += array_intersect_key($measured, array_flip(['video_duration_ms', 'audio_duration_ms', 'av_drift_ms', 'video_streams', 'audio_streams', 'fps', 'avg_frame_rate', 'frames', 'codec', 'pixel_format', 'sar', 'rotation', 'audio_codec']));
                $entry += [
                    'audio_policy' => (string) $moto['audio_policy'],
                    'media_check' => ['version' => $file['media_check']['version'] ?? null, 'passed' => true, 'policy' => $request['media_policy'] ?? null, 'proof' => (string) $render->proof_method],
                    'execution' => ['policy' => $moto['execution_policy'], 'motion_method' => $moto['motion_method'], 'input_mode' => $moto['input_mode'], 'content_duration_ms' => $moto['content_duration_ms'], 'render' => $moto['render']],
                    'sources' => ['shot_plan' => $moto['source']['token'] ?? null, 'keyframe' => $moto['keyframe'], 'images' => array_values((array) $moto['images']), 'crop_plan' => $moto['crop_plan'], 'prompt' => $moto['prompt']],
                    'review' => ['version' => $approved['decision']->metadata_json['version'] ?? null, 'accepted' => $approved['decision']->metadata_json['content_review']['accepted'] ?? []],
                ];
            }

            $clips[] = $entry;
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
        $renders = $this->renders->findProductionVideoRendersOfShots($shots->flatten()->pluck('id'))
            ->groupBy('shot_id');
        $scenes = [];
        $clips = [];
        $keyframes = [];
        $summary = $base['summary'];
        $stageId = (string) $preview['plan']['source']['planning_stage_id'];
        $selected = $this->writer->selected($projectId, $stageId);

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
                'clip_prompt' => $this->writer->screen($projectId, $preview['source'], $preview['keyframes'], $selected, $shotId, $preview['inputs']),
                'clip_input' => [
                    'mode' => $clip['input_mode'],
                    'cards' => $preview['inputs'][$shotId]['cards'],
                    'sent' => array_column($clip['images'], 'position', 'artifact_id'),
                    'limit' => $preview['inputs'][$shotId]['limit'] ?? null,
                    'url' => route('video-projects.clip-prompt-action', [$projectId, $shotId, 'input']),
                ],
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
                    $clip['input_mode'] === MotoClipPlanBuilder::REFERENCES
                        ? 'Ảnh tham chiếu: '.count($clip['images']).' ảnh gửi đi, không dùng keyframe'
                        : 'Keyframe: '.($clip['keyframe'] === null ? ($clip['blocked']['code'] ?? 'chưa có') : 'đã duyệt · '.substr((string) $clip['keyframe']['sha256'], 0, 12)),
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
     * @param  ?string  $onlyShot  one shot, for the part of the page an action answers with; null for every shot
     * @return array<string, mixed> what the Render video screen of a project under an execution policy shows by shot: the shot plan row and what must be seen, the settings being prepared with every offer of the policy (unavailable ones with why), the reference images or the keyframe set and crop path, the video prompt, what a render of the settings asks for and costs, what is running, the clip in use judged by its own pins, the candidates waiting for review, and every attempt with its technical and content state; opening it reads only and writes nothing
     */
    public function panel(string $projectId, ?string $onlyShot = null): array
    {
        $base = ['problem' => null, 'rows' => [], 'groups' => self::REVIEW_GROUPS, 'contract' => null, 'stage' => null, 'counts' => ['shots' => 0, 'in_use' => 0, 'pending' => 0, 'running' => 0, 'blocked' => 0]];

        try {
            $preview = $this->plans->preview($projectId);
        } catch (RuntimeException $e) {
            return ['problem' => $e->getMessage()] + $base;
        }

        $stageId = (string) $preview['plan']['source']['planning_stage_id'];
        $clips = array_values(array_filter($preview['plan']['clips'], static fn (array $clip): bool => $onlyShot === null || $clip['shot_id'] === $onlyShot));
        $shots = $this->shotsOf($projectId, null, array_column($clips, 'shot_id'))->groupBy('shot_code');
        $ids = $shots->flatten()->pluck('id');
        $renders = $this->renders->findProductionVideoRendersOfShotsInCreationOrder($ids)->groupBy('shot_id');
        $decisions = $this->decisions->findShotDecisions($ids)->groupBy('entity_id');
        $selected = $this->writer->selected($projectId, $stageId);
        $rows = [];
        $counts = $base['counts'];

        foreach ($clips as $clip) {
            $row = $this->panelRow($projectId, $preview, $clip, $shots->get((string) $clip['shot_id'], collect()), $renders, $decisions, $selected, $stageId);
            $rows[] = $row;
            $counts['shots']++;
            $counts['in_use'] += is_array($row['in_use']) && ($row['in_use']['validity']['status'] ?? null) === 'valid' && $row['in_use']['file_problem'] === null ? 1 : 0;
            $counts['pending'] += count($row['candidates']);
            $counts['running'] += $row['current'] !== null ? 1 : 0;
            $counts['blocked'] += $clip['blocked'] !== null ? 1 : 0;
        }

        return [
            'problem' => null,
            'rows' => $rows,
            'groups' => self::REVIEW_GROUPS,
            'contract' => ['phase_revision' => (int) $preview['plan']['contract']['phase_revision'], 'builder' => (string) $preview['plan']['contract']['builder'], 'writer' => (string) $preview['plan']['contract']['writer'], 'policy' => (string) ($preview['plan']['execution_policy']['version'] ?? '')],
            'stage' => ['id' => $stageId, 'revision' => (int) $preview['plan']['source']['revision'], 'content_hash' => (string) $preview['plan']['source']['token']['content_hash'], 'total_ms' => (int) $preview['plan']['total_ms']],
            'counts' => $counts,
        ];
    }

    /**
     * @param  array<string, mixed>  $preview
     * @param  array<string, mixed>  $clip  the clip of the shot in the plan built now
     * @param  Collection<int, VideoShot>  $own  every revision of the shot
     * @param  Collection<string, Collection<int, VideoRender>>  $renders  results by shot
     * @param  Collection<string, Collection<int, VideoReviewDecision>>  $decisions  decisions by shot, oldest first
     * @param  array<string, array<string, mixed>>  $selected  the approved prompts of the shot plan
     * @return array<string, mixed> one shot of panel()
     */
    private function panelRow(string $projectId, array $preview, array $clip, Collection $own, Collection $renders, Collection $decisions, array $selected, string $stageId): array
    {
        $shotId = (string) $clip['shot_id'];
        $planRow = (array) (collect((array) $preview['source']['plan']['shots'])->firstWhere('id', $shotId) ?? []);
        $input = (array) ($preview['inputs'][$shotId] ?? []);
        $settings = (array) ($input['settings'] ?? []);
        $keyframe = (array) ($preview['keyframes'][$shotId] ?? []);
        $shot = $own->first(static fn (VideoShot $one): bool => self::stageOf($one) === $stageId);
        $crop = $clip['motion_method'] === MotoContract::MOTION_DYNAMIC_CROP;
        $models = collect($preview['models'])->keyBy('id');
        $entries = [];
        $candidates = [];
        $attempts = [];
        $inUse = null;
        $approved = $shot === null ? null : $this->approvedOf($shot);

        foreach ($own as $revision) {
            $byRender = $this->decisionsByRender($decisions->get((string) $revision->id, collect()));

            foreach ($renders->get((string) $revision->id, collect()) as $render) {
                $here = $shot !== null && (string) $revision->id === (string) $shot->id;
                $entry = $this->renderEntry($projectId, $shotId, $render, $revision, $byRender[(string) $render->id] ?? null, $here ? $preview : null, $models->all());
                $entries[] = $entry;

                if ($here) {
                    $attempts[] = ['entry' => $entry, 'technical' => $entry['technical'], 'retirement' => $this->clipRender->retirementOf($render), 'current' => $shot->current_render_id !== null && (string) $render->id === (string) $shot->current_render_id];
                }

                if ($here && $approved !== null && (string) $approved['render']->id === (string) $render->id) {
                    $inUse = $entry + ['file_problem' => $this->fileProblem($render)];
                } elseif ($here && $entry['pinned'] && $entry['technical']['state'] === 'passed' && $entry['content']['state'] === 'pending') {
                    $candidates[] = $entry;
                }
            }
        }

        usort($entries, static fn (array $a, array $b): int => [$b['sort'], $b['attempt_no']] <=> [$a['sort'], $a['attempt_no']]);
        usort($candidates, static fn (array $a, array $b): int => [$b['sort'], $b['attempt_no']] <=> [$a['sort'], $a['attempt_no']]);
        $open = self::openAttempts($attempts);
        $current = $open['shown'] === null ? null : $attempts[$open['shown']]['entry'];
        $holding = $open['holding'] === null ? null : $attempts[$open['holding']]['entry'];
        $entry = is_string($clip['render']['model'] ?? null) ? $models->get($clip['render']['model']) : null;
        $local = ($clip['render']['transport'] ?? null) === MotoClipPlanBuilder::LOCAL;
        $controls = [
            'model_id' => (string) ($local ? $clip['render']['processor'] : ($clip['render']['model'] ?? '')),
            'resolution' => (string) ($clip['render']['resolution'] ?? ''),
            'aspect_ratio' => (string) ($clip['render']['aspect_ratio'] ?? ''),
            'duration_seconds' => is_int($clip['render']['duration_ms'] ?? null) ? intdiv($clip['render']['duration_ms'], 1000) : 0,
        ];

        return [
            'shot_id' => $shotId,
            'scene_id' => (string) $clip['scene_id'],
            'order' => (int) $clip['order'],
            'spec' => [
                'purpose' => (string) ($planRow['purpose'] ?? ''),
                'first_frame' => (string) ($planRow['first_frame'] ?? ''),
                'end_frame' => (string) ($planRow['end_frame'] ?? ''),
                'steps' => array_map(static fn (array $step): array => ['id' => (string) ($step['id'] ?? ''), 'start_ms' => (int) ($step['start_ms'] ?? 0), 'end_ms' => (int) ($step['end_ms'] ?? 0), 'action' => (string) ($step['action'] ?? ''), 'result' => (string) ($step['result'] ?? '')], array_values(array_filter((array) ($planRow['action_timeline'] ?? []), 'is_array'))),
                'camera' => array_map(static fn (array $segment): array => ['id' => (string) ($segment['id'] ?? ''), 'start_ms' => (int) ($segment['start_ms'] ?? 0), 'end_ms' => (int) ($segment['end_ms'] ?? 0), 'description' => (string) ($segment['description'] ?? '')], array_values(array_filter((array) ($planRow['camera']['path'] ?? []), 'is_array'))),
            ],
            'clip' => $clip,
            'blocked' => $clip['blocked'],
            'settings' => $settings,
            'choices' => self::choices((array) ($preview['options'] ?? []), $settings, (array) $preview['plan']['execution_policy']),
            'cards' => (array) ($input['cards'] ?? []),
            'limit' => (int) ($input['limit'] ?? 0),
            'keyframe' => [
                'url' => $keyframe['url'] ?? null,
                'set' => $keyframe['set'] ?? null,
                'blocked' => $keyframe['blocked'] ?? null,
                'master_url' => is_string($keyframe['set']['master']['artifact_id'] ?? null) ? route('video-artifacts.show', (string) $keyframe['set']['master']['artifact_id']) : null,
            ],
            'crop' => self::cropTool($keyframe['set'] ?? null, $settings, (array) $preview['plan']['execution_policy']),
            'writer' => $crop ? null : $this->writer->screen($projectId, $preview['source'], $preview['keyframes'], $selected, $shotId, $preview['inputs']),
            'cost' => $crop ? null : self::estimate($entry, $clip['render']['resolution'] ?? null, $clip['render']['duration_ms'] ?? null),
            'render' => $controls + [
                'local' => $local,
                'expected_settings' => (int) ($clip['settings']['revision'] ?? 0),
                'hash' => (string) $clip['hash'],
                'label' => $local ? 'Dynamic Crop trên máy này ('.$clip['render']['processor'].')' : (string) ($entry['label'] ?? $clip['render']['model'] ?? '—'),
                'renderable' => $clip['blocked'] === null && $holding === null && ! is_string($settings['problem'] ?? null),
                'why' => $clip['blocked']['message'] ?? match (true) {
                    $holding === null => null,
                    $holding['technical']['state'] === 'unknown' => 'Lượt trước chưa rõ provider đã nhận hay chưa — bấm Hỏi provider ngay; không gửi lại để tránh trả tiền hai lần.',
                    default => 'Shot đang có một lượt chạy — chờ nó xong.',
                },
            ],
            'intent_version' => (int) ($shot?->intent_version ?? 0),
            'current' => $current,
            'in_use' => $inUse,
            'candidates' => $candidates,
            'history' => $entries,
        ];
    }

    /**
     * @param  list<array{technical: array<string, mixed>, retirement: ?string, current: bool}>  $attempts  the attempts of the shot under the plan in force, oldest first: technical() of each, what a new render of the shot would do with it (MotoClipRenderService::retirement()) and whether the shot names it as its current render
     * @return array{shown: ?int, holding: ?int} the place of the attempt the screen follows and of the one that stops a new render, the newest of each, null when there is none. An attempt stops a new render exactly when the dispatch would refuse to retire it (RETIRE_WAIT): a row the next render closes or cancels for free never does, however it is labelled. The screen follows the attempt that stops a new render, so one of unknown outcome is shown with its way to ask the provider; when none does, it follows the current render of the shot while it runs
     */
    public static function openAttempts(array $attempts): array
    {
        $holding = null;
        $running = null;

        foreach (array_values($attempts) as $index => $attempt) {
            if ($attempt['retirement'] === MotoClipRenderService::RETIRE_WAIT) {
                $holding = $index;
            }

            if ($attempt['current'] && ($attempt['technical']['state'] ?? null) === 'running') {
                $running = $index;
            }
        }

        return ['shown' => $holding ?? $running, 'holding' => $holding];
    }

    /**
     * @param  Collection<int, VideoReviewDecision>  $decisions  the decisions of one shot, oldest first
     * @return array<string, VideoReviewDecision> the decision that counts for each render a decision names: the newest one naming it
     */
    private function decisionsByRender(Collection $decisions): array
    {
        $byRender = [];

        foreach ($decisions as $decision) {
            $named = $decision->metadata_json['render_id'] ?? $decision->metadata_json['selected_render_id'] ?? null;

            if (is_string($named)) {
                $byRender[$named] = $decision;
            }
        }

        return $byRender;
    }

    /**
     * @param  ?array<string, mixed>  $preview  the plan built now when the render belongs to the shot plan in force, for its verdict; null for a render of an earlier plan, which is never offered
     * @param  array<string, array<string, mixed>>  $models  the registry entries of the offered models by id
     * @return array<string, mixed> one attempt as the screen shows it: what it asked for (from its own request), what was measured, its technical state, the decision that counts for it, whether it still stands on its pins, what it cost, and whether it can be approved or rejected now
     */
    private function renderEntry(string $projectId, string $shotId, VideoRender $render, VideoShot $shot, ?VideoReviewDecision $decision, ?array $preview, array $models): array
    {
        $request = (array) json_decode((string) $render->render_request_json, true);
        $moto = (array) ($request['moto_clip'] ?? []);
        $pinned = self::pinned($moto);
        $local = $render->provider === MotoClipPlanBuilder::LOCAL;
        $technical = self::technical($render);
        $inUse = (string) $shot->video_render_id === (string) $render->id;
        $content = self::content($technical['state'] === 'passed' ? $decision : null, $inUse, $technical['state']);
        $validity = $preview !== null && $technical['state'] === 'passed' ? $this->verdict($shot, $render, $preview) : null;
        $check = (array) ($render->artifact_manifest['artifacts'][0]['media_check'] ?? []);
        $measured = (array) ($check['measured'] ?? []);
        $model = (string) ($moto['render']['model'] ?? $moto['capability']['model_id'] ?? '');
        $file = $technical['state'] === 'passed' && (string) $render->artifact_path !== '';

        return [
            'id' => (string) $render->id,
            'attempt_no' => (int) $render->attempt_no,
            'sort' => $render->created_at?->getTimestamp() ?? 0,
            'at' => $render->created_at?->format('Y-m-d H:i'),
            'current_plan' => $preview !== null,
            'pinned' => $pinned,
            'local' => $local,
            'model_label' => $local ? 'Dynamic Crop · '.$render->model : (string) ($models[$model]['label'] ?? ($model !== '' ? $model : $render->model)),
            'requested' => [
                'motion_method' => $moto['motion_method'] ?? MotoContract::MOTION_AI_VIDEO,
                'input_mode' => $moto['input_mode'] ?? ($moto['input']['mode'] ?? null),
                'resolution' => $moto['render']['resolution'] ?? ($request['resolution'] ?? null),
                'aspect_ratio' => $moto['render']['aspect_ratio'] ?? ($request['aspect_ratio'] ?? null),
                'duration_ms' => is_int($moto['render']['duration_ms'] ?? null) ? $moto['render']['duration_ms'] : (is_int($request['duration_seconds'] ?? null) ? $request['duration_seconds'] * 1000 : null),
                'used' => is_array($moto['used'] ?? null) ? $moto['used'] : null,
                'audio_policy' => $moto['audio_policy'] ?? null,
                'settings_revision' => $moto['settings']['revision'] ?? null,
            ],
            'measured' => $render->duration_ms === null && $measured === [] ? null : [
                'duration_ms' => $measured['duration_ms'] ?? $render->duration_ms,
                'width' => $measured['width'] ?? $render->width,
                'height' => $measured['height'] ?? $render->height,
                'fps' => $measured['fps'] ?? null,
                'frames' => $measured['frames'] ?? null,
                'codec' => $measured['codec'] ?? null,
                'pixel_format' => $measured['pixel_format'] ?? null,
                'has_audio' => $measured['has_audio'] ?? ($render->artifact_manifest['artifacts'][0]['has_audio'] ?? null),
                'audio_streams' => $measured['audio_streams'] ?? null,
                'bytes' => $render->bytes,
                'decoded' => $check['decode']['ok'] ?? null,
            ],
            'technical' => $technical,
            'content' => $content,
            'decision' => $decision === null ? null : [
                'decision' => (string) $decision->decision,
                'at' => $decision->created_at?->format('Y-m-d H:i'),
                'reviewer_id' => $decision->reviewer_id,
                'faults' => array_values(array_map(static fn (string $fault): string => self::REVIEW_GROUPS[$fault] ?? $fault, (array) ($decision->metadata_json['content_review']['faults'] ?? []))),
                'note' => $decision->metadata_json['content_review']['note'] ?? null,
                'marks_ms' => (array) ($decision->metadata_json['content_review']['marks_ms'] ?? []),
            ],
            'validity' => $validity === null ? null : ['status' => $validity['status'], 'reasons' => $validity['reasons'], 'labels' => array_map(static fn (string $reason): string => self::REASONS[$reason] ?? 'nguồn: '.$reason, $validity['reasons'])],
            'cost' => $render->cost_usd,
            'prompt' => (string) $render->sent_prompt,
            'file_url' => $file ? route('video-projects.scene-clip-file', [$projectId, $render->id]) : null,
            'strip_url' => $file && $pinned ? route('video-projects.clip-review', [$projectId, $shotId, $render->id]) : null,
            'approvable' => $pinned && $technical['state'] === 'passed' && ($validity['status'] ?? null) === 'valid' && ! ($inUse && $content['state'] === 'in_use'),
            'rejectable' => $pinned && $technical['state'] === 'passed' && ! $inUse,
        ];
    }

    /**
     * @return array{state: string, label: string, tone: string, note: ?string} the technical state of an attempt from what it recorded (spec §12): running, of unknown outcome, passed (a checked file), failed with why, or cancelled before it was sent; a row of the processor of this machine never speaks of a provider
     */
    public static function technical(VideoRender $render): array
    {
        $status = $render->execution_status?->value;
        $local = $render->provider === MotoClipPlanBuilder::LOCAL;
        $check = (array) ($render->artifact_manifest['artifacts'][0]['media_check'] ?? []);
        $hashed = preg_match('/^[a-f0-9]{64}$/', (string) $render->primary_artifact_hash) === 1 && (string) $render->artifact_path !== '';

        return match (true) {
            $status === 'provider_unknown' => $local
                ? ['state' => 'unknown', 'label' => 'Dừng giữa chừng', 'tone' => 'amber', 'note' => 'bộ xử lý trên máy này không còn giữ lượt — lượt được đóng khi tạo lượt mới; không có gì gửi ra ngoài']
                : ['state' => 'unknown', 'label' => 'Chưa rõ kết quả', 'tone' => 'amber', 'note' => 'chưa rõ provider đã nhận hay chưa — không tự gửi lại'],
            in_array($status, ['queued', 'retry_wait', ...self::RUNNING], true) => ['state' => 'running', 'label' => 'Đang xử lý', 'tone' => 'blue', 'note' => $local ? 'đang tạo trên máy này' : 'provider đang dựng · đã hỏi '.(int) $render->provider_poll_count.' lần'],
            $status === 'succeeded' && $hashed && ($check === [] || ($check['passed'] ?? null) === true) => ['state' => 'passed', 'label' => 'Đạt kỹ thuật', 'tone' => 'ok', 'note' => null],
            $status === 'cancelled' => ['state' => 'cancelled', 'label' => 'Đã huỷ trước khi gửi', 'tone' => 'mute', 'note' => (string) ($render->failure_message ?? '')],
            default => ['state' => 'failed', 'label' => 'Không đạt kỹ thuật', 'tone' => 'dg', 'note' => trim(implode(' · ', array_filter([(string) $render->failure_code, (string) ($check['reason'] ?? ''), (string) ($render->failure_message ?? '')])))],
        };
    }

    /**
     * @param  ?VideoReviewDecision  $decision  the decision that counts for a render that passed its technical checks
     * @return array{state: string, label: string, tone: string} its content state: waiting for review, approved and in use, approved before and replaced, not passing, or revoked; a render that did not pass its technical checks has none
     */
    public static function content(?VideoReviewDecision $decision, bool $inUse, string $technical): array
    {
        return match (true) {
            $technical !== 'passed' => ['state' => 'none', 'label' => '—', 'tone' => 'mute'],
            $decision === null => ['state' => 'pending', 'label' => 'Chờ duyệt nội dung', 'tone' => 'amber'],
            $decision->decision === 'approved' && $inUse => ['state' => 'in_use', 'label' => 'Đã duyệt · đang dùng', 'tone' => 'ok'],
            $decision->decision === 'approved' => ['state' => 'replaced', 'label' => 'Đã duyệt trước đây · không còn dùng', 'tone' => 'mute'],
            $decision->decision === 'rejected' => ['state' => 'rejected', 'label' => 'Không đạt nội dung', 'tone' => 'dg'],
            $decision->decision === 'revoked' => ['state' => 'revoked', 'label' => 'Đã thu hồi', 'tone' => 'mute'],
            default => ['state' => 'pending', 'label' => 'Chờ duyệt nội dung', 'tone' => 'amber'],
        };
    }

    /**
     * @param  list<array<string, mixed>>  $options  MotoClipPlanBuilder::options() of the policy
     * @param  array<string, mixed>  $settings  the settings of the shot
     * @param  array<string, mixed>  $execution  the execution policy
     * @return array<string, mixed> what the settings form offers: every way of moving and input of the policy, the models and resolutions of the chosen way, each with whether it can be chosen and why not, and the audio rules; nothing the policy lists is left out
     */
    private static function choices(array $options, array $settings, array $execution): array
    {
        $motion = (string) ($settings['motion_method'] ?? $execution['default_motion_method'] ?? MotoContract::MOTION_AI_VIDEO);
        $mode = (string) ($settings['input_mode'] ?? $execution['default_input_mode'] ?? 'keyframe');
        $group = static function (array $options, string $key): array {
            $grouped = [];

            foreach ($options as $option) {
                $value = (string) $option[$key];
                $grouped[$value] ??= ['value' => $value, 'available' => false, 'reasons' => []];
                $grouped[$value]['available'] = $grouped[$value]['available'] || $option['available'];

                if (! $option['available'] && is_string($option['reason'])) {
                    $grouped[$value]['reasons'][] = $option['reason'];
                }
            }

            return array_values(array_map(static fn (array $row): array => ['value' => $row['value'], 'available' => $row['available'], 'reason' => $row['available'] ? null : implode(' · ', array_unique($row['reasons']))], $grouped));
        };
        $ofMotion = array_values(array_filter($options, static fn (array $option): bool => $option['motion_method'] === $motion));
        $ofMode = array_values(array_filter($ofMotion, static fn (array $option): bool => $option['input_mode'] === $mode));
        $chosen = is_string($settings['model'] ?? null) ? $settings['model'] : null;
        $offer = $chosen === null ? (array_values(array_filter($ofMode, static fn (array $option): bool => $option['available']))[0] ?? null) : (array_values(array_filter($ofMode, static fn (array $option): bool => $option['model'] === $chosen))[0] ?? null);

        return [
            'motion' => $motion,
            'mode' => $mode,
            'motions' => $group($options, 'motion_method'),
            'inputs' => $group($ofMotion, 'input_mode'),
            'models' => array_map(static fn (array $option): array => ['value' => $option['model'], 'label' => $option['label'], 'available' => $option['available'], 'reason' => $option['reason']], array_values(array_filter($ofMode, static fn (array $option): bool => is_string($option['model'])))),
            'default_model' => array_values(array_filter($ofMode, static fn (array $option): bool => $option['available']))[0]['label'] ?? null,
            'resolutions' => (array) ($offer['resolutions'] ?? []),
            'default_resolution' => array_values(array_filter((array) ($offer['resolutions'] ?? []), static fn (array $row): bool => $row['available']))[0]['resolution'] ?? null,
            'audio' => [
                ['value' => MotoContract::AUDIO_ALLOW_SILENT, 'available' => true, 'reason' => null],
                ['value' => MotoContract::AUDIO_REQUIRE, 'available' => $motion !== MotoContract::MOTION_DYNAMIC_CROP, 'reason' => $motion === MotoContract::MOTION_DYNAMIC_CROP ? 'Dynamic Crop không tạo âm thanh.' : null],
            ],
            'policy_audio' => (string) ($execution['audio_policy'][$motion] ?? MotoContract::AUDIO_ALLOW_SILENT),
        ];
    }

    /**
     * @param  ?array<string, mixed>  $set  the approved keyframe set of the shot
     * @param  array<string, mixed>  $settings  the settings of the shot
     * @param  array<string, mixed>  $execution  the execution policy
     * @return ?array<string, mixed> what the crop path tool draws: the master by its size, the window approved with the set as the fixed start, the end window saved (or the start), the window unit and size range that keep the output ratio without enlarging, and the protected region saved through settings, which this screen only shows; null when the set has no master and approved window
     */
    private static function cropTool(?array $set, array $settings, array $execution): ?array
    {
        $start = is_array($set['crop'] ?? null) && ($set['framed'] ?? false) === true ? $set['crop'] : null;
        $output = (array) ($execution['dynamic_crop'] ?? []);
        $width = (int) ($set['master']['width'] ?? 0);
        $height = (int) ($set['master']['height'] ?? 0);

        if ($start === null || $width < 1 || $height < 1 || (int) ($output['width'] ?? 0) < 1 || (int) ($output['height'] ?? 0) < 1) {
            return null;
        }

        $divisor = static function (int $a, int $b) use (&$divisor): int {
            return $b === 0 ? max(1, $a) : $divisor($b, $a % $b);
        };
        $common = $divisor((int) $output['width'], (int) $output['height']);
        $unit = [intdiv((int) $output['width'], $common), intdiv((int) $output['height'], $common)];
        $path = (array) ($settings['crop_plan']['path'] ?? []);
        $end = count($path) > 1 && is_array(end($path)) ? end($path) : ['at_ms' => (int) $execution['content_duration_ms']] + $start;
        $kMax = min(intdiv($width, $unit[0]), intdiv($height, $unit[1]));
        $kMin = max(intdiv((int) $output['width'] + $unit[0] - 1, $unit[0]), intdiv((int) $output['height'] + $unit[1] - 1, $unit[1]));
        $k = intdiv((int) $end['width'], $unit[0]);
        $slack = static fn (int $total, int $size, int $at): float => $total - $size <= 0 ? 0.0 : min(1000.0, ($at + 0.5) * 1000 / ($total - $size));

        return [
            'master' => ['artifact_id' => (string) $set['master']['artifact_id'], 'width' => $width, 'height' => $height, 'url' => route('video-artifacts.show', (string) $set['master']['artifact_id'])],
            'start' => $start,
            'end' => $end,
            'saved' => count($path) > 1,
            'unit' => $unit,
            'k_min' => $kMin,
            'k_max' => $kMax,
            'ranges' => ['x' => $slack($width, (int) $end['width'], (int) $end['x']), 'y' => $slack($height, (int) $end['height'], (int) $end['y']), 'k' => max($kMin, min($kMax, $k))],
            'content_ms' => (int) $execution['content_duration_ms'],
            'output' => [(int) $output['width'], (int) $output['height']],
            'protected' => $settings['crop_plan']['protected'] ?? null,
        ];
    }

    /**
     * @param  ?array<string, mixed>  $entry  the registry entry of the model
     * @return ?array{usd: float, basis: string} the cost of a file of that length and resolution from the price per second the evidence contract of the model holds; null when the model has no contract or the contract no price for that resolution
     */
    public static function estimate(?array $entry, ?string $resolution, ?int $durationMs): ?array
    {
        $name = is_string($entry['evidence']['contract'] ?? null) ? (string) $entry['evidence']['contract'] : '';
        $file = rtrim((string) config('video.provider_evidence_dir'), '/\\').DIRECTORY_SEPARATOR.$name;

        if ($name === '' || $resolution === null || $durationMs === null || $durationMs < 1 || ! is_file($file)) {
            return null;
        }

        $rate = ((array) json_decode((string) file_get_contents($file), true))['documented']['pricing'][$resolution.'_usd_per_second'] ?? null;

        return is_int($rate) || is_float($rate)
            ? ['usd' => round($rate * $durationMs / 1000, 3), 'basis' => $rate.' USD/giây '.$resolution.' × '.round($durationMs / 1000, 1).' giây file theo '.$name.' (ước tính)']
            : null;
    }

    /**
     * @return array<string, mixed> one row's state after a click or a poll, enough for the screen to fetch the row again when it has settled
     *
     * @throws RuntimeException when the shot of the render belongs to another project
     */
    public function state(string $projectId, VideoRender $render, string $reason): array
    {
        $shot = $this->shots->findShotOfRender($render);
        $status = $render->execution_status?->value;
        $running = in_array($status, self::RUNNING, true);
        $local = $render->provider === MotoClipPlanBuilder::LOCAL;
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
                'running' => match (true) {
                    $local => (string) self::technical($render)['note'],
                    $status === 'provider_unknown' => 'chưa rõ provider đã nhận hay chưa — không tự gửi lại',
                    default => 'đang dựng · đã hỏi '.$render->provider_poll_count.' lần',
                },
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
        return $this->renders->findOwnedRender($projectId, $renderId);
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
            'note' => $status === 'provider_unknown' ? ($current?->provider === MotoClipPlanBuilder::LOCAL ? (string) self::technical($current)['note'] : 'chưa rõ provider đã nhận hay chưa — không tự gửi lại') : null,
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

    /** @return ?array{render: VideoRender, decision: VideoReviewDecision} the result of the shot a person approved, with the decision taken on that very file; a decision never carries over to another file. For a render of an execution plan the decision that counts is the newest one naming it: one revoked or rejected after it was approved is no longer approved, and an approval from before is never taken back up */
    private function approvedOf(VideoShot $shot): ?array
    {
        if ($shot->approved_at === null || $shot->video_render_id === null) {
            return null;
        }

        $render = $this->renders->findRenderOfShot($shot->video_render_id, $shot->id);
        $pinned = $render !== null && self::pinned(json_decode((string) $render->render_request_json, true)['moto_clip'] ?? null);
        $decision = $render === null ? null : ($pinned
            ? $this->decisions->findLatestShotDecisionNamingRender($shot->id, (string) $render->id)
            : $this->decisions->findLatestShotApprovalSelectingRender($shot->id, (string) $render->id));

        if ($decision === null || $decision->decision !== 'approved' || ! hash_equals((string) $render->primary_artifact_hash, (string) ($decision->metadata_json['artifact_hash'] ?? '')) || ! hash_equals((string) $render->primary_artifact_hash, (string) $shot->approved_artifact_hash)) {
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
        return $lock
            ? $this->shots->lockMotoClipShots($projectId, $stageId, $shotIds)
            : $this->shots->findMotoClipShots($projectId, $stageId, $shotIds);
    }

    /** @return ?string the shot plan a shot of the flow was made for */
    private static function stageOf(VideoShot $shot): ?string
    {
        $stage = $shot->metadata_json['moto_clip']['shot_plan_stage_id'] ?? null;

        return is_string($stage) ? $stage : null;
    }
}
