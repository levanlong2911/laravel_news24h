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
 * A Facebook Page, through the Reels publishing flow of the Graph API: start an upload, send the bytes, finish with
 * the text and the state. Facebook holds the time of a scheduled reel. A cover and an AI label are not set here — the
 * publishing guide names no request for either — so both are left to a person, and the platform policy says so.
 *
 * Facebook names the video before any byte is sent, so a posting that got no answer is looked up by that id.
 */
final class FacebookPublisher implements SocialPublisher
{
    public const ADAPTER = 'facebook-reels-v1';

    private const POSTING_SCOPE = 'pages_manage_posts';

    public function platform(): string
    {
        return 'facebook';
    }

    public function adapter(): string
    {
        return self::ADAPTER;
    }

    public function payload(array $document, array $rules, ?CarbonInterface $scheduledAt): array
    {
        $payload = ['description' => (string) $document['rendered']['text'], 'video_state' => $scheduledAt === null ? 'PUBLISHED' : 'SCHEDULED'];

        if (is_string($document['rendered']['title'])) {
            $payload['title'] = $document['rendered']['title'];
        }

        if ($scheduledAt !== null) {
            $payload['scheduled_publish_time'] = $scheduledAt->getTimestamp();
        }

        return $payload;
    }

    public function missingScopes(array $scopes, string $action = 'post'): array
    {
        // Nothing is sent to cancel a schedule (see unschedule()), so nothing is needed for it.
        return $action === 'cancel' || in_array(self::POSTING_SCOPE, $scopes, true) ? [] : [self::POSTING_SCOPE];
    }

    public function unschedule(array $account, string $externalId, \Closure $guard): array
    {
        // The Reels guide and the Video reference read on 2026-11-22 document no request that cancels or moves the schedule of a Reel, and the video_reels edge says it cannot be updated or deleted.
        return ['state' => 'unsupported', 'detail' => 'Graph API không có tài liệu về hủy hay đổi lịch của Reel — chưa gửi gì. Hủy lịch trên Meta Business Suite rồi ghi nhận "Đã hủy lịch trên nền tảng" ở đây.'];
    }

    public function open(array $account, array $payload, int $bytes): array
    {
        $started = $this->call(fn () => Http::asForm()->timeout((int) config('video.social.http_timeout_seconds'))
            ->post(self::graph($account['external_id'].'/video_reels'), ['upload_phase' => 'start', 'access_token' => $account['access_token']]), false)->json();

        if (! is_string($started['video_id'] ?? null) || ! is_string($started['upload_url'] ?? null)) {
            throw new SocialPlatformError(SocialPlatformError::SERVER, 'Facebook không trả video_id và upload_url.');
        }

        return ['external_id' => $started['video_id'], 'upload_url' => $started['upload_url']];
    }

    public function send(array $account, array $session, string $file, int $bytes): array
    {
        $stream = fopen($file, 'rb');

        if ($stream === false) {
            throw new SocialPlatformError(SocialPlatformError::REJECTED, 'Không mở được file phim để gửi.');
        }

        try {
            $this->call(fn () => Http::withHeaders(['Authorization' => 'OAuth '.$account['access_token'], 'offset' => '0', 'file_size' => (string) $bytes])
                ->timeout((int) config('video.social.upload_timeout_seconds'))
                ->withBody(Utils::streamFor($stream), 'application/octet-stream')
                ->post((string) $session['upload_url']), true);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $session;
    }

    public function finish(array $account, array $session, array $payload): array
    {
        $this->call(fn () => Http::asForm()->timeout((int) config('video.social.http_timeout_seconds'))
            ->post(self::graph($account['external_id'].'/video_reels'), ['access_token' => $account['access_token'], 'video_id' => (string) $session['external_id'], 'upload_phase' => 'finish'] + $payload), true);

        return $session;
    }

    public function state(array $account, array $session, array $payload, int $bytes): array
    {
        $id = (string) ($session['external_id'] ?? '');

        if ($id === '') {
            return self::standing('upload_incomplete', null, 'Facebook chưa cấp video_id — chưa có gì được gửi.');
        }

        $status = (array) $this->call(fn () => Http::timeout((int) config('video.social.http_timeout_seconds'))
            ->get(self::graph($id), ['fields' => 'status', 'access_token' => $account['access_token']]), false)->json('status');
        $done = static fn (mixed $phase): bool => in_array($phase['status'] ?? null, ['complete', 'completed'], true);
        $publishing = (array) ($status['publishing_phase'] ?? []);
        $at = is_numeric($publishing['publish_time'] ?? null) ? Carbon::createFromTimestampUTC((int) $publishing['publish_time'])->toIso8601String() : null;

        if (in_array($status['video_status'] ?? null, ['error', 'upload_failed', 'expired'], true)
            || ($status['processing_phase']['status'] ?? null) === 'error' || ($publishing['status'] ?? null) === 'error' || ($publishing['publish_status'] ?? null) === 'error') {
            return self::standing('failed', $id, 'Facebook: video_status '.json_encode($status['video_status'] ?? null).' — '.json_encode($status['processing_phase']['error'] ?? $publishing['error'] ?? $status['uploading_phase']['error'] ?? 'không nêu lý do', JSON_UNESCAPED_UNICODE).'.');
        }

        return match (true) {
            ($publishing['publish_status'] ?? null) === 'published' => self::standing('published', $id, null, $at),
            ($publishing['publish_status'] ?? null) === 'scheduled' => self::standing('scheduled', $id, null, $at ?? (isset($payload['scheduled_publish_time']) ? Carbon::createFromTimestampUTC((int) $payload['scheduled_publish_time'])->toIso8601String() : null)),
            ! $done($status['uploading_phase'] ?? []) => self::standing('upload_incomplete', $id, 'Facebook báo chưa nhận đủ file.'),
            ($publishing['status'] ?? 'not_started') === 'not_started' && ($status['processing_phase']['status'] ?? 'not_started') === 'not_started' => self::standing('uploaded', $id, 'Facebook đã nhận file; bước đăng chưa bắt đầu.'),
            default => self::standing('processing', $id, 'Facebook đang xử lý video.'),
        };
    }

    public function cover(array $account, string $externalId, string $file): void
    {
        throw new SocialPlatformError(SocialPlatformError::REJECTED, 'Adapter Facebook Reels không đặt cover qua API — đặt cover bằng tay trên nền tảng.');
    }

    public function metrics(array $account, string $externalId, array $items, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = (array) $this->call(fn () => Http::timeout((int) config('video.social.http_timeout_seconds'))
            ->get(self::graph($externalId.'/video_insights'), ['metric' => implode(',', array_column($items, 'id')), 'access_token' => $account['access_token']]), false)->json('data');
        $given = [];

        foreach ($rows as $row) {
            if (is_string($row['name'] ?? null)) {
                $given[$row['name']] = $row['values'][0]['value'] ?? null;
            }
        }

        $values = [];

        foreach ($items as $item) {
            $value = $given[$item['id']] ?? null;

            if ($item['shape'] === 'map') {
                // One number a key. A key Facebook does not list is not a zero: it is simply not there.
                foreach (is_array($value) ? $value : [] as $key => $number) {
                    $values[$item['id'].'.'.Str::lower((string) $key)] = is_int($number) || is_float($number) ? $number : null;
                }

                if (! is_array($value)) {
                    $values[$item['id']] = null;
                }

                continue;
            }

            $values[$item['id']] = is_int($value) || is_float($value) ? $value : null;
        }

        return $values;
    }

    /**
     * @param  \Closure(): Response  $request
     * @param  bool  $mayHaveArrived  whether a request that got no answer may have been carried out all the same
     *
     * @throws SocialPlatformError
     */
    private function call(\Closure $request, bool $mayHaveArrived): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException|TransferException $e) {
            // A connection refused is wrapped by the HTTP client; a reset, a broken read or a stalled body comes as Guzzle's own. Either may follow a request that arrived.
            throw new SocialPlatformError(SocialPlatformError::TIMEOUT, Str::limit(SocialRedaction::text($e->getMessage()), 200), $mayHaveArrived);
        }

        if ($response->successful() && $response->json('error') === null) {
            return $response;
        }

        $code = (int) ($response->json('error.code') ?? 0);
        $message = Str::limit((string) ($response->json('error.message') ?? $response->body()), 300).($code === 0 ? '' : " (code {$code})");

        throw match (true) {
            $code === 190 => new SocialPlatformError(SocialPlatformError::TOKEN, $message),
            $code === 10 || ($code >= 200 && $code <= 299) => new SocialPlatformError(SocialPlatformError::PERMISSION, $message),
            in_array($code, [4, 17, 32, 613], true) || ($code >= 80000 && $code <= 80014) || $response->status() === 429 => new SocialPlatformError(SocialPlatformError::QUOTA, $message),
            $response->serverError() || in_array($code, [1, 2], true) => new SocialPlatformError(SocialPlatformError::SERVER, $message, $mayHaveArrived),
            $response->status() === 404 => new SocialPlatformError(SocialPlatformError::NOT_FOUND, $message),
            default => new SocialPlatformError(SocialPlatformError::REJECTED, $message),
        };
    }

    private static function graph(string $path): string
    {
        return 'https://graph.facebook.com/'.config('services.facebook.graph_version').'/'.$path;
    }

    /** @return array{state: string, external_id: ?string, url: ?string, at: ?string, detail: ?string} */
    private static function standing(string $state, ?string $id, ?string $detail, ?string $at = null): array
    {
        return ['state' => $state, 'external_id' => $id, 'url' => $id === null ? null : 'https://www.facebook.com/reel/'.$id, 'at' => $at, 'detail' => $detail];
    }
}
