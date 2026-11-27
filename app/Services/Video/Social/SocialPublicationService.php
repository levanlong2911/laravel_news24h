<?php

declare(strict_types=1);

namespace App\Services\Video\Social;

use App\Jobs\Video\PublishSocialPackageJob;
use App\Models\VideoArtifact;
use App\Models\VideoSocialChannel;
use App\Models\VideoSocialEvent;
use App\Models\VideoSocialPackage;
use App\Models\VideoSocialPublication;
use App\Video\Queue\VideoQueues;
use App\Video\Social\SocialClaimLost;
use App\Video\Social\SocialPlatformError;
use App\Video\Social\SocialPolicy;
use App\Video\Social\SocialRedaction;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The postings of approved package revisions: the ones a person made by hand and reports, and the ones sent through
 * the API of a platform.
 *
 * A request to post is taken in inside a short transaction — approval, revision, hash, target, the approved film,
 * policy and the key of the request are looked at there, and one row is written. A worker takes the row, looks at all
 * of it again — and at the adapter, the payload hash and the schedule — and sends outside any transaction, storing
 * what the platform gave back after every step. Before every request to a platform the worker proves it still holds
 * the row; a worker whose claim was taken over stops at once and sends nothing more. The same request sent twice is
 * one posting; two workers cannot both take one row.
 *
 * Exactly-once is not promised where a platform gives no way to promise it. A step that got no answer leaves the
 * posting `unknown`: it is looked up with the ids the platform already gave, it is never sent again on a guess, it
 * never goes back to `queued`, and when the platform cannot say either, a person looks and records what they saw.
 *
 * With the queue on, a posting the platform still processes or holds a time for is followed by one look-up job after
 * another, within a window; past the window, or on an error no later look-up can mend, the posting says a person must look.
 */
final class SocialPublicationService
{
    /** @var list<string> the statuses a posting may be looked up from */
    private const FOLLOWED = [VideoSocialPublication::UPLOADING, VideoSocialPublication::PROCESSING, VideoSocialPublication::SCHEDULED, VideoSocialPublication::UNKNOWN];

    /** @var list<string> the statuses the follow-up jobs keep looking at; an unknown posting waits for a person */
    private const CHAINED = [VideoSocialPublication::PROCESSING, VideoSocialPublication::SCHEDULED];

    /** A profile taken out of use is no target of a new posting through the API, as it is no target of a new approval; its packages are still exported and posted by hand. */
    public const ARCHIVED = 'Hồ sơ kênh của gói đã được lưu trữ — không đăng qua API. Gói vẫn tải về được để đăng tay rồi ghi nhận.';

    public function __construct(
        private readonly SocialSourceService $sources,
        private readonly SocialPackageService $packages,
        private readonly SocialConnectionService $connections,
    ) {}

    /**
     * Records a posting a person made by hand. It is their word: nothing on the platform is compared with the package.
     *
     * @param  string  $postedAt  when it was posted, as a person reads the clock of the channel
     * @return array{0: VideoSocialPublication, 1: string} the posting and reported|replayed
     *
     * @throws RuntimeException when the revision is not approved, is not the one the page showed, or the URL or the time is not usable
     */
    public function reportManual(string $projectId, string $packageId, string $expectedHash, string $requestKey, string $url, string $postedAt, ?string $actorId): array
    {
        $package = $this->approvedRevision($projectId, $packageId, $expectedHash);
        $url = trim($url);

        if (preg_match('/^https?:\/\/\S+$/i', $url) !== 1 || mb_strlen($url) > 500) {
            throw new RuntimeException('URL bài đăng phải là một địa chỉ http(s) đầy đủ, tối đa 500 ký tự.');
        }

        $at = self::localTime($postedAt, (string) $package->source_json['channel']['timezone']);

        if ($at->gt(now()->addMinutes(5))) {
            throw new RuntimeException('Thời điểm đăng nằm ở tương lai — ghi nhận đăng tay chỉ dành cho bài đã đăng.');
        }

        return $this->once($requestKey, $packageId, function () use ($projectId, $package, $requestKey, $url, $at, $actorId): VideoSocialPublication {
            $publication = VideoSocialPublication::create([
                'package_revision_id' => $package->id,
                'package_hash' => $package->package_hash,
                'project_id' => $projectId,
                'channel_id' => $package->channel_id,
                'method' => VideoSocialPublication::MANUAL,
                'status' => VideoSocialPublication::MANUAL_REPORTED,
                'request_key' => $requestKey,
                'intent' => $this->posted($package) ? 'repost' : 'first',
                'external_url' => $url,
                'published_at' => $at,
                'requested_by' => $actorId,
                'confirmed_by' => $actorId,
                'steps_json' => [],
            ]);
            VideoSocialEvent::record($projectId, 'manual_reported', (string) $package->id, (string) $publication->id, $actorId, ['url' => $url, 'published_at' => $at->toIso8601String(), 'note' => 'Reported by a person. The posting on the platform was not compared with the package.']);

            return $publication;
        }, 'reported');
    }

    /**
     * Takes in a request to post an approved revision through the API, now or at a time the platform will hold.
     *
     * @param  ?string  $scheduledAt  the time to publish at, as a person reads the clock of the channel; null to post now
     * @param  bool  $again  the person means a further posting of a revision that already has one
     * @return array{0: VideoSocialPublication, 1: string} the posting and queued|replayed
     *
     * @throws RuntimeException when posting is switched off, the revision is not approved or not the one shown, the page or channel its profile names is not the one approved, the film or the policy has changed, the channel has no usable connection, the time is not one the platform takes, or the revision already has a posting and no further one was asked for
     */
    public function request(string $projectId, string $packageId, string $expectedHash, string $requestKey, ?string $scheduledAt, bool $again, ?string $actorId): array
    {
        $package = $this->approvedRevision($projectId, $packageId, $expectedHash);
        $source = (array) $package->source_json;
        $rules = (array) $source['policy']['rules'];
        $platform = (string) $rules['platform'];

        if (! SocialConnectionService::publishing($platform)) {
            throw new RuntimeException("Đăng {$platform} qua API đang tắt — tải gói về và đăng tay.");
        }

        $channel = VideoSocialChannel::query()->with('connection')->findOrFail($package->channel_id);

        if ($channel->status !== VideoSocialChannel::ACTIVE) {
            throw new RuntimeException(self::ARCHIVED);
        }

        // The approval is of the page or channel the revision names. A connection that reaches the page the profile names now does not make it that one.
        if (($target = SocialChannelService::targetProblem((array) $source['channel'], $channel)) !== null) {
            throw new RuntimeException($target.' — không gửi. '.SocialChannelService::RETARGET);
        }

        $problems = $this->connections->problems($channel);

        if ($problems !== []) {
            throw new RuntimeException(implode(' ', $problems));
        }

        $publisher = SocialConnectionService::publisher($platform);

        $adapter = $publisher->adapter();

        if ($rules['api']['adapter'] !== $adapter) {
            throw new RuntimeException("Chính sách của gói viết cho adapter {$rules['api']['adapter']}; code đang có {$adapter} — cập nhật gói theo chính sách hiện hành.");
        }

        $document = (array) $package->document_json;
        $at = $scheduledAt === null || trim($scheduledAt) === '' ? null : self::localTime($scheduledAt, (string) $source['channel']['timezone']);

        if ($at !== null && ($problem = SocialPolicy::scheduleProblem($rules, $document['metadata']['privacy'] ?? null, $at, now())) !== null) {
            throw new RuntimeException($problem);
        }

        // The film is hashed before any lock is taken.
        $read = $this->sources->read($projectId);

        if (! hash_equals((string) $package->source_hash, (string) $read['snapshot']['hash'])) {
            throw new RuntimeException('Phim đã duyệt của dự án đã đổi: gói này tham chiếu bản phim trước nên không gửi. Tạo revision cho phim hiện hành, duyệt rồi đăng bản đó.');
        }

        if (($cover = SocialApprovalService::coverProblem($package)) !== null) {
            throw new RuntimeException('Cover của gói: '.$cover.' — không gửi.');
        }

        $fields = $publisher->payload($document, $rules, $at);
        $payload = [
            'adapter' => $adapter,
            'fields' => $fields,
            'film' => ['sha256' => (string) $read['snapshot']['film']['sha256'], 'bytes' => (int) $read['snapshot']['film']['media']['bytes']],
            'cover' => is_array($document['artwork'] ?? null) ? ['artifact_id' => (string) $document['artwork']['artifact_id'], 'sha256' => (string) $document['artwork']['sha256']] : null,
        ];

        [$publication, $reason] = $this->once($requestKey, $packageId, function () use ($projectId, $packageId, $expectedHash, $requestKey, $payload, $at, $again, $actorId): VideoSocialPublication {
            $package = VideoSocialPackage::query()->whereKey($packageId)->where('project_id', $projectId)->lockForUpdate()->firstOrFail();

            if (! SocialApprovalService::approved($package) || ! hash_equals((string) $package->package_hash, $expectedHash)) {
                throw new RuntimeException('Bản này không còn ở trạng thái đã duyệt — không gửi.');
            }

            $contract = $this->sources->policy($projectId);
            $this->sources->hold($projectId, $contract);

            if (! hash_equals((string) $package->source_json['policy']['policy_hash'], (string) $contract['contract']['policy_hash'])) {
                throw new RuntimeException('Chính sách nền tảng đã có revision mới so với lúc duyệt gói — gói giữ nguyên trong lịch sử; tạo revision theo chính sách hiện hành, duyệt rồi đăng bản đó.');
            }

            // The file was hashed before this transaction; here the selection of the film and the profile of the target are held and looked at again.
            $moved = $this->sources->selected($projectId, (array) $package->source_json['pins']);

            if ($moved !== null) {
                throw new RuntimeException('Phim đã duyệt của dự án vừa đổi ('.$moved.') — không gửi. Tạo revision cho phim hiện hành, duyệt rồi đăng bản đó.');
            }

            $channel = VideoSocialChannel::query()->whereKey($package->channel_id)->sharedLock()->first();
            $target = $channel === null ? 'Hồ sơ kênh của gói không còn' : SocialChannelService::targetProblem((array) $package->source_json['channel'], $channel);

            if ($target !== null) {
                throw new RuntimeException($target.' — không gửi. '.SocialChannelService::RETARGET);
            }

            $standing = VideoSocialPublication::query()->where('package_revision_id', $package->id)->where('method', VideoSocialPublication::API)
                ->whereIn('status', [...VideoSocialPublication::OPEN, VideoSocialPublication::PUBLISHED])->lockForUpdate()->get();

            if ($standing->isNotEmpty() && ! $again) {
                throw new RuntimeException('Bản này đã có một lượt đăng API ('.$standing->first()->status.'). Gửi lại do lỗi mạng thì không cần làm gì thêm; nếu chủ ý đăng thêm một bài mới, chọn "Đăng thêm một bài mới" rồi gửi.');
            }

            $publication = VideoSocialPublication::create([
                'package_revision_id' => $package->id,
                'package_hash' => $package->package_hash,
                'project_id' => $projectId,
                'channel_id' => $package->channel_id,
                'method' => VideoSocialPublication::API,
                'status' => VideoSocialPublication::QUEUED,
                'request_key' => $requestKey,
                'intent' => $standing->isNotEmpty() || $this->posted($package) ? 'repost' : 'first',
                'scheduled_at' => $at,
                'requested_by' => $actorId,
                'payload_json' => $payload,
                'payload_hash' => CanonicalJson::hash(['adapter' => $payload['adapter'], 'fields' => $payload['fields']]),
                'adapter_version' => $payload['adapter'],
                'steps_json' => [],
            ]);
            VideoSocialEvent::record($projectId, 'publication_queued', (string) $package->id, (string) $publication->id, $actorId, ['payload_hash' => $publication->payload_hash, 'scheduled_at' => $at?->toIso8601String(), 'intent' => $publication->intent]);

            return $publication;
        }, 'queued');

        if ($reason === 'queued') {
            $this->dispatch((string) $publication->id, 'work', $actorId, 0);
        }

        return [$publication->fresh(), $reason];
    }

    /**
     * What a worker does with a queued posting: takes it — one worker only —, looks at everything again, and sends.
     */
    public function work(string $publicationId): void
    {
        $token = (string) Str::uuid();
        // A posting still queued has sent nothing — a worker moves it to uploading before the first request — so the claim of a worker that died before that may be taken over.
        $taken = $this->free(VideoSocialPublication::query()->whereKey($publicationId)->where('method', VideoSocialPublication::API)->where('status', VideoSocialPublication::QUEUED))
            ->update(['claim_token' => $token, 'claimed_at' => now()]);

        if ($taken !== 1) {
            return;
        }

        $publication = VideoSocialPublication::query()->with(['package', 'channel.connection'])->findOrFail($publicationId);

        try {
            $this->send($publication, $token);
        } catch (SocialClaimLost $e) {
            // Another process holds the posting now: this worker sent nothing after it noticed, and writes nothing.
            Log::warning('social publication: the worker lost its claim and stopped', ['publication_id' => $publicationId, 'reason' => $e->getMessage()]);
        } catch (\Throwable $e) {
            // The message of an error may hold the URL of a request, and a URL may hold a token: it is kept and logged only redacted.
            $said = SocialRedaction::text($e->getMessage());
            Log::error('social publication: the worker stopped on an error of its own', ['publication_id' => $publicationId, 'error' => get_class($e).': '.$said, 'at' => $e->getFile().':'.$e->getLine()]);
            $sent = $publication->fresh()?->status !== VideoSocialPublication::QUEUED;

            try {
                // Whatever broke here, a posting whose upload had begun is not known to have failed.
                $this->advance($publication, $token, [
                    'status' => $sent ? VideoSocialPublication::UNKNOWN : VideoSocialPublication::FAILED,
                    'error_code' => 'worker_error',
                    'error_message' => Str::limit($said, 500),
                ], ['worker', $sent ? 'unknown' : 'failed', Str::limit($said, 300)]);
            } catch (SocialClaimLost) {
                // The posting is another process's now; it records what it finds.
            }
        } finally {
            VideoSocialPublication::query()->whereKey($publicationId)->where('claim_token', $token)->update(['claim_token' => null]);
        }
    }

    /**
     * Looks up where a posting stands on its platform, with the ids the platform gave. Nothing is sent again.
     *
     * @return array{0: VideoSocialPublication, 1: string, 2: bool} the posting, what was found, in words, and whether the platform could not be asked or refused the look-up
     */
    public function follow(string $projectId, string $publicationId): array
    {
        $look = $this->lookUp($projectId, $publicationId);

        return [$look['publication'], $look['message'], $look['error'] !== null];
    }

    /**
     * What a follow-up job does: looks a posting up once more — the platform is asked, nothing is sent — and queues the
     * next look-up while the posting is still processing or scheduled and its window is open. A scheduled posting is not
     * asked about before its time. Past the window, or on an error no later look-up mends, the posting is marked for a
     * person and the chain ends.
     *
     * @param  ?int  $until  the Unix time the window of the posting ends; null: it opens now
     */
    public function followUp(string $publicationId, ?int $until): void
    {
        $publication = VideoSocialPublication::query()->find($publicationId);

        if ($publication === null || $publication->method !== VideoSocialPublication::API || ! in_array($publication->status, self::CHAINED, true)) {
            return;
        }

        if ($this->waits($publication)) {
            $this->chain($publication, $until);

            return;
        }

        $until = self::windowEnd($publication, $until);

        if (now()->getTimestamp() >= $until) {
            $this->mark($publication, 'follow_overdue', 'Quá thời hạn theo dõi tự động: sau '.self::followHours().' giờ nền tảng vẫn báo "'.$publication->status.'". Hệ thống ngừng tự hỏi — cần người vận hành kiểm tra trên nền tảng rồi bấm Đối chiếu hoặc ghi nhận kết quả.', ['follow', 'overdue', 'Hết thời hạn theo dõi tự động.']);

            return;
        }

        $look = $this->lookUp((string) $publication->project_id, $publicationId);
        $error = $look['error'];

        if ($error !== null && ! $error->transient()) {
            $this->mark($look['publication'], 'follow_stopped', 'Theo dõi tự động dừng vì một lỗi mà hỏi lại không tự khắc phục: '.Str::limit($error->getMessage(), 300).' Cần người vận hành xử lý (kết nối lại nếu token hết hạn) rồi bấm Đối chiếu.', ['follow', 'failed', Str::limit($error->getMessage(), 300)]);

            return;
        }

        // Still open — or busy with another process, or an error that a later look-up may get past: the next look-up is queued.
        if (in_array($look['publication']->status, self::CHAINED, true)) {
            $this->chain($look['publication'], $until);
        }
    }

    /**
     * Stops a posting where it can be stopped, and says as it is what was not.
     *
     * @return array{0: VideoSocialPublication, 1: string} the posting and what was done, said as it is
     */
    public function cancel(string $projectId, string $publicationId, ?string $actorId): array
    {
        $publication = VideoSocialPublication::query()->with(['package', 'channel.connection'])->where('project_id', $projectId)->findOrFail($publicationId);
        $cancelled = match ($publication->status) {
            VideoSocialPublication::MANUAL_REPORTED => VideoSocialPublication::query()->whereKey($publicationId)->where('status', VideoSocialPublication::MANUAL_REPORTED)->update(['status' => VideoSocialPublication::CANCELLED, 'error_code' => 'report_withdrawn']),
            VideoSocialPublication::QUEUED => $this->free(VideoSocialPublication::query()->whereKey($publicationId)->where('status', VideoSocialPublication::QUEUED))->update(['status' => VideoSocialPublication::CANCELLED, 'error_code' => 'cancelled_by_person', 'claim_token' => null]),
            default => 0,
        };

        if ($cancelled === 1) {
            VideoSocialEvent::record($projectId, 'publication_cancelled', (string) $publication->package_revision_id, $publicationId, $actorId, ['from' => $publication->status]);

            return [$publication->fresh(), $publication->status === VideoSocialPublication::MANUAL_REPORTED ? 'Đã rút ghi nhận đăng tay. Bài trên nền tảng, nếu có, không bị đụng tới.' : 'Đã hủy lượt đang chờ — chưa có gì được gửi.'];
        }

        if ($publication->status === VideoSocialPublication::SCHEDULED) {
            return $this->unschedule($publication, $actorId, 'person');
        }

        return [$publication->fresh(), self::cannotCancel($publication->fresh())];
    }

    /**
     * Records what a person saw on the platform, for a posting the platform could not answer for, or one whose
     * schedule the platform holds. It is their word and is kept as such.
     *
     * @param  string  $outcome  published|failed|cancelled|rescheduled
     * @return VideoSocialPublication
     *
     * @throws RuntimeException when the posting is not one a person settles, or what they report is not complete
     */
    public function resolve(string $projectId, string $publicationId, string $outcome, ?string $url, ?string $time, ?string $actorId): VideoSocialPublication
    {
        return DB::transaction(function () use ($projectId, $publicationId, $outcome, $url, $time, $actorId): VideoSocialPublication {
            $publication = VideoSocialPublication::query()->with('package')->where('project_id', $projectId)->lockForUpdate()->findOrFail($publicationId);
            $allowed = match ($publication->status) {
                VideoSocialPublication::UNKNOWN => ['published', 'failed'],
                VideoSocialPublication::SCHEDULED => ['published', 'cancelled', 'rescheduled'],
                default => [],
            };

            if (! in_array($outcome, $allowed, true)) {
                throw new RuntimeException('Lượt ở trạng thái '.$publication->status.' không ghi nhận được kết quả "'.$outcome.'" bằng tay.');
            }

            $timezone = (string) $publication->package->source_json['channel']['timezone'];
            $url = trim((string) $url);

            if ($outcome === 'rescheduled') {
                // The new time is read off the platform: it must be given, and a schedule is a time still to come.
                $moved = trim((string) $time) === '' ? throw new RuntimeException('Cần giờ mới mà bạn đã đặt trên nền tảng.') : self::localTime((string) $time, $timezone);

                if (! $moved->isFuture()) {
                    throw new RuntimeException('Giờ mới phải ở tương lai — một lịch đã qua thì ghi nhận "Đã đăng" hoặc "Đã hủy lịch" theo những gì bạn thấy trên nền tảng.');
                }
            }

            $attributes = match ($outcome) {
                'published' => [
                    'status' => VideoSocialPublication::PUBLISHED,
                    'external_url' => preg_match('/^https?:\/\/\S+$/i', $url) === 1 ? $url : throw new RuntimeException('Cần URL của bài đã đăng.'),
                    'published_at' => trim((string) $time) === '' ? now() : self::localTime((string) $time, $timezone),
                ],
                'rescheduled' => ['scheduled_at' => $moved],
                'cancelled' => ['status' => VideoSocialPublication::CANCELLED, 'error_code' => 'cancelled_on_platform'],
                default => ['status' => VideoSocialPublication::FAILED, 'error_code' => 'reported_not_posted'],
            };
            $before = ['status' => $publication->status, 'scheduled_at' => $publication->scheduled_at?->toIso8601String()];
            // Only an outcome a person settles makes the posting theirs to answer for; a moved time leaves who confirms the posting open. The step and the event keep who reported it either way.
            $confirmed = $outcome === 'rescheduled' ? [] : ['confirmed_by' => $actorId];
            // What a person saw ends any claim on the posting: a worker still asking the platform writes nothing over it.
            $publication->forceFill($attributes + $confirmed + ['claim_token' => null, 'claimed_at' => null, 'steps_json' => [...(array) $publication->steps_json, self::step('person', $outcome, 'Ghi nhận theo xác nhận của người vận hành, không phải do API đối chiếu.')]])->save();
            VideoSocialEvent::record($projectId, $outcome === 'rescheduled' ? 'schedule_change_reported' : 'publication_resolved_by_person', (string) $publication->package_revision_id, $publicationId, $actorId, ['outcome' => $outcome, 'before' => $before, 'url' => $attributes['external_url'] ?? null, 'scheduled_at' => $publication->scheduled_at?->toIso8601String()]);

            return $publication;
        });
    }

    /**
     * Moves the time of a posting that has not been sent. The package is not touched: the change is an event of its own.
     *
     * @throws RuntimeException when the posting was already taken by a worker or handed to the platform, or the time is not one the platform takes
     */
    public function reschedule(string $projectId, string $publicationId, string $scheduledAt, ?string $actorId): VideoSocialPublication
    {
        return DB::transaction(function () use ($projectId, $publicationId, $scheduledAt, $actorId): VideoSocialPublication {
            $publication = VideoSocialPublication::query()->with('package')->where('project_id', $projectId)->lockForUpdate()->findOrFail($publicationId);

            if ($publication->status !== VideoSocialPublication::QUEUED || $publication->claim_token !== null || $publication->scheduled_at === null) {
                throw new RuntimeException($publication->status === VideoSocialPublication::SCHEDULED
                    ? 'Nền tảng đang giữ lịch của lượt này — đổi giờ trên nền tảng rồi ghi nhận giờ mới ở đây.'
                    : 'Chỉ đổi được giờ của lượt đang chờ và có lịch.');
            }

            $source = (array) $publication->package->source_json;
            $document = (array) $publication->package->document_json;
            $at = self::localTime($scheduledAt, (string) $source['channel']['timezone']);

            if (($problem = SocialPolicy::scheduleProblem((array) $source['policy']['rules'], $document['metadata']['privacy'] ?? null, $at, now())) !== null) {
                throw new RuntimeException($problem);
            }

            $payload = (array) $publication->payload_json;
            $payload['fields'] = SocialConnectionService::publisher((string) $source['policy']['rules']['platform'])->payload($document, (array) $source['policy']['rules'], $at);
            $from = $publication->scheduled_at->toIso8601String();
            $publication->forceFill(['scheduled_at' => $at, 'payload_json' => $payload, 'payload_hash' => CanonicalJson::hash(['adapter' => $payload['adapter'], 'fields' => $payload['fields']])])->save();
            VideoSocialEvent::record($projectId, 'publication_rescheduled', (string) $publication->package_revision_id, $publicationId, $actorId, ['from' => $from, 'to' => $at->toIso8601String(), 'payload_hash' => $publication->payload_hash]);

            return $publication;
        });
    }

    /**
     * What becomes of the postings of a revision whose approval was taken back: what waits is stopped, a schedule the
     * platform holds is asked to be taken back where the adapter can, and nothing else is said to be stopped.
     *
     * @return list<string> one line a posting, each saying what was done and what was not
     */
    public function afterRevoke(string $projectId, string $packageId, ?string $actorId): array
    {
        $lines = [];

        foreach (VideoSocialPublication::query()->with(['package', 'channel.connection'])->where('project_id', $projectId)->where('package_revision_id', $packageId)->orderBy('created_at')->get() as $publication) {
            if ($publication->status === VideoSocialPublication::QUEUED) {
                $stopped = $this->free(VideoSocialPublication::query()->whereKey($publication->id)->where('status', VideoSocialPublication::QUEUED))->update(['status' => VideoSocialPublication::CANCELLED, 'error_code' => 'approval_withdrawn', 'error_message' => 'Duyệt gói đã bị thu hồi trước khi gửi.', 'claim_token' => null]);

                if ($stopped === 1) {
                    VideoSocialEvent::record($projectId, 'publication_cancelled', $packageId, (string) $publication->id, $actorId, ['from' => VideoSocialPublication::QUEUED, 'why' => 'approval_withdrawn']);
                    $lines[] = 'Lượt chờ gửi '.substr((string) $publication->id, 0, 8).': đã hủy, chưa có gì được gửi.';

                    continue;
                }

                $publication->refresh();
            }

            if ($publication->status === VideoSocialPublication::SCHEDULED) {
                [, $message] = $this->unschedule($publication, $actorId, 'approval_withdrawn');
                $lines[] = 'Lượt '.substr((string) $publication->id, 0, 8).': '.$message;

                continue;
            }

            if ($publication->status !== VideoSocialPublication::CANCELLED && $publication->status !== VideoSocialPublication::FAILED) {
                $lines[] = 'Lượt '.substr((string) $publication->id, 0, 8).': '.self::cannotCancel($publication->fresh());
            }
        }

        return $lines;
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator the postings of a project, newest first, a page at a time, with the revision and the channel of each read in one query apiece
     */
    public function history(string $projectId, int $page)
    {
        return VideoSocialPublication::query()
            ->with(['package:id,package_group_id,revision,format,language,package_hash,status', 'channel:id,display_name,platform,timezone'])
            ->where('project_id', $projectId)
            ->orderByDesc('created_at')
            ->paginate((int) config('video.social.history_per_page'), ['*'], 'page', max(1, $page));
    }

    /**
     * Sends a posting a worker has taken: looks at everything again, then one step after another, each stored before
     * the next. Every store proves the claim is still held; a claim lost stops the posting with SocialClaimLost before
     * the next request leaves.
     *
     * @throws SocialClaimLost
     */
    private function send(VideoSocialPublication $publication, string $token): void
    {
        $package = $publication->package;
        $source = (array) $package->source_json;
        $payload = (array) $publication->payload_json;
        $rules = (array) $source['policy']['rules'];
        $publisher = SocialConnectionService::publisher((string) $rules['platform']);

        // Before anything leaves: what was true when the request was taken in is looked at again.
        [$refusal, $read] = $this->recheck($publication, $publisher);

        if ($refusal !== null) {
            $this->advance($publication, $token, ['status' => VideoSocialPublication::CANCELLED, 'error_code' => $refusal[0], 'error_message' => $refusal[1]], ['check', 'failed', $refusal[0]]);

            return;
        }

        $bytes = (int) filesize($read['path']);

        try {
            $account = $this->connections->account($publication->channel);

            // The account a request is signed with is the one of the page or channel approved, and no other.
            if (! hash_equals((string) ($source['channel']['external_id'] ?? ''), (string) $account['external_id'])) {
                $this->advance($publication, $token, ['status' => VideoSocialPublication::CANCELLED, 'error_code' => 'target_changed', 'error_message' => 'Kết nối ký yêu cầu cho ID '.json_encode($account['external_id']).', bản đã duyệt viết cho ID '.json_encode($source['channel']['external_id'] ?? null).' — không gửi.'], ['check', 'failed', 'target_changed']);

                return;
            }

            $this->advance($publication, $token, ['status' => VideoSocialPublication::UPLOADING], ['check', 'ok', null]);
            $session = $publisher->open($account, (array) $payload['fields'], $bytes);
            // What the platform gave to follow the posting by is stored before a single byte is sent — and only while this worker still holds the posting.
            $this->advance($publication, $token, ['session' => $session, 'external_id' => $session['external_id'] ?? null], ['open', 'ok', null]);
            $session = $publisher->send($account, $session, $read['path'], $bytes);
            $this->advance($publication, $token, ['session' => $session, 'external_id' => $session['external_id'] ?? null], ['upload', 'ok', null]);
            $session = $publisher->finish($account, $session, (array) $payload['fields']);
            $this->advance($publication, $token, ['session' => $session, 'status' => VideoSocialPublication::PROCESSING], ['finish', 'ok', null]);
        } catch (SocialPlatformError $e) {
            $this->refused($publication, $e);
            // No answer after the bytes or the publish call were on their way: the posting may exist. It is unknown, not failed.
            $this->advance($publication, $token, ['status' => $e->uncertain ? VideoSocialPublication::UNKNOWN : VideoSocialPublication::FAILED, 'error_code' => $e->kind, 'error_message' => Str::limit($e->getMessage(), 500)], ['send', $e->uncertain ? 'unknown' : 'failed', Str::limit($e->getMessage(), 300)]);

            return;
        }

        try {
            $this->settle($publication, $token, $account, false);
        } catch (SocialPlatformError $e) {
            $this->refused($publication, $e);
            // The video is on the platform; only where it stands could not be read. It stays processing and is looked up later.
            $this->advance($publication, $token, [], ['status', 'failed', Str::limit($e->getMessage(), 300)]);
        }

        $publication->refresh();

        if (in_array($publication->status, self::CHAINED, true)) {
            $this->chain($publication, null);
        }
    }

    /**
     * Everything a worker looks at again before the first request of a posting leaves, in the order a person would fix it.
     *
     * @return array{0: ?array{0: string, 1: string}, 1: ?array{snapshot: array<string, mixed>, path: string}} why nothing may be sent — a code and what to tell a person — or null with the film read now
     */
    private function recheck(VideoSocialPublication $publication, SocialPublisher $publisher): array
    {
        $package = $publication->package;
        $source = (array) $package->source_json;
        $rules = (array) $source['policy']['rules'];
        $document = (array) $package->document_json;
        $payload = (array) $publication->payload_json;
        $projectId = (string) $publication->project_id;

        if (! SocialApprovalService::approved($package) || ! hash_equals((string) $package->package_hash, (string) $publication->package_hash)) {
            return [['approval_withdrawn', 'Duyệt gói đã bị thu hồi hoặc bản đã đổi khi lượt đăng chờ — không gửi.'], null];
        }

        if (! SocialConnectionService::publishing((string) $rules['platform'])) {
            return [['publishing_disabled', 'Đăng qua API đã bị tắt khi lượt đăng chờ — không gửi.'], null];
        }

        try {
            $contract = $this->sources->policy($projectId)['contract'];
        } catch (RuntimeException $e) {
            return [['policy_unavailable', 'Không đọc được chính sách nền tảng hiện hành ('.$e->getMessage().') — không gửi.'], null];
        }

        if (! hash_equals((string) $source['policy']['policy_hash'], (string) $contract['policy_hash'])) {
            return [['policy_changed', 'Chính sách nền tảng đã có revision mới khi lượt đăng chờ — không gửi; gói giữ nguyên trong lịch sử. Tạo revision theo chính sách hiện hành, duyệt rồi đăng bản đó.'], null];
        }

        $pinned = (string) $publication->adapter_version;

        if ($publisher->adapter() !== $pinned || (string) $rules['api']['adapter'] !== $pinned || (string) ($payload['adapter'] ?? '') !== $pinned) {
            return [['adapter_changed', 'Lượt đăng ghim adapter '.json_encode($pinned).', chính sách của gói viết cho '.json_encode($rules['api']['adapter']).', code đang chạy '.json_encode($publisher->adapter()).' — không gửi.'], null];
        }

        // The payload as it was stored, and as the adapter builds it now from the approved revision, are both the one the request pinned.
        $stored = CanonicalJson::hash(['adapter' => $payload['adapter'], 'fields' => $payload['fields']]);
        $rebuilt = CanonicalJson::hash(['adapter' => $publisher->adapter(), 'fields' => $publisher->payload($document, $rules, $publication->scheduled_at)]);

        if (! hash_equals((string) $publication->payload_hash, $stored) || ! hash_equals((string) $publication->payload_hash, $rebuilt)) {
            return [['payload_changed', 'Nội dung sẽ gửi không còn khớp payload_hash đã ghim khi nhận yêu cầu — không gửi.'], null];
        }

        // A time that stopped being one the platform takes while the posting waited is not turned into "post now".
        if ($publication->scheduled_at !== null && ($late = SocialPolicy::scheduleProblem($rules, $document['metadata']['privacy'] ?? null, $publication->scheduled_at, now())) !== null) {
            return [['schedule_invalid', 'Giờ hẹn '.$publication->scheduled_at->toIso8601String().' không còn hợp lệ lúc tới lượt gửi: '.$late.' Chưa gửi gì, và lượt hẹn giờ không bị đổi thành đăng ngay — tạo lượt đăng mới với giờ hợp lệ.'], null];
        }

        $channel = $publication->channel;
        $target = $channel === null ? 'Hồ sơ kênh của lượt đăng không còn' : SocialChannelService::targetProblem((array) $source['channel'], $channel);

        if ($target !== null) {
            return [['target_changed', $target.' — không gửi. '.SocialChannelService::RETARGET], null];
        }

        if ($channel->status !== VideoSocialChannel::ACTIVE) {
            return [['channel_archived', self::ARCHIVED], null];
        }

        $problems = $this->connections->problems($channel);

        if ($problems !== []) {
            return [['connection_unusable', implode(' ', $problems).' Không gửi.'], null];
        }

        try {
            $read = $this->sources->read($projectId);
        } catch (RuntimeException) {
            $read = null;
        }

        if ($read === null || ! hash_equals((string) $package->source_hash, (string) $read['snapshot']['hash']) || ! hash_equals((string) $payload['film']['sha256'], (string) $read['snapshot']['film']['sha256'])) {
            return [['source_changed', 'Phim đã duyệt của dự án đã đổi hoặc không còn khi lượt đăng chờ — không gửi; gói cũ giữ nguyên trong lịch sử.'], null];
        }

        $cover = $payload['cover'] === null ? null : VideoArtifact::query()->whereKey($payload['cover']['artifact_id'])->first();

        if ($payload['cover'] !== null && ($cover === null || SocialArtworkService::problem($cover) !== null || ! hash_equals((string) $payload['cover']['sha256'], (string) $cover->sha256))) {
            return [['cover_changed', 'File cover của gói không còn hoặc đã đổi — không gửi.'], null];
        }

        return [null, $read];
    }

    /**
     * Reads where a posting stands and records it; sets the cover once the platform has the video ready.
     *
     * @param  array{access_token: string, external_id: string}  $account
     * @param  bool  $lookup  whether a person or a later job is looking a posting up, which asks the platform once; a worker that just sent it asks a few times while the platform processes
     * @return string what was found, in words
     *
     * @throws SocialPlatformError
     * @throws SocialClaimLost
     */
    private function settle(VideoSocialPublication $publication, string $token, array $account, bool $lookup): string
    {
        $publication->refresh();
        $payload = (array) $publication->payload_json;
        $rules = (array) $publication->package->source_json['policy']['rules'];
        $publisher = SocialConnectionService::publisher((string) $rules['platform']);
        $session = (array) $publication->session;
        $tries = $lookup ? 1 : max(1, (int) config('video.social.poll.tries', 6));
        $state = ['state' => 'unknown', 'external_id' => null, 'url' => null, 'at' => null, 'detail' => null];

        for ($try = 1; $try <= $tries; $try++) {
            $this->keep($publication, $token);
            $state = $publisher->state($account, $session, (array) $payload['fields'], (int) $payload['film']['bytes']);

            if ($state['state'] !== 'processing' || $try === $tries) {
                break;
            }

            sleep(max(0, (int) config('video.social.poll.seconds', 10)));
        }

        $known = ['external_id' => $state['external_id'] ?? $publication->external_id, 'external_url' => $state['url'] ?? $publication->external_url, 'session' => ['external_id' => $state['external_id'] ?? ($session['external_id'] ?? null)] + $session];

        switch ($state['state']) {
            case 'published':
                // The platform answers for this posting now: it is no person's confirmation.
                $this->advance($publication, $token, $known + ['status' => VideoSocialPublication::PUBLISHED, 'published_at' => $state['at'] === null ? ($publication->published_at ?? now()) : Carbon::parse($state['at']), 'error_code' => null, 'error_message' => null, 'confirmed_by' => null], ['publish', 'ok', null]);
                $this->extras($publication, $token, $account, $rules);

                return 'Nền tảng xác nhận đã đăng.';
            case 'scheduled':
                $this->advance($publication, $token, $known + ['status' => VideoSocialPublication::SCHEDULED, 'scheduled_at' => $state['at'] === null ? $publication->scheduled_at : Carbon::parse($state['at']), 'error_code' => null, 'error_message' => null], ['schedule', 'ok', 'Nền tảng giữ lịch đăng.']);
                $this->extras($publication, $token, $account, $rules);

                return 'Nền tảng đang giữ lịch đăng.';
            case 'processing':
                $this->advance($publication, $token, $known + ['status' => VideoSocialPublication::PROCESSING], ['status', 'ok', $state['detail']]);

                return 'Nền tảng vẫn đang xử lý video.';
            case 'failed':
                $this->advance($publication, $token, $known + ['status' => VideoSocialPublication::FAILED, 'error_code' => SocialPlatformError::PROCESSING, 'error_message' => $state['detail']], ['status', 'failed', $state['detail']]);

                return 'Nền tảng báo lỗi: '.$state['detail'];
            case 'upload_incomplete':
            case 'uploaded':
                if ((array_column((array) $publication->steps_json, 'status', 'step')['finish'] ?? null) === 'ok') {
                    // Every call of the posting was answered: the platform has only not caught up with it yet.
                    $this->advance($publication, $token, $known + ['status' => VideoSocialPublication::PROCESSING], ['status', 'ok', $state['detail']]);

                    return 'Nền tảng chưa cập nhật xong lượt vừa gửi — vẫn đang xử lý.';
                }

                // A call of the posting got no answer, and the platform itself now says the posting never came to be: that is an answer, and the posting ends. A new one is a new request of a person.
                $this->advance($publication, $token, $known + ['status' => VideoSocialPublication::FAILED, 'error_code' => $state['state'] === 'uploaded' ? 'not_published' : 'upload_incomplete', 'error_message' => $state['detail']], ['reconcile', 'failed', $state['detail']]);

                return 'Nền tảng xác nhận lượt này chưa thành bài đăng: '.$state['detail'].' Lượt kết thúc; muốn đăng thì tạo lượt đăng mới.';
            default:
                $this->advance($publication, $token, $known + ['status' => VideoSocialPublication::UNKNOWN, 'error_code' => $publication->error_code ?? 'undetermined', 'error_message' => $state['detail']], ['reconcile', 'unknown', $state['detail']]);

                return 'Nền tảng không cho biết kết quả: '.$state['detail'].' Cần người vận hành kiểm tra trên nền tảng rồi ghi nhận.';
        }
    }

    /**
     * The steps beside the posting itself, each with a result of its own: the cover and the synthetic-content
     * declaration. A posting that is out stays out whatever becomes of them.
     *
     * @param  array{access_token: string, external_id: string}  $account
     * @param  array<string, mixed>  $rules
     *
     * @throws SocialClaimLost
     */
    private function extras(VideoSocialPublication $publication, string $token, array $account, array $rules): void
    {
        $publication->refresh();
        $done = array_column((array) $publication->steps_json, 'step');
        $payload = (array) $publication->payload_json;
        $document = (array) $publication->package->document_json;

        if (! in_array('synthetic_media', $done, true)) {
            $this->advance($publication, $token, [], match (true) {
                ! $document['disclosure']['synthetic_media'] => ['synthetic_media', 'none', 'Gói không khai báo nội dung tổng hợp.'],
                (bool) $rules['synthetic_media']['api'] => ['synthetic_media', 'ok', 'Đã gửi kèm khi tải lên.'],
                default => ['synthetic_media', 'manual', (string) $rules['synthetic_media']['note']],
            });
        }

        if (in_array('cover', $done, true)) {
            return;
        }

        if ($payload['cover'] === null) {
            $this->advance($publication, $token, [], ['cover', 'none', 'Gói không có cover.']);

            return;
        }

        if (! $rules['cover']['api']) {
            $this->advance($publication, $token, [], ['cover', 'manual', (string) $rules['cover']['note']]);

            return;
        }

        // Setting a cover writes to the platform: with posting through its API switched off, a look-up only reads.
        if (! SocialConnectionService::publishing((string) $rules['platform'])) {
            $this->advance($publication, $token, [], ['cover', 'manual', 'Đăng qua API đang tắt nên không đặt cover qua API — đặt cover bằng tay trên nền tảng (file cover có trong gói tải về).']);

            return;
        }

        $cover = VideoArtifact::query()->whereKey($payload['cover']['artifact_id'])->first();
        $path = $cover === null ? null : SocialArtworkService::path($cover);

        if ($path === null) {
            $this->advance($publication, $token, [], ['cover', 'failed', 'File cover không còn — chưa đặt được.']);

            return;
        }

        try {
            $this->keep($publication, $token);
            SocialConnectionService::publisher((string) $rules['platform'])->cover($account, (string) $publication->external_id, $path);
            $this->advance($publication, $token, [], ['cover', 'ok', null]);
        } catch (SocialPlatformError $e) {
            $this->advance($publication, $token, [], ['cover', 'failed', Str::limit($e->getMessage(), 300)]);
        }
    }

    /**
     * A look-up of a posting under a claim of its own: the platform is asked once with what the posting stored, and what
     * it answers is recorded. Nothing is sent again.
     *
     * @return array{publication: VideoSocialPublication, message: string, error: ?SocialPlatformError}
     */
    private function lookUp(string $projectId, string $publicationId): array
    {
        $token = (string) Str::uuid();
        $taken = $this->free(VideoSocialPublication::query()->whereKey($publicationId)->where('project_id', $projectId)->where('method', VideoSocialPublication::API)->whereIn('status', self::FOLLOWED))
            ->update(['claim_token' => $token, 'claimed_at' => now()]);
        $publication = VideoSocialPublication::query()->with(['package', 'channel.connection'])->where('project_id', $projectId)->findOrFail($publicationId);

        if ($taken !== 1) {
            return ['publication' => $publication, 'message' => in_array($publication->status, self::FOLLOWED, true) ? 'Lượt này đang được một tiến trình khác xử lý — thử lại sau.' : 'Lượt này đã ở trạng thái cuối, không cần đối chiếu.', 'error' => null];
        }

        $error = null;

        try {
            $channel = $publication->channel;
            $target = $channel === null ? 'hồ sơ kênh của lượt đăng không còn' : SocialChannelService::targetProblem((array) $publication->package->source_json['channel'], $channel);

            if ($target !== null) {
                // The posting is on the page approved; the profile now names another, whose connection is not the one to ask with.
                throw new SocialPlatformError(SocialPlatformError::CONFIG, $target.' — không đối chiếu bằng kết nối của đích mới.');
            }

            $account = $this->connections->account($channel);
            $message = $this->settle($publication, $token, $account, true);
        } catch (SocialPlatformError $e) {
            $error = $e;
            $this->refused($publication, $e);
            $message = 'Chưa đối chiếu được: '.$e->getMessage().' Trạng thái giữ nguyên.';

            try {
                $this->advance($publication, $token, [], ['reconcile', 'failed', Str::limit($e->getMessage(), 300)]);
            } catch (SocialClaimLost) {
                // Another process holds it now and records what it finds.
            }
        } catch (SocialClaimLost) {
            $message = 'Lượt này vừa được một tiến trình hoặc người vận hành khác tiếp quản — dừng đối chiếu, không ghi gì thêm.';
        } finally {
            VideoSocialPublication::query()->whereKey($publicationId)->where('claim_token', $token)->update(['claim_token' => null]);
        }

        return ['publication' => $publication->fresh(), 'message' => $message, 'error' => $error];
    }

    /**
     * Asks the platform to take back the schedule of a posting it holds, where the policy and the adapter know a request
     * for it, and records only what the platform reads back. Said as it is: never cancelled before it is confirmed.
     *
     * @param  string  $why  person|approval_withdrawn
     * @return array{0: VideoSocialPublication, 1: string}
     */
    private function unschedule(VideoSocialPublication $publication, ?string $actorId, string $why): array
    {
        $projectId = (string) $publication->project_id;
        $source = (array) $publication->package->source_json;
        $rules = (array) $source['policy']['rules'];
        $platform = (string) $rules['platform'];
        $needsPlatform = function (string $reason) use ($publication, $projectId, $actorId, $why): void {
            VideoSocialEvent::record($projectId, 'cancel_needs_platform', (string) $publication->package_revision_id, (string) $publication->id, $actorId, ['scheduled_at' => $publication->scheduled_at?->toIso8601String(), 'why' => $why, 'reason' => $reason]);
        };

        if (! ($rules['schedule']['cancel']['api'] ?? false)) {
            $needsPlatform('adapter_unsupported');

            return [$publication->fresh(), self::cannotCancel($publication->fresh(), $rules['schedule']['cancel']['note'] ?? null)];
        }

        if (! SocialConnectionService::publishing($platform)) {
            $needsPlatform('publishing_disabled');

            return [$publication->fresh(), 'đăng '.$platform.' qua API đang tắt nên không gửi yêu cầu hủy lịch — CHƯA HỦY. Hủy trên nền tảng rồi ghi nhận "Đã hủy lịch trên nền tảng" ở đây.'];
        }

        $token = (string) Str::uuid();
        $taken = $this->free(VideoSocialPublication::query()->whereKey($publication->id)->where('status', VideoSocialPublication::SCHEDULED))->update(['claim_token' => $token, 'claimed_at' => now()]);

        if ($taken !== 1) {
            return [$publication->fresh(), 'lượt này đang được một tiến trình khác xử lý — CHƯA HỦY; thử lại sau.'];
        }

        $publisher = SocialConnectionService::publisher($platform);

        try {
            $channel = $publication->channel;
            $target = $channel === null ? 'hồ sơ kênh của lượt đăng không còn' : SocialChannelService::targetProblem($source['channel'], $channel);

            if ($target !== null) {
                throw new SocialPlatformError(SocialPlatformError::CONFIG, $target.' — không gửi yêu cầu hủy bằng kết nối của đích mới.');
            }

            $missing = $publisher->missingScopes((array) $channel->connection?->scopes, 'cancel');

            if ($missing !== []) {
                throw new SocialPlatformError(SocialPlatformError::PERMISSION, 'kết nối thiếu quyền '.implode(', ', $missing).' để hủy lịch qua API — kết nối lại và cấp quyền này, hoặc hủy trên nền tảng.');
            }

            $account = $this->connections->account($channel);
            // The claim is proved and renewed before each request the adapter makes: one lost while a request ran stops the next.
            $answer = $publisher->unschedule($account, (string) $publication->external_id, fn () => $this->keep($publication, $token));

            if ($answer['state'] === 'cancelled') {
                $this->advance($publication, $token, ['status' => VideoSocialPublication::CANCELLED, 'error_code' => 'cancelled_via_api', 'error_message' => $answer['detail']], ['cancel', 'ok', $answer['detail']]);
                VideoSocialEvent::record($projectId, 'schedule_cancelled_via_api', (string) $publication->package_revision_id, (string) $publication->id, $actorId, ['why' => $why, 'scheduled_at' => $publication->scheduled_at?->toIso8601String(), 'detail' => $answer['detail']]);

                return [$publication->fresh(), 'Đã hủy lịch qua API — '.$answer['detail'].' Video vẫn nằm trên kênh ở chế độ riêng tư; hệ thống không xóa nó.'];
            }

            if ($answer['state'] === 'unsupported') {
                $needsPlatform('adapter_unsupported');

                return [$publication->fresh(), self::cannotCancel($publication->fresh(), $answer['detail'])];
            }

            $this->advance($publication, $token, [], ['cancel', $answer['state'] === 'unknown' ? 'unknown' : 'failed', $answer['detail']]);
            $needsPlatform('not_confirmed:'.$answer['state']);

            return [$publication->fresh(), match ($answer['state']) {
                'published' => 'không hủy được: '.$answer['detail'].' Bấm Đối chiếu để cập nhật trạng thái.',
                'scheduled' => 'đã gửi yêu cầu hủy nhưng nền tảng đọc lại vẫn giữ lịch — CHƯA HỦY. '.$answer['detail'],
                default => 'nền tảng không cho biết lịch còn hay không — CHƯA HỦY. '.$answer['detail'],
            }];
        } catch (SocialPlatformError $e) {
            $this->refused($publication, $e);
            $needsPlatform('refused:'.$e->kind);

            try {
                $this->advance($publication, $token, [], ['cancel', $e->uncertain ? 'unknown' : 'failed', Str::limit($e->getMessage(), 300)]);
            } catch (SocialClaimLost) {
                // Another process holds it now.
            }

            return [$publication->fresh(), $e->uncertain
                ? 'yêu cầu hủy lịch không nhận được trả lời — chưa rõ nền tảng đã hủy hay chưa; CHƯA HỦY cho tới khi nền tảng xác nhận. Bấm Hủy lịch lại để hỏi lại nền tảng.'
                : 'không hủy được lịch qua API ('.$e->getMessage().') — CHƯA HỦY. Hủy trên nền tảng rồi ghi nhận "Đã hủy lịch trên nền tảng" ở đây.'];
        } catch (SocialClaimLost) {
            return [$publication->fresh(), 'lượt này vừa được một tiến trình hoặc một người vận hành khác cập nhật — dừng, không gửi thêm yêu cầu hủy nào. Xem trạng thái hiện tại của lượt; nếu vẫn đang giữ lịch thì bấm Hủy lịch lại.'];
        } finally {
            VideoSocialPublication::query()->whereKey($publication->id)->where('claim_token', $token)->update(['claim_token' => null]);
        }
    }

    /**
     * Queues the next look-up of a posting still open: a posting the platform holds a time for, at that time; one it
     * still processes, after a pause. With the queue off nothing is queued and a person looks it up.
     *
     * @param  ?int  $until  the end of the window of the posting; null: it opens now
     */
    private function chain(VideoSocialPublication $publication, ?int $until): void
    {
        $until = self::windowEnd($publication, $until);
        $at = $this->waits($publication)
            ? $publication->scheduled_at->getTimestamp() + max(0, (int) config('video.social.poll.after_schedule_seconds', 300))
            : now()->getTimestamp() + max(0, (int) config('video.social.poll.requeue_seconds', 120));
        $delay = min(max(0, $at - now()->getTimestamp()), max(60, (int) config('video.social.poll.max_delay_seconds', 21600)));

        $this->dispatch((string) $publication->id, 'follow', $publication->requested_by, $delay, $until);
    }

    /** @return bool whether a posting is scheduled for a time still to come: nothing is asked of the platform before it */
    private function waits(VideoSocialPublication $publication): bool
    {
        return $publication->status === VideoSocialPublication::SCHEDULED && $publication->scheduled_at !== null && $publication->scheduled_at->isFuture();
    }

    /**
     * @return int the Unix time the follow-up of a posting ends: for a scheduled one, its time and the window after it; for one being processed, the end it was given, or the window from now
     */
    private static function windowEnd(VideoSocialPublication $publication, ?int $until): int
    {
        $window = self::followHours() * 3600;

        if ($publication->status === VideoSocialPublication::SCHEDULED && $publication->scheduled_at !== null) {
            return $publication->scheduled_at->getTimestamp() + $window;
        }

        return $until ?? now()->getTimestamp() + $window;
    }

    private static function followHours(): int
    {
        return max(1, (int) config('video.social.poll.follow_hours', 6));
    }

    /**
     * Writes on a posting no live worker holds what a follow-up found — that a person must look — under a claim of its
     * own. A posting held by another process is looked at again by the next job instead.
     *
     * @param  array{0: string, 1: string, 2: ?string}  $step
     */
    private function mark(VideoSocialPublication $publication, string $code, string $message, array $step): void
    {
        $token = (string) Str::uuid();
        $taken = $this->free(VideoSocialPublication::query()->whereKey($publication->id)->where('status', $publication->status))->update(['claim_token' => $token, 'claimed_at' => now()]);

        if ($taken !== 1) {
            $this->chain($publication, null);

            return;
        }

        try {
            $this->advance($publication, $token, ['error_code' => $code, 'error_message' => $message], $step);
            VideoSocialEvent::record((string) $publication->project_id, $code, (string) $publication->package_revision_id, (string) $publication->id, null, ['status' => $publication->status, 'message' => $message]);
        } catch (SocialClaimLost) {
            // Another process holds it now.
        } finally {
            VideoSocialPublication::query()->whereKey($publication->id)->where('claim_token', $token)->update(['claim_token' => null]);
        }
    }

    /**
     * Stores what a step came to, while this worker still holds the posting and the posting is still in the status this
     * worker last left it in, and renews the claim. The copy the worker holds is brought up to the row it wrote.
     *
     * @param  array<string, mixed>  $attributes
     * @param  ?array{0: string, 1: string, 2: ?string}  $step
     *
     * @throws SocialClaimLost when another process or a person took the posting over, released it, or moved its status: this one stops, and nothing is written
     */
    private function advance(VideoSocialPublication $publication, string $token, array $attributes, ?array $step = null): void
    {
        DB::transaction(function () use ($publication, $token, $attributes, $step): void {
            $held = VideoSocialPublication::query()->whereKey($publication->id)->where('claim_token', $token)->lockForUpdate()->first();

            if ($held === null) {
                throw new SocialClaimLost('Lượt đăng '.$publication->id.' không còn do tiến trình này giữ.');
            }

            // The status is the version the answer of a platform is applied to: a status that moved under the worker is not overwritten with an answer read before.
            if ((string) $held->status !== (string) $publication->status) {
                throw new SocialClaimLost('Trạng thái lượt đăng '.$publication->id.' đã đổi từ '.$publication->status.' sang '.$held->status.' bởi một thao tác khác.');
            }

            $before = (string) $held->status;
            $held->forceFill($attributes + ['claimed_at' => now()] + ($step === null ? [] : ['steps_json' => [...(array) $held->steps_json, self::step(...$step)]]))->save();
            $publication->setRawAttributes($held->getAttributes(), true);

            if (isset($attributes['status']) && $attributes['status'] !== $before) {
                VideoSocialEvent::record((string) $held->project_id, 'publication_'.$attributes['status'], (string) $held->package_revision_id, (string) $held->id, null, ['from' => $before, 'error_code' => $held->error_code, 'external_id' => $held->external_id]);
            }
        });
    }

    /**
     * Proves, right before a request to a platform, that this worker still holds the posting, and renews the claim so
     * no other process takes it over while the request runs.
     *
     * @throws SocialClaimLost
     */
    private function keep(VideoSocialPublication $publication, string $token): void
    {
        $this->advance($publication, $token, []);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<VideoSocialPublication>  $query
     * @return \Illuminate\Database\Eloquent\Builder<VideoSocialPublication> the postings of the query no live worker holds: no claim, or a claim older than a worker may hold one
     */
    private function free($query)
    {
        $stale = now()->subSeconds((int) config('video.social.claim_seconds'));

        return $query->where(static fn ($free) => $free->whereNull('claim_token')->orWhere('claimed_at', '<', $stale));
    }

    /** A token the platform refused marks the connection, so the screen says to connect again. */
    private function refused(VideoSocialPublication $publication, SocialPlatformError $e): void
    {
        if ($e->kind === SocialPlatformError::TOKEN && $publication->channel !== null) {
            $this->connections->expire($publication->channel, $e->getMessage());
        }
    }

    /** @param ?int $until follow only: the end of the window of the posting */
    private function dispatch(string $publicationId, string $mode, ?string $requesterId, int $delay, ?int $until = null): void
    {
        if (! VideoQueues::enabled()) {
            // Without the queue the request itself sends; a posting still processing or scheduled afterwards is looked up by a person.
            if ($mode === 'work') {
                $this->work($publicationId);
            }

            return;
        }

        // Called once the row of the posting is committed: the job is queued now, not held for a transaction around the caller.
        PublishSocialPackageJob::dispatch($publicationId, $mode, $mode === 'follow' ? $until : null)
            ->onConnection(VideoQueues::CONNECTION)
            ->onQueue(VideoQueues::name(VideoQueues::API, $requesterId))
            ->delay($delay)
            ->beforeCommit();
    }

    /**
     * @param  \Closure(): VideoSocialPublication  $create
     * @return array{0: VideoSocialPublication, 1: string} the posting of the request: made now, or the one the same request made before
     *
     * @throws RuntimeException when the key was used for another revision
     */
    private function once(string $requestKey, string $packageId, \Closure $create, string $made): array
    {
        if (! Str::isUuid($requestKey)) {
            throw new RuntimeException('Mã yêu cầu không hợp lệ — tải lại trang.');
        }

        $replay = function () use ($requestKey, $packageId): ?array {
            $existing = VideoSocialPublication::query()->where('request_key', $requestKey)->first();

            if ($existing !== null && (string) $existing->package_revision_id !== $packageId) {
                throw new RuntimeException('Mã yêu cầu này đã dùng cho một gói khác — tải lại trang.');
            }

            return $existing === null ? null : [$existing, 'replayed'];
        };

        try {
            return DB::transaction(static fn (): array => $replay() ?? [$create(), $made]);
        } catch (QueryException $e) {
            // Two requests with one key at the same instant: the unique key lets one in, the other is handed that one.
            return $replay() ?? throw $e;
        }
    }

    /**
     * @return VideoSocialPackage the revision a person chose to post, approved and the one the page showed
     *
     * @throws RuntimeException
     */
    private function approvedRevision(string $projectId, string $packageId, string $expectedHash): VideoSocialPackage
    {
        $package = $this->packages->owned($projectId, $packageId);

        if (! hash_equals((string) $package->package_hash, $expectedHash)) {
            throw new RuntimeException('Gói trên trang không phải bản này — tải lại trang.');
        }

        if (! SocialApprovalService::approved($package)) {
            throw new RuntimeException('Chỉ đăng hoặc ghi nhận đăng cho bản đã duyệt — bản này đang '.$package->status.'.');
        }

        return $package;
    }

    /** @return bool whether the revision already has a posting that stands */
    private function posted(VideoSocialPackage $package): bool
    {
        return VideoSocialPublication::query()->where('package_revision_id', $package->id)->whereNotIn('status', [VideoSocialPublication::CANCELLED, VideoSocialPublication::FAILED])->exists();
    }

    /**
     * @param  ?string  $note  why the adapter does not take a schedule back, as the policy or the adapter says it
     * @return string why a posting was not cancelled, as it stands — never that it is cancelled
     */
    private static function cannotCancel(VideoSocialPublication $publication, ?string $note = null): string
    {
        return match ($publication->status) {
            VideoSocialPublication::SCHEDULED => 'nền tảng đã nhận lịch đăng ('.$publication->scheduled_at?->toIso8601String().'); adapter này không hủy lịch qua API'.($note === null ? '' : ' ('.$note.')').' — CHƯA HỦY. Hủy trên nền tảng rồi ghi nhận "Đã hủy lịch trên nền tảng" ở đây.',
            VideoSocialPublication::QUEUED, VideoSocialPublication::UPLOADING, VideoSocialPublication::PROCESSING => 'đang được gửi — chưa hủy được và không báo là đã hủy; bấm Đối chiếu để xem kết quả.',
            VideoSocialPublication::UNKNOWN => 'chưa rõ kết quả trên nền tảng — không báo là đã hủy; bấm Đối chiếu, hoặc kiểm tra trên nền tảng rồi ghi nhận.',
            VideoSocialPublication::PUBLISHED, VideoSocialPublication::MANUAL_REPORTED => 'đã đăng ('.($publication->external_url ?? 'không có URL').') — lịch sử được giữ; bài trên nền tảng không bị xóa.',
            default => 'đã ở trạng thái '.$publication->status.'.',
        };
    }

    /**
     * @param  string  $local  a time as a person types it, without a zone
     * @return Carbon that moment in UTC, read on the clock of the channel
     *
     * @throws RuntimeException
     */
    private static function localTime(string $local, string $timezone): Carbon
    {
        try {
            return Carbon::parse(trim($local), $timezone)->utc();
        } catch (\Throwable) {
            throw new RuntimeException('Không đọc được thời điểm '.json_encode($local).' — nhập dạng 2026-12-31 20:30.');
        }
    }

    /** @return array{step: string, status: string, at: string, detail: ?string} */
    private static function step(string $step, string $status, ?string $detail): array
    {
        return ['step' => $step, 'status' => $status, 'at' => now()->toIso8601String(), 'detail' => $detail];
    }
}
