<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\DesignImageStatus;
use App\Jobs\Video\RenderDesignImageJob;
use App\Models\VideoDesignImage;
use App\Video\Media\SpecRouting;
use App\Video\Queue\ProviderRateLimit;
use App\Video\Queue\VideoQueues;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ImageRenderQueue
{
    public const WAITING = 'waiting';

    public const SENDING = 'sending';

    /** @var array<string, class-string<QueuedImageService>> the cell renders a worker may send, each with the service that checks its source and sends it */
    public const STEPS = [
        'model_sheet' => ModelSheetService::class,
        'location' => LocationReferenceService::class,
        'parts_detail' => PartsDetailService::class,
    ];

    public function __construct(private readonly DesignImageQueue $images) {}

    /** @return int the job budget: the lease of the claimed cell, then the margin for the work after the call, such as cutting a model sheet */
    public static function jobSeconds(): int
    {
        return VideoQueues::jobTimeout(DesignImageDirectRenderer::leaseSeconds());
    }

    /**
     * @param  \Closure(): array{0: ?VideoDesignImage, 1: string}  $create  makes the cell, or finds the one this operation already made
     * @param  \Closure(VideoDesignImage, string): ?array{0: ?VideoDesignImage, 1: string}  $settle  the answer for a cell that must not be queued, null to queue it
     * @param  ?array{id: string, name: string}  $requester  who sent the render; the job waits on that person's image queue
     * @return array{0: ?VideoDesignImage, 1: string} reason queued when the cell and its job were stored together; a cell is never kept without its job
     */
    public function createQueued(string $step, \Closure $create, \Closure $settle, ?array $requester): array
    {
        DB::beginTransaction();

        try {
            [$image, $reason] = $create();
            $answer = $image === null ? [null, $reason] : $settle($image, $reason);

            if ($answer === null) {
                $problem = $this->submit($step, (string) $image->id, $requester);
                $answer = $problem === null ? [$image->fresh() ?? $image, 'queued'] : [null, 'Chưa đưa vào hàng đợi, không render: '.$problem];
            }

            $answer[0] === null ? DB::rollBack() : DB::commit();

            return $answer;
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }
    }

    /**
     * @param  ?array{id: string, name: string}  $requester
     * @return ?string why no job waits for the cell, null when one waits for it now, new or already there
     */
    public function submit(string $step, string $imageId, ?array $requester): ?string
    {
        if (! isset(self::STEPS[$step])) {
            throw new InvalidArgumentException("Bước ảnh {$step} không chạy được bằng hàng đợi.");
        }

        $problem = VideoQueues::problem(self::jobSeconds());

        if ($problem !== null) {
            return $problem;
        }

        return DB::transaction(function () use ($step, $imageId, $requester): ?string {
            $image = VideoDesignImage::query()->lockForUpdate()->find($imageId);

            if ($image === null) {
                return 'Không tìm thấy ô ảnh.';
            }

            if (self::busy($image)) {
                return null;
            }

            if (! in_array($image->status, DesignImageStatus::enqueueableValues(), true)) {
                return 'Ô ảnh đang render hoặc đã có ảnh — không xếp hàng thêm.';
            }

            $this->enqueue($image, ['step' => $step, 'requested_by' => $requester, 'queued_at' => time()], 0);

            return null;
        });
    }

    /** @return array{0: ?VideoDesignImage, 1: string} what the queued render became; a job whose token is no longer the cell's sends nothing */
    public function run(string $step, string $imageId, string $token): array
    {
        config(['video.async.rate_limit.client_retry' => false]);
        $service = app(self::STEPS[$step] ?? throw new InvalidArgumentException("Bước ảnh {$step} không chạy được bằng hàng đợi."));
        $image = $this->take($imageId, $token);

        if ($image === null) {
            return [null, 'Lượt render này đã bị huỷ, đã chạy hoặc đã có lượt mới thay — bỏ qua, không gọi provider.'];
        }

        try {
            $problem = $service->checkQueued($image);

            if ($problem !== null) {
                $this->images->refuse($imageId, $problem);

                return [null, $problem];
            }

            $spec = (array) $image->prompt_spec_json;
            $provider = SpecRouting::provider($spec);
            $model = (string) ($spec['model'] ?? '');
            $cooldown = ProviderRateLimit::cooldownRemaining($provider, $model);

            if ($cooldown > 0) {
                return $this->wait($image, $token, $provider, $model, null, $cooldown, 'cooldown', '');
            }

            $this->markSent($imageId, $token);
            [$done, $reason, $refusal] = $service->sendQueued($image);
            $limit = $reason === 'failed' && is_array($refusal) ? ProviderRateLimit::ofImage($provider, $refusal) : null;

            if (($limit['kind'] ?? null) === ProviderRateLimit::RATE_LIMIT) {
                Log::warning('image render queue: the provider limited the rate, the cell waits in the queue', ['image_id' => $imageId, 'provider' => $provider, 'retry_after' => $limit['retry_after']]);

                return $this->wait($image, $token, $provider, $model, $limit['retry_after'], 0, ProviderRateLimit::RATE_LIMIT, (string) ($refusal['message'] ?? ''));
            }

            if (($limit['kind'] ?? null) === ProviderRateLimit::QUOTA) {
                $message = 'Hết hạn mức hoặc tín dụng tài khoản '.$provider.' — cần người can thiệp, không tự thử lại: '.($refusal['message'] ?? '');
                VideoDesignImage::query()->whereKey($imageId)->where('status', DesignImageStatus::FAILED->value)->update(['render_error' => Str::limit($message, 1000)]);

                return [null, $message];
            }

            return [$done, $reason];
        } catch (\Throwable $e) {
            $this->abandon($imageId, $token, $e->getMessage());

            throw $e;
        } finally {
            $this->finish($imageId, $token);
        }
    }

    /** @return void the run of this token is over: the cell no longer waits for it */
    public function finish(string $imageId, string $token): void
    {
        DB::transaction(function () use ($imageId, $token): void {
            $image = VideoDesignImage::query()->lockForUpdate()->find($imageId);
            $queue = (array) ($image?->render_queue ?? []);

            if ($image !== null && ($queue['token'] ?? null) === $token) {
                $image->forceFill(['render_queue' => ['token' => null, 'state' => 'done', 'ended_at' => time()] + $queue])->save();
            }
        });
    }

    /**
     * @param  string  $reason  why the run stopped
     * @return void the run of this token stopped on an error: before the provider was called, the cell is marked failed with the reason and nothing was billed; once it was called, the outcome is unknown and the cell is left to its lease, which marks it failed when it expires
     */
    public function abandon(string $imageId, string $token, string $reason): void
    {
        DB::transaction(function () use ($imageId, $token, $reason): void {
            $image = VideoDesignImage::query()->lockForUpdate()->find($imageId);
            $queue = (array) ($image?->render_queue ?? []);

            if ($image === null || ($queue['token'] ?? null) !== $token) {
                return;
            }

            $unsent = $image->status === DesignImageStatus::CANDIDATE->value
                || (! is_int($queue['sent_at'] ?? null) && $image->status === DesignImageStatus::FAILED->value);
            $image->forceFill([
                'render_queue' => ['token' => null, 'state' => 'stopped', 'ended_at' => time()] + $queue,
            ] + ($unsent ? [
                'status' => DesignImageStatus::FAILED->value,
                'render_error' => Str::limit('Tiến trình chạy nền dừng trước khi gửi provider, chưa tính phí: '.$reason, 1000),
            ] : []))->save();
        });
    }

    /**
     * @param  \Closure(string): bool  $hasJob  whether the queue still holds a job naming the token
     * @return int the cells whose waiting job is gone, freed so the page offers the render again; every waiting cell old enough is checked, page by page, up to $limit freed
     */
    public function recoverOrphans(int $limit, int $graceSeconds, \Closure $hasJob): int
    {
        $freed = 0;
        $due = time() - $graceSeconds;

        VideoDesignImage::query()
            ->whereNotNull('render_queue')
            ->where('render_queue->state', self::WAITING)
            ->whereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(render_queue, '$.retry_at')), JSON_UNQUOTE(JSON_EXTRACT(render_queue, '$.queued_at'))) + 0 <= ?", [$due])
            ->select('id')
            ->chunkById(100, function ($rows) use (&$freed, $limit, $due, $hasJob): bool {
                foreach ($rows as $row) {
                    $freed += $this->free((string) $row->id, $due, $hasJob);

                    if ($freed >= $limit) {
                        return false;
                    }
                }

                return true;
            });

        return $freed;
    }

    /** @return int 1 when the waiting cell has no job left and was freed */
    private function free(string $imageId, int $due, \Closure $hasJob): int
    {
        return DB::transaction(function () use ($imageId, $due, $hasJob): int {
            $image = VideoDesignImage::query()->lockForUpdate()->find($imageId);
            $queue = (array) ($image?->render_queue ?? []);
            $token = $queue['token'] ?? null;

            if ($image === null || ! is_string($token) || ($queue['state'] ?? null) !== self::WAITING
                || (int) ($queue['retry_at'] ?? $queue['queued_at'] ?? 0) > $due || $hasJob($token)) {
                return 0;
            }

            $image->forceFill(['render_queue' => ['token' => null, 'state' => 'lost', 'ended_at' => time()] + $queue])->save();

            return 1;
        });
    }

    private function markSent(string $imageId, string $token): void
    {
        DB::transaction(function () use ($imageId, $token): void {
            $image = VideoDesignImage::query()->lockForUpdate()->find($imageId);
            $queue = (array) ($image?->render_queue ?? []);

            if ($image !== null && ($queue['token'] ?? null) === $token) {
                $image->forceFill(['render_queue' => ['sent_at' => time()] + $queue])->save();
            }
        });
    }

    /** @return ?array{kind: string, id: string, status: string, requested_by: ?string, queued_at: ?string, started_at: ?string, retry_at: ?int} the render of a cell while its job waits or a worker sends it, null for every other cell */
    public static function taskOf(VideoDesignImage $image): ?array
    {
        $queue = (array) ($image->render_queue ?? []);
        $busy = self::busy($image);
        $waiting = $busy && ($queue['state'] ?? null) === self::WAITING;

        if (! $busy && ! in_array($image->status, DesignImageStatus::leasedValues(), true)) {
            return null;
        }

        $stamp = static fn (mixed $seconds): ?string => is_int($seconds) ? date(DATE_ATOM, $seconds) : null;

        return [
            'kind' => 'images',
            'id' => (string) $image->id,
            'status' => $waiting ? 'pending' : 'running',
            'requested_by' => $busy && is_string($queue['requested_by']['name'] ?? null) ? $queue['requested_by']['name'] : null,
            'queued_at' => $busy ? $stamp($queue['queued_at'] ?? null) : null,
            'started_at' => $image->claimed_at?->toIso8601String() ?? ($busy ? $stamp($queue['sending_at'] ?? null) : null),
            'retry_at' => $waiting && is_int($queue['retry_at'] ?? null) ? $queue['retry_at'] : null,
        ];
    }

    /** @return ?array<string, mixed> taskOf() of the cell with this id */
    public static function taskFor(string $imageId): ?array
    {
        $image = VideoDesignImage::query()->find($imageId, ['id', 'status', 'claimed_at', 'render_queue']);

        return $image === null ? null : self::taskOf($image);
    }

    private static function busy(VideoDesignImage $image): bool
    {
        $queue = (array) ($image->render_queue ?? []);

        if (! is_string($queue['token'] ?? null)) {
            return false;
        }

        return match ($queue['state'] ?? null) {
            self::WAITING => true,
            self::SENDING => (int) ($queue['sending_at'] ?? 0) + self::jobSeconds() > time(),
            default => false,
        };
    }

    /** @param array<string, mixed> $queue what the new token waits with: the step, who sent it, and any rate-limit wait so far */
    private function enqueue(VideoDesignImage $image, array $queue, int $delay): void
    {
        $token = (string) Str::uuid();
        $image->forceFill(['render_queue' => ['token' => $token, 'state' => self::WAITING, 'sending_at' => null] + $queue])->save();
        $requester = $queue['requested_by']['id'] ?? null;

        RenderDesignImageJob::dispatch((string) $queue['step'], (string) $image->id, $token)
            ->onConnection(VideoQueues::CONNECTION)
            ->onQueue(VideoQueues::name(VideoQueues::IMAGE, is_string($requester) ? $requester : null))
            ->delay($delay)
            ->beforeCommit();
    }

    private function take(string $imageId, string $token): ?VideoDesignImage
    {
        return DB::transaction(function () use ($imageId, $token): ?VideoDesignImage {
            $image = VideoDesignImage::query()->lockForUpdate()->find($imageId);
            $queue = (array) ($image?->render_queue ?? []);

            if ($image === null || ($queue['token'] ?? null) !== $token || ($queue['state'] ?? null) !== self::WAITING) {
                return null;
            }

            $image->forceFill(['render_queue' => ['state' => self::SENDING, 'sending_at' => time()] + $queue])->save();

            return $image;
        });
    }

    /** @return array{0: ?VideoDesignImage, 1: string} the cell queued again with a new token after the wait, or failed once the wait would pass the limit */
    private function wait(VideoDesignImage $image, string $token, string $provider, string $model, ?int $retryAfter, int $cooldown, string $reason, string $detail): array
    {
        $queue = (array) ($image->render_queue ?? []);
        $attempt = (int) ($queue['attempts'] ?? 0) + 1;
        $firstAt = (int) ($queue['first_at'] ?? time());
        $maxWait = (int) config('video.async.rate_limit.max_wait_seconds');
        $delay = ProviderRateLimit::delay($retryAfter, $attempt, max($cooldown, ProviderRateLimit::cooldownRemaining($provider, $model)));

        if ($reason === ProviderRateLimit::RATE_LIMIT) {
            ProviderRateLimit::coolDown($provider, $model, $delay);
        }

        if (time() + $delay > $firstAt + $maxWait) {
            $message = "{$provider} vẫn giới hạn tốc độ sau {$attempt} lần chờ — hết thời hạn chờ ".intdiv($maxWait, 60).' phút, không gửi lại. Bấm render lại sau.'.($detail === '' ? '' : ' '.$detail);
            $this->images->refuse((string) $image->id, Str::limit($message, 1000));

            return [null, $message];
        }

        $queued = DB::transaction(function () use ($image, $token, $queue, $attempt, $firstAt, $delay, $reason, $detail): bool {
            $locked = VideoDesignImage::query()->lockForUpdate()->find($image->id);
            $current = (array) ($locked?->render_queue ?? []);

            if ($locked === null || ($current['token'] ?? null) !== $token || ! in_array($locked->status, DesignImageStatus::enqueueableValues(), true)) {
                return false;
            }

            $this->enqueue($locked, [
                'step' => $queue['step'] ?? null,
                'requested_by' => $queue['requested_by'] ?? null,
                'queued_at' => $queue['queued_at'] ?? time(),
                'attempts' => $attempt,
                'first_at' => $firstAt,
                'retry_at' => time() + $delay,
                'reason' => $reason,
                'detail' => mb_substr($detail, 0, 300),
            ], $delay);

            return true;
        });

        return $queued ? [$image, 'requeued'] : [null, 'Ô ảnh không còn chờ lượt này — không xếp hàng lại.'];
    }
}
