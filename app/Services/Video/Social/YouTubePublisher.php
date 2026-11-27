<?php

declare(strict_types=1);

namespace App\Services\Video\Social;

use App\Video\Social\SocialPlatformError;
use App\Video\Social\SocialRedaction;
use Carbon\CarbonInterface;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * YouTube, through the Data API v3: a resumable upload with videos.insert, the thumbnail with thumbnails.set, the
 * numbers with the Analytics API. YouTube holds the time of a scheduled video: it is uploaded private with publishAt
 * and YouTube makes it public — nothing here waits for that time.
 *
 * An upload that got no answer is looked up through its upload session, which says whether the bytes arrived; it is
 * never started again on a guess.
 */
final class YouTubePublisher implements SocialPublisher
{
    public const ADAPTER = 'youtube-videos-v1';

    private const UPLOAD = 'https://www.googleapis.com/upload/youtube/v3/videos';

    private const VIDEOS = 'https://www.googleapis.com/youtube/v3/videos';

    private const THUMBNAIL = 'https://www.googleapis.com/upload/youtube/v3/thumbnails/set';

    private const REPORTS = 'https://youtubeanalytics.googleapis.com/v2/reports';

    private const UPLOAD_SCOPE = 'https://www.googleapis.com/auth/youtube.upload';

    /** @var list<string> the scopes videos.update takes; the first is the one a connection is asked for */
    private const UPDATE_SCOPES = ['https://www.googleapis.com/auth/youtube.force-ssl', 'https://www.googleapis.com/auth/youtube', 'https://www.googleapis.com/auth/youtubepartner'];

    /** @var list<string> the reasons Google gives for an allowance that is used up */
    private const QUOTA = ['quotaExceeded', 'dailyLimitExceeded', 'rateLimitExceeded', 'userRateLimitExceeded', 'uploadLimitExceeded', 'uploadRateLimitExceeded'];

    public function platform(): string
    {
        return 'youtube';
    }

    public function adapter(): string
    {
        return self::ADAPTER;
    }

    public function payload(array $document, array $rules, ?CarbonInterface $scheduledAt): array
    {
        $status = [
            // A scheduled video is uploaded private; YouTube makes it public at publishAt.
            'privacyStatus' => $scheduledAt === null ? (string) $document['metadata']['privacy'] : 'private',
            'selfDeclaredMadeForKids' => (bool) $document['metadata']['made_for_kids'],
            'containsSyntheticMedia' => (bool) $document['disclosure']['synthetic_media'],
        ];

        if ($scheduledAt !== null) {
            $status['publishAt'] = $scheduledAt->copy()->utc()->format('Y-m-d\TH:i:s\Z');
        }

        return [
            'snippet' => [
                'title' => (string) $document['rendered']['title'],
                'description' => (string) $document['rendered']['text'],
                'tags' => array_values((array) $document['rendered']['tags']),
                'defaultLanguage' => (string) $document['target']['language'],
            ],
            'status' => $status,
        ];
    }

    public function missingScopes(array $scopes, string $action = 'post'): array
    {
        if ($action === 'cancel') {
            // videos.update takes one of these; youtube.upload is not among them.
            return array_intersect(self::UPDATE_SCOPES, $scopes) === [] ? [self::UPDATE_SCOPES[0]] : [];
        }

        return in_array(self::UPLOAD_SCOPE, $scopes, true) ? [] : [self::UPLOAD_SCOPE];
    }

    public function unschedule(array $account, string $externalId, \Closure $guard): array
    {
        // Every request is preceded by the guard: a caller that lost the posting while one request ran sends no further one.
        $read = function () use ($account, $externalId, $guard): ?array {
            $guard();

            return $this->call(fn () => Http::withToken($account['access_token'])
                ->timeout((int) config('video.social.http_timeout_seconds'))
                ->get(self::VIDEOS, ['part' => 'status', 'id' => $externalId]), false)->json('items.0.status');
        };
        $status = $read();

        if (! is_array($status)) {
            return ['state' => 'unknown', 'detail' => 'YouTube không trả video '.$externalId.' cho tài khoản này — có thể đã bị xóa.'];
        }

        if (self::standingOf($status)['state'] !== 'scheduled') {
            return self::standingOf($status);
        }

        // videos.update replaces the part it is given and deletes a property it leaves out. Every writable field of the
        // status read now is sent back as it is, privacyStatus private, and no publishAt: the schedule is the one thing taken out.
        $kept = array_intersect_key($status, array_flip(['embeddable', 'license', 'publicStatsViewable', 'selfDeclaredMadeForKids', 'containsSyntheticMedia']));
        $guard();
        $this->call(fn () => Http::withToken($account['access_token'])
            ->timeout((int) config('video.social.http_timeout_seconds'))
            ->withBody((string) json_encode(['id' => $externalId, 'status' => ['privacyStatus' => 'private'] + $kept], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'application/json; charset=UTF-8')
            ->put(self::VIDEOS.'?part=status'), true);

        // The documentation names no request as "cancel a schedule": what YouTube reads back is what counts.
        $after = $read();

        return is_array($after) ? self::standingOf($after) : ['state' => 'unknown', 'detail' => 'YouTube không trả lại video '.$externalId.' sau khi cập nhật.'];
    }

    /**
     * @param  array<string, mixed>  $status  the status part of a video
     * @return array{state: string, detail: ?string} whether YouTube holds a schedule for it
     */
    private static function standingOf(array $status): array
    {
        $privacy = $status['privacyStatus'] ?? null;
        $publishAt = $status['publishAt'] ?? null;

        return match (true) {
            $privacy === 'private' && is_string($publishAt) && $publishAt !== '' => ['state' => 'scheduled', 'detail' => 'YouTube vẫn giữ lịch đăng '.$publishAt.'.'],
            $privacy === 'private' => ['state' => 'cancelled', 'detail' => 'YouTube đọc lại: video ở chế độ riêng tư, không còn lịch đăng.'],
            default => ['state' => 'published', 'detail' => 'YouTube báo video đang ở chế độ '.json_encode($privacy).' — đã đăng, không còn lịch để hủy.'],
        };
    }

    public function open(array $account, array $payload, int $bytes): array
    {
        $response = $this->call(fn () => Http::withToken($account['access_token'])
            ->withHeaders(['X-Upload-Content-Length' => (string) $bytes, 'X-Upload-Content-Type' => 'video/mp4'])
            ->timeout((int) config('video.social.http_timeout_seconds'))
            ->withBody((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'application/json; charset=UTF-8')
            ->post(self::UPLOAD.'?uploadType=resumable&part=snippet,status'), false);
        $location = $response->header('Location');

        if ($location === '') {
            throw new SocialPlatformError(SocialPlatformError::SERVER, 'YouTube không trả địa chỉ phiên upload.');
        }

        return ['upload_url' => $location, 'external_id' => null];
    }

    public function send(array $account, array $session, string $file, int $bytes): array
    {
        $stream = fopen($file, 'rb');

        if ($stream === false) {
            throw new SocialPlatformError(SocialPlatformError::REJECTED, 'Không mở được file phim để gửi.');
        }

        try {
            // Once the bytes are on their way, no answer does not mean nothing arrived.
            $response = $this->call(fn () => Http::withToken($account['access_token'])
                ->withHeaders(['Content-Length' => (string) $bytes])
                ->timeout((int) config('video.social.upload_timeout_seconds'))
                ->withBody(Utils::streamFor($stream), 'video/mp4')
                ->put((string) $session['upload_url']), true);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $id = $response->json('id');

        if (! is_string($id) || $id === '') {
            throw new SocialPlatformError(SocialPlatformError::SERVER, 'YouTube nhận file nhưng không trả ID video.', true);
        }

        return ['external_id' => $id] + $session;
    }

    public function finish(array $account, array $session, array $payload): array
    {
        return $session;
    }

    public function state(array $account, array $session, array $payload, int $bytes): array
    {
        $id = $session['external_id'] ?? null;

        if (! is_string($id) || $id === '') {
            if (! is_string($session['upload_url'] ?? null) || $session['upload_url'] === '') {
                // No session was stored before the posting stopped: there is nothing to ask the platform with.
                return self::standing('unknown', null, 'Lượt này dừng trước khi YouTube cấp phiên upload, nên không có gì để hỏi YouTube — kiểm tra trên YouTube Studio rồi ghi nhận kết quả.');
            }

            // The upload session answers whether the bytes arrived: nothing is sent, the range only asks.
            $probe = $this->call(fn () => Http::withToken($account['access_token'])
                ->withHeaders(['Content-Length' => '0', 'Content-Range' => 'bytes */'.$bytes])
                ->timeout((int) config('video.social.http_timeout_seconds'))
                ->withBody('', 'video/mp4')
                ->put((string) ($session['upload_url'] ?? '')), false, [308, 404]);

            if ($probe->status() === 308) {
                return self::standing('upload_incomplete', null, 'YouTube báo phiên upload chưa nhận đủ file.');
            }

            if ($probe->status() === 404 || ! is_string($probe->json('id'))) {
                return self::standing('unknown', null, 'Phiên upload của YouTube đã hết hạn và không trả ID video — kiểm tra trên YouTube Studio.');
            }

            $id = (string) $probe->json('id');
        }

        $video = $this->call(fn () => Http::withToken($account['access_token'])
            ->timeout((int) config('video.social.http_timeout_seconds'))
            ->get(self::VIDEOS, ['part' => 'status,processingDetails', 'id' => $id]), false)->json('items.0');

        if (! is_array($video)) {
            return self::standing('unknown', $id, 'YouTube không trả video '.$id.' cho tài khoản này — có thể đã bị xóa.');
        }

        $upload = (string) ($video['status']['uploadStatus'] ?? '');
        $processing = (string) ($video['processingDetails']['processingStatus'] ?? '');

        if (in_array($upload, ['failed', 'rejected', 'deleted'], true) || in_array($processing, ['failed', 'terminated'], true)) {
            return self::standing('failed', $id, 'YouTube: uploadStatus '.$upload.', processingStatus '.$processing.' — '.($video['status']['failureReason'] ?? $video['status']['rejectionReason'] ?? 'không nêu lý do').'.');
        }

        if ($upload !== 'processed' && $processing !== 'succeeded') {
            return self::standing('processing', $id, 'YouTube đang xử lý video.');
        }

        $publishAt = $video['status']['publishAt'] ?? null;

        if (($video['status']['privacyStatus'] ?? null) === 'private' && is_string($publishAt) && Carbon::parse($publishAt)->isFuture()) {
            return self::standing('scheduled', $id, null, Carbon::parse($publishAt)->utc()->toIso8601String());
        }

        if (isset($payload['status']['publishAt']) && ($video['status']['privacyStatus'] ?? null) === 'private') {
            return self::standing('unknown', $id, 'Video đã qua giờ hẹn nhưng YouTube vẫn để riêng tư và không còn lịch — kiểm tra trên YouTube Studio.');
        }

        return self::standing('published', $id, null, isset($payload['status']['publishAt']) ? Carbon::parse((string) $payload['status']['publishAt'])->utc()->toIso8601String() : null);
    }

    public function cover(array $account, string $externalId, string $file): void
    {
        $this->call(fn () => Http::withToken($account['access_token'])
            ->timeout((int) config('video.social.http_timeout_seconds'))
            ->withBody((string) file_get_contents($file), 'image/jpeg')
            ->post(self::THUMBNAIL.'?uploadType=media&videoId='.rawurlencode($externalId)), false);
    }

    public function metrics(array $account, string $externalId, array $items, CarbonInterface $from, CarbonInterface $to): array
    {
        $names = array_column($items, 'id');
        $report = $this->call(fn () => Http::withToken($account['access_token'])
            ->timeout((int) config('video.social.http_timeout_seconds'))
            ->get(self::REPORTS, [
                'ids' => 'channel==MINE',
                'startDate' => $from->format('Y-m-d'),
                'endDate' => $to->format('Y-m-d'),
                'metrics' => implode(',', $names),
                'filters' => 'video=='.$externalId,
            ]), false)->json();
        $columns = array_column((array) ($report['columnHeaders'] ?? []), 'name');
        $row = (array) ($report['rows'][0] ?? []);
        $values = [];

        // No row is no data for the span: every number is missing, none of them is zero.
        foreach ($names as $name) {
            $index = array_search($name, $columns, true);
            $value = $index === false ? null : ($row[$index] ?? null);
            $values[$name] = is_int($value) || is_float($value) ? $value : null;
        }

        return $values;
    }

    /**
     * @param  \Closure(): Response  $request
     * @param  bool  $mayHaveArrived  whether a request that got no answer may have been carried out all the same
     * @param  list<int>  $expected  statuses that are an answer of their own and not a refusal
     *
     * @throws SocialPlatformError
     */
    private function call(\Closure $request, bool $mayHaveArrived, array $expected = []): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException|TransferException $e) {
            // A connection refused is wrapped by the HTTP client; a reset, a broken read or a stalled body comes as Guzzle's own. Either may follow a request that arrived.
            throw new SocialPlatformError(SocialPlatformError::TIMEOUT, Str::limit(SocialRedaction::text($e->getMessage()), 200), $mayHaveArrived);
        }

        if ($response->successful() || in_array($response->status(), $expected, true)) {
            return $response;
        }

        $reason = (string) ($response->json('error.errors.0.reason') ?? $response->json('error.status') ?? '');
        $message = Str::limit((string) ($response->json('error.message') ?? $response->body()), 300).($reason === '' ? '' : " ({$reason})");

        throw match (true) {
            in_array($reason, self::QUOTA, true) || $response->status() === 429 => new SocialPlatformError(SocialPlatformError::QUOTA, $message),
            $response->status() === 401 => new SocialPlatformError(SocialPlatformError::TOKEN, $message),
            $response->status() === 403 => new SocialPlatformError(SocialPlatformError::PERMISSION, $message),
            $response->status() === 404 => new SocialPlatformError(SocialPlatformError::NOT_FOUND, $message),
            $response->serverError() => new SocialPlatformError(SocialPlatformError::SERVER, $message, $mayHaveArrived),
            default => new SocialPlatformError(SocialPlatformError::REJECTED, $message),
        };
    }

    /** @return array{state: string, external_id: ?string, url: ?string, at: ?string, detail: ?string} */
    private static function standing(string $state, ?string $id, ?string $detail, ?string $at = null): array
    {
        return ['state' => $state, 'external_id' => $id, 'url' => $id === null ? null : 'https://www.youtube.com/watch?v='.$id, 'at' => $at, 'detail' => $detail];
    }
}
