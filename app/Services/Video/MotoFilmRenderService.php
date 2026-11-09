<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Jobs\Video\ComposeMotoFilmJob;
use App\Models\VideoFinal;
use App\Models\VideoRender;
use App\Models\VideoSession;
use App\Models\VideoSessionEvent;
use App\Video\FinalComposition\CompositionDecor;
use App\Video\FinalComposition\CompositionExecutor;
use App\Video\FinalComposition\CompositionRun;
use App\Video\FinalComposition\Deadline;
use App\Video\Queue\VideoQueues;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Cuts a film from a frozen film plan, only ever in a background job; with no queue ready a request is refused before anything is taken in.
 *
 * A request freezes everything the cut reads: the film plan, the Phase 10 manifest by its hash, the policy, the profile, the card style
 * and its font by their hashes, the version of the graph and the FFmpeg manifest. The cut reads only that copy, in a workspace of its own
 * named by the final, and a source that changes later never changes it: the result keeps the plan it was cut from, is never approved by
 * itself and is never cut again by itself. A final that already exists is never overwritten; a new request makes a new final.
 */
final class MotoFilmRenderService
{
    public const FORMAT = 'moto-film-render-v1';

    public const QUEUED = 'queued';

    public const COMPOSING = 'composing';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const RELOAD = 'Kế hoạch phim đã đổi so với trang đang mở — tải lại trang để xem bản mới trước khi dựng.';

    public function __construct(
        private readonly MotoFilmPlanService $plans,
        private readonly MotoClipSelectionService $selection,
        private readonly FilesystemFactory $storage,
    ) {}

    /**
     * @return array{0: VideoFinal, 1: string} the final this request queued, or the one an earlier send of the same operation made
     *
     * @throws RuntimeException when the queue or the tools are not ready, the page is stale, the plan is blocked, or a film of the project is still being cut
     */
    public function request(string $projectId, string $expectedHash, string $operationId, ?string $requesterId): array
    {
        if (! Str::isUuid($operationId)) {
            throw new RuntimeException('Mã thao tác dựng không hợp lệ — tải lại trang.');
        }

        $payloadHash = hash('sha256', json_encode([$projectId, $expectedHash], JSON_THROW_ON_ERROR));
        $replayed = $this->replayed($projectId, $operationId, $payloadHash);

        if ($replayed !== null) {
            return [$replayed, 'replayed'];
        }

        // A film is only ever cut by a background job: with no queue ready, nothing is taken in.
        $queue = $this->queueProblem();

        if ($queue !== null) {
            throw new RuntimeException('Chưa nhận lượt dựng: '.$queue);
        }

        $tools = $this->toolProblem();

        if ($tools !== null) {
            throw new RuntimeException('Chưa nhận lượt dựng: '.$tools);
        }

        return DB::transaction(function () use ($projectId, $expectedHash, $operationId, $payloadHash, $requesterId): array {
            // Lock first, read after, as an approval does: the plan frozen below is the one in force when the request is written, read from the revisions held.
            $preview = $this->plans->lockedPreview($projectId);
            $replayed = $this->replayed($projectId, $operationId, $payloadHash);

            if ($replayed !== null) {
                return [$replayed, 'replayed'];
            }

            if (! $preview['ready']) {
                throw new RuntimeException('Kế hoạch phim còn bị chặn: '.($preview['blockers'][0]['message'] ?? 'không rõ'));
            }

            if (! hash_equals((string) $preview['hash'], $expectedHash)) {
                throw new RuntimeException(self::RELOAD);
            }

            $plan = $preview['plan'];
            $sessionId = $this->session($projectId, $plan);
            VideoSession::query()->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $running = $this->running($sessionId);

            if ($running !== null) {
                throw new RuntimeException('Đang có một lượt dựng phim của dự án này ('.$running->status.', bắt đầu '.$running->created_at?->toDateTimeString().') — đợi nó xong.');
            }

            $stage = $this->plans->save($projectId, $preview);
            $snapshot = $this->snapshot($projectId, $plan, (string) $preview['hash'], (string) $stage->id, $operationId, $requesterId);
            $final = VideoFinal::query()->create([
                'session_id' => $sessionId,
                'revision' => ((int) VideoFinal::query()->where('session_id', $sessionId)->max('revision')) + 1,
                'status' => self::QUEUED,
                'plan_json' => $snapshot,
                'manifest_hash' => substr(CanonicalJson::hash($snapshot), strlen('sha256:')),
                'frozen_at' => now(),
            ]);
            VideoSessionEvent::create([
                'session_id' => $sessionId,
                'event_type' => 'moto_film_requested',
                'entity_type' => 'final',
                'entity_id' => $final->id,
                'payload_json' => ['film_plan_hash' => $snapshot['moto_film']['film_plan_hash'], 'requested_by' => $requesterId],
                'operation_id' => $operationId,
                'operation_payload_hash' => $payloadHash,
                'operation_result_json' => ['status' => self::QUEUED, 'final_id' => (string) $final->id],
            ]);

            ComposeMotoFilmJob::dispatch((string) $final->id)
                ->onConnection(VideoQueues::CONNECTION)
                ->onQueue(VideoQueues::name(VideoQueues::FFMPEG, $requesterId))
                ->beforeCommit();

            return [$final, 'queued'];
        }, attempts: 3);
    }

    /**
     * @return ?string why no film can be taken in now: the video queue is off, its connection cannot hold a job with its final in one transaction, or no supervisor is running to start a worker; null when a job would be picked up
     */
    public function queueProblem(): ?string
    {
        if (! VideoQueues::enabled()) {
            return 'hàng đợi video đang tắt (VIDEO_ASYNC_ENABLED) — final chỉ dựng bằng job chạy nền, không dựng trong request.';
        }

        $problem = VideoQueues::problem(VideoQueues::jobTimeout($this->budget()));

        if ($problem !== null) {
            return $problem;
        }

        $heartbeat = json_decode((string) @file_get_contents(VideoQueues::heartbeatPath()), true);
        $age = is_array($heartbeat) ? time() - (int) ($heartbeat['at'] ?? 0) : null;

        if ($age === null || $age > (int) config('video.async.supervisor.heartbeat_stale_seconds') || ($heartbeat['mode'] ?? null) !== 'run' || isset($heartbeat['error'])) {
            return 'bộ điều phối hàng đợi (video:queue-supervisor) không chạy'.($age === null ? '' : ' — nhịp tim cách đây '.$age.' s').'; xem php artisan video:queue-check.';
        }

        return null;
    }

    /**
     * The body of the job: cuts the frozen copy once, if this run is the one that takes it.
     */
    public function compose(string $finalId): void
    {
        $final = $this->claim($finalId);

        if ($final === null) {
            return;
        }

        try {
            $snapshot = (array) $final->plan_json;
            $film = (array) $snapshot['moto_film'];
            $sources = array_map(fn (array $clip): string => $this->storage->disk((string) $clip['disk'])->path((string) $clip['path']), (array) $snapshot['clips']);
            $executor = app(CompositionExecutor::class);
            $result = $executor->execute($snapshot, $sources, Deadline::in($executor->budgetSeconds()), new CompositionRun(
                finalId: (string) $final->id,
                manifestHash: (string) $final->manifest_hash,
            ), self::decorOf((array) $film['decor']));
        } catch (Throwable $e) {
            $this->fail($final, get_class($e).': '.$e->getMessage());

            return;
        }

        if (! $result->successful && $result->unresolved) {
            Log::warning('moto film: cut and checked but not recorded; video:final-recover reconciles it', ['final_id' => (string) $final->id, 'reasons' => $result->reasons]);

            return;
        }

        if (! $result->successful) {
            $this->fail($final, implode(' | ', $result->reasons));

            return;
        }

        $cuts = [];

        foreach ((array) $snapshot['clips'] as $i => $clip) {
            $row = $result->timeline[$i] ?? null;

            if ($row === null) {
                $this->fail($final, 'ket qua ghep khong co dong thoi gian cho clip '.($i + 1));

                return;
            }

            $cuts[] = ['render_id' => (string) $clip['render_id'], 'sequence_no' => $i + 1, 'start_ms' => (int) $row['start_ms'], 'duration_ms' => (int) $row['duration_ms']];
        }

        try {
            app(FinalCompositionReconciler::class)->complete($final, $cuts, (string) $result->path, (int) $result->frames);
        } catch (Throwable $e) {
            Log::warning('moto film: cut and checked but the row could not take it; video:final-recover reconciles it', ['final_id' => (string) $final->id, 'reason' => $e->getMessage()]);
        }
    }

    /** @return ?string why this machine cannot cut a film now: ffprobe or ffmpeg does not run; null when both do */
    public function toolProblem(): ?string
    {
        $probe = app(\App\Video\Media\MediaProbe::class);

        if ($probe instanceof \App\Video\Media\Mp4Probe && ($problem = $probe->problem()) !== null) {
            return 'ffprobe không chạy được — '.$problem;
        }

        $ffmpeg = app(\App\Video\Media\FfmpegRunner::class);

        if ($ffmpeg instanceof \App\Video\Media\FfmpegBinary && ($problem = $ffmpeg->problem()) !== null) {
            return 'ffmpeg không chạy được — '.$problem;
        }

        return null;
    }

    /** Closes a run a worker gave up on, only when nothing was cut: a run with a checked file is left to video:final-recover. */
    public function abandon(string $finalId, string $reason): void
    {
        $final = VideoFinal::query()->find($finalId);

        if ($final === null || ! in_array($final->status, [self::QUEUED, self::COMPOSING], true)) {
            return;
        }

        $verdict = app(FinalCompositionReconciler::class)->reconcile($final, false);

        if ($verdict['state'] === 'pending') {
            $this->fail($final, $reason);
        }
    }

    /**
     * What the Final Composition screen shows for a moto project: `composition` in the shape the screen draws for every project,
     * and `motoFilm` with the film's own parts (the plan, its blockers and warnings, the cards, what each final may do).
     * Opening the screen reads only and writes nothing.
     *
     * @return array{composition: array<string, mixed>, motoFilm: array<string, mixed>}
     */
    public function screen(string $projectId): array
    {
        $preview = null;
        $problem = null;

        try {
            $preview = $this->plans->preview($projectId);
        } catch (RuntimeException $e) {
            $problem = $e->getMessage();
        }

        $approvals = app(MotoFilmApprovalService::class);
        $selection = $approvals->selection($projectId);
        $plan = $preview['plan'] ?? null;
        $entries = collect($preview['manifest']['clips'] ?? [])->keyBy('shot_id');
        $clips = [];

        foreach ((array) ($preview['rows'] ?? []) as $row) {
            $entry = $entries->get($row['shot_id']);

            if ($row['render_id'] === null || $entry === null) {
                continue;
            }

            $clips[] = [
                'ordinal' => (int) $row['order'],
                'scene_code' => (string) $row['shot_id'],
                'title' => (string) $row['shot_id'],
                'render_id' => (string) $row['render_id'],
                'duration_ms' => (int) $row['used_duration_ms'],
                'used_ms' => (int) $row['used_duration_ms'],
                'used_start_ms' => (int) ($entry['used']['start_ms'] ?? 0),
                'width' => (int) ($entry['measured']['width'] ?? 0),
                'height' => (int) ($entry['measured']['height'] ?? 0),
                'thumbnail_url' => null,
                'file_url' => route('video-projects.scene-clip-file', [$projectId, $row['render_id']]),
            ];
        }

        $finals = [];
        $extra = [];
        $active = null;

        foreach ($this->finalsOf($projectId) as $final) {
            $row = $this->describe($final, $preview['hash'] ?? null);
            $receipt = $row['has_file'] ? $approvals->receipt($final) : null;
            $approved = ($selection['final_id'] ?? null) === $row['id'];
            $active ??= in_array($row['status'], [self::QUEUED, self::COMPOSING], true) && ! $row['stopped'] ? $row : null;
            $finals[] = [
                'id' => $row['id'],
                'status' => self::label($row, $approved),
                'file_name' => $receipt === null ? null : basename($receipt['path']),
                'video_url' => $receipt === null ? null : route('video-projects.final-file', [$projectId, $row['id']]),
                'duration_seconds' => (int) $final->duration_seconds,
                'width' => (int) data_get($final->plan_json, 'output.width', 0),
                'height' => (int) data_get($final->plan_json, 'output.height', 0),
                'created_at' => $final->created_at?->format('Y-m-d H:i'),
                'cuts' => collect((array) data_get($final->plan_json, 'moto_film.film_plan.clips', []))->mapWithKeys(static fn (array $clip): array => [(string) $clip['render_id'] => (int) $clip['timeline_start_ms']])->all(),
            ];
            $extra[$row['id']] = [
                'film_plan_hash' => $row['film_plan_hash'],
                'sha256' => $receipt['sha256'] ?? null,
                'approved' => $approved,
                'can_approve' => $receipt !== null && $row['current'] && ! $approved,
            ];
        }

        $sizes = array_values(array_unique(array_map(static fn (array $clip): string => $clip['width'].' × '.$clip['height'], array_filter($clips, static fn (array $clip): bool => $clip['width'] > 0 && $clip['height'] > 0))));
        $queue = $this->queueProblem();
        $tools = $this->toolProblem();
        $blockers = (array) ($preview['blockers'] ?? []);

        return [
            'composition' => [
                'revision' => (int) ($preview['manifest']['source']['revision'] ?? 0),
                'blocked' => match (true) {
                    $problem !== null => $problem,
                    $plan === null => 'Kế hoạch phim còn '.count($blockers).' điểm chặn — '.($blockers[0]['message'] ?? 'xem bảng bên dưới').'',
                    $queue !== null => 'Chưa nhận lượt dựng: '.$queue,
                    $tools !== null => 'Chưa nhận lượt dựng: '.$tools,
                    $active !== null => 'Bản final #'.$active['revision'].' đang '.$active['status'].' — đợi nó xong.',
                    default => null,
                },
                'clips' => $clips,
                'total_duration_ms' => (int) ($plan['timeline']['total_ms'] ?? array_sum(array_column((array) ($preview['rows'] ?? []), 'used_duration_ms'))),
                'sizes' => $sizes,
                'uniform_size' => count($sizes) === 1 ? $sizes[0] : null,
                'finals' => $finals,
                'latest_final' => $finals[0] ?? null,
            ],
            'motoFilm' => [
                'preview' => $preview,
                'problem' => $problem,
                'hash' => $preview['hash'] ?? null,
                'operation_id' => (string) Str::uuid(),
                'output' => $plan['output'] ?? null,
                'finals' => $extra,
                'active' => $active,
            ],
        ];
    }

    /** @param array<string, mixed> $row describe() */
    private static function label(array $row, bool $approved): string
    {
        return match (true) {
            $row['stopped'] => $row['status'].' — dừng giữa chừng',
            $row['status'] === self::FAILED => 'failed: '.Str::limit((string) $row['error'], 140),
            $row['status'] === self::READY => 'ready'.($approved ? ' · đã duyệt' : '').($row['current'] ? '' : ' · kế hoạch cũ'),
            default => (string) $row['status'],
        };
    }

    /**
     * @return list<array<string, mixed>> the finals of the project's films, newest first, with whether each was cut from the plan the page shows now
     */
    public function history(string $projectId, ?string $currentHash): array
    {
        $rows = [];

        foreach ($this->finalsOf($projectId) as $final) {
            $rows[] = $this->describe($final, $currentHash);
        }

        return $rows;
    }

    /** @return array<string, mixed> a final as the screen and the status poll show it; reading it writes nothing */
    public function describe(VideoFinal $final, ?string $currentHash): array
    {
        $film = (array) ($final->plan_json['moto_film'] ?? []);
        $stale = $this->isStale($final);

        return [
            'id' => (string) $final->id,
            'revision' => (int) $final->revision,
            'status' => (string) $final->status,
            'stopped' => $stale,
            'error' => $final->error_message,
            'film_plan_hash' => (string) ($film['film_plan_hash'] ?? ''),
            'current' => $currentHash !== null && hash_equals((string) ($film['film_plan_hash'] ?? ''), $currentHash),
            'requested_at' => $final->created_at?->toDateTimeString(),
            'updated_at' => $final->updated_at?->toDateTimeString(),
            'duration_seconds' => $final->duration_seconds,
            'has_file' => $final->status === self::READY && is_string($final->video_path) && $final->video_path !== '',
        ];
    }

    /**
     * @return VideoFinal the final of a film of this project
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function ownedFinal(string $projectId, string $finalId): VideoFinal
    {
        return VideoFinal::query()
            ->whereKey($finalId)
            ->whereIn('session_id', VideoSession::query()->where('project_id', $projectId)->select('id'))
            ->where('plan_json->moto_film->format', self::FORMAT)
            ->where('plan_json->moto_film->project_id', $projectId)
            ->firstOrFail();
    }

    /** @return \Illuminate\Support\Collection<int, VideoFinal> */
    private function finalsOf(string $projectId): \Illuminate\Support\Collection
    {
        return VideoFinal::query()
            ->whereIn('session_id', VideoSession::query()->where('project_id', $projectId)->select('id'))
            ->where('plan_json->moto_film->format', self::FORMAT)
            ->where('plan_json->moto_film->project_id', $projectId)
            ->orderByDesc('created_at')
            ->orderByDesc('revision')
            ->limit(50)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $plan  a ready film plan
     *
     * @throws RuntimeException when its clips are not this project's or do not share one session
     */
    private function session(string $projectId, array $plan): string
    {
        $sessions = [];

        foreach ($plan['clips'] as $clip) {
            try {
                $render = $this->selection->ownedRender($projectId, (string) $clip['render_id']);
            } catch (Throwable) {
                throw new RuntimeException('Clip '.$clip['shot_id'].' của kế hoạch phim không thuộc dự án này — không dựng.');
            }

            if (! hash_equals((string) $clip['artifact']['sha256'], (string) $render->primary_artifact_hash)) {
                throw new RuntimeException('Clip '.$clip['shot_id'].': hash file đã đổi so với kế hoạch phim — tải lại trang.');
            }

            $sessions[(string) $render->shot?->session_id] = true;
        }

        if (count($sessions) !== 1 || array_key_first($sessions) === '') {
            throw new RuntimeException('Các clip của phim thuộc '.count($sessions).' session — bản này chỉ dựng trong một session.');
        }

        return (string) array_key_first($sessions);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed> the plan_json of the final: the FFmpeg manifest of the cut, in the shape the composer and video:final-recover read, with the film it was frozen from
     */
    private function snapshot(string $projectId, array $plan, string $hash, string $stageId, string $operationId, ?string $requesterId): array
    {
        return [
            'engine' => VideoFinal::ENGINE_LARAVEL,
            'output' => ['width' => $plan['output']['width'], 'height' => $plan['output']['height'], 'fps' => $plan['output']['fps'], 'crf' => $plan['output']['crf']],
            'clips' => array_map(static fn (array $clip): array => [
                'render_id' => $clip['render_id'],
                'sequence_no' => $clip['order'],
                'disk' => $clip['artifact']['disk'],
                'path' => $clip['artifact']['path'],
                'sha256' => $clip['artifact']['sha256'],
                'trim_start_ms' => $clip['trim_start_ms'],
                'duration_ms' => $clip['used_duration_ms'],
            ], $plan['clips']),
            'moto_film' => [
                'format' => self::FORMAT,
                'project_id' => $projectId,
                'film_plan' => $plan,
                'film_plan_hash' => $hash,
                'film_plan_stage_id' => $stageId,
                'clip_manifest_hash' => $plan['source']['clips']['hash'],
                'composer' => CompositionDecor::VERSION,
                'decor' => self::decor($plan),
                'operation_id' => $operationId,
                'requested_by' => $requesterId,
                'requested_at' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed> the dips and cards of the film as the composer draws them, frozen with the request
     */
    public static function decor(array $plan): array
    {
        $style = $plan['style'];

        return [
            'clips' => array_map(static fn (array $clip): array => ['frames' => $clip['duration_frames'], 'fade_in' => $clip['fade_in_frames'], 'fade_out' => $clip['fade_out_frames'], 'has_audio' => $clip['audio'] === 'clip'], $plan['clips']),
            'cards' => array_map(static fn (array $card): array => ['start_frame' => $card['start_frame'], 'end_frame' => $card['end_frame'], 'lines' => array_map(static fn (array $line): array => ['text' => $line['text'], 'baseline_px' => $line['baseline_px']], $card['lines'])], $plan['cards']),
            'font' => $plan['cards'] === [] ? null : ['path' => $style['font']['path'], 'sha256' => $style['font']['sha256'], 'size_px' => $style['size_px'], 'colour' => $style['colour'], 'border_colour' => $style['border']['colour'], 'border_px' => $style['border']['width_px']],
        ];
    }

    /** @param array<string, mixed> $decor decor() */
    public static function decorOf(array $decor): CompositionDecor
    {
        $font = $decor['font'] === null ? null : ['source' => base_path((string) $decor['font']['path'])] + array_diff_key((array) $decor['font'], ['path' => true]);

        return new CompositionDecor((array) $decor['clips'], (array) $decor['cards'], $font);
    }

    /** @return ?VideoFinal the final this run takes, with its status moved to composing; null when another run took it or it waited too long */
    private function claim(string $finalId): ?VideoFinal
    {
        $wait = (int) config('video.films.queue_wait_seconds');
        $claimed = VideoFinal::query()
            ->whereKey($finalId)
            ->where('status', self::QUEUED)
            ->where('created_at', '>=', now()->subSeconds($wait))
            ->update(['status' => self::COMPOSING, 'updated_at' => now()]);

        if ($claimed === 1) {
            return VideoFinal::query()->find($finalId);
        }

        $expired = VideoFinal::query()->whereKey($finalId)->where('status', self::QUEUED)->first();

        if ($expired !== null) {
            $this->fail($expired, 'Chờ quá '.$wait.' giây mà chưa có worker nhận — đóng lại, không dựng muộn. Bấm dựng lại nếu vẫn cần.', self::QUEUED);
        }

        return null;
    }

    private function fail(VideoFinal $final, string $reason, string $from = self::COMPOSING): void
    {
        VideoFinal::query()->whereKey($final->id)->where('status', $from)->whereNull('video_path')
            ->update(['status' => self::FAILED, 'error_message' => Str::limit($reason, 1000)]);
    }

    /** @return ?VideoFinal a final of the session that may still be cutting: queued within the wait, or composing within the budget */
    private function running(string $sessionId): ?VideoFinal
    {
        foreach (VideoFinal::query()->where('session_id', $sessionId)->whereIn('status', [self::QUEUED, self::COMPOSING])->get() as $final) {
            if (! $this->isStale($final)) {
                return $final;
            }
        }

        return null;
    }

    /** @return bool whether a queued or composing final can no longer be running: it waited past the queue wait, or composed past the budget */
    private function isStale(VideoFinal $final): bool
    {
        return match ($final->status) {
            self::QUEUED => $final->created_at === null || $final->created_at->lt(now()->subSeconds((int) config('video.films.queue_wait_seconds'))),
            self::COMPOSING => $final->updated_at === null || $final->updated_at->lt(now()->subSeconds($this->budget() + 60)),
            default => false,
        };
    }

    private function budget(): int
    {
        return (int) config('video.veo.compose_budget_seconds');
    }

    private function replayed(string $projectId, string $operationId, string $payloadHash): ?VideoFinal
    {
        $event = VideoSessionEvent::query()
            ->whereIn('session_id', VideoSession::query()->where('project_id', $projectId)->select('id'))
            ->where('operation_id', $operationId)
            ->first();

        if ($event === null) {
            return null;
        }

        if ($event->event_type !== 'moto_film_requested' || ! hash_equals((string) $event->operation_payload_hash, $payloadHash)) {
            throw new RuntimeException('Mã thao tác này đã dùng cho một yêu cầu khác — tải lại trang.');
        }

        return VideoFinal::query()->find($event->entity_id) ?? throw new RuntimeException('Lượt dựng của mã thao tác này không còn.');
    }
}
