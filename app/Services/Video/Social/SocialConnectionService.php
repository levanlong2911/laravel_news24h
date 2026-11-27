<?php

declare(strict_types=1);

namespace App\Services\Video\Social;

use App\Models\VideoSocialChannel;
use App\Models\VideoSocialConnection;
use App\Video\Social\SocialPlatformError;
use App\Video\Social\SocialRedaction;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The accounts a person connected, and what a request to a platform is signed with. A connection lists the pages or
 * channels the account can post to by the id the platform knows each by; a channel profile is verified when, and only
 * when, its own id is one of those — a profile is never tied to an account because their names look alike.
 *
 * Tokens stay here: stored encrypted by the model, read when a request is made, and put in no prompt, no manifest,
 * no hash, no event and no log line.
 */
final class SocialConnectionService
{
    private const GOOGLE_AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const GOOGLE_TOKEN = 'https://oauth2.googleapis.com/token';

    private const YOUTUBE_CHANNELS = 'https://www.googleapis.com/youtube/v3/channels';

    /** @return SocialPublisher the publisher of a platform */
    public static function publisher(string $platform): SocialPublisher
    {
        return match ($platform) {
            'youtube' => app(YouTubePublisher::class),
            'facebook' => app(FacebookPublisher::class),
            default => throw new RuntimeException("Chưa có adapter đăng cho nền tảng {$platform}."),
        };
    }

    /** @return bool whether posting through the API of the platform is switched on */
    public static function publishing(string $platform): bool
    {
        return SocialSourceService::enabled() && (bool) (config('video.social.publish')[$platform] ?? false);
    }

    /**
     * @return string where a person is sent to grant access
     *
     * @throws RuntimeException when the app of the platform is not configured
     */
    public function authorizeUrl(string $platform, string $state, string $redirect): string
    {
        $app = $this->app($platform);

        return $platform === 'youtube'
            // offline + consent: Google gives a refresh token only then, and only it keeps a connection alive past the hour.
            ? self::GOOGLE_AUTH.'?'.http_build_query(['client_id' => $app['client_id'], 'redirect_uri' => $redirect, 'response_type' => 'code', 'scope' => implode(' ', $app['scopes']), 'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state])
            : 'https://www.facebook.com/'.$app['graph_version'].'/dialog/oauth?'.http_build_query(['client_id' => $app['client_id'], 'redirect_uri' => $redirect, 'state' => $state, 'scope' => implode(',', $app['scopes'])]);
    }

    /**
     * Exchanges the code a platform sent back for tokens, lists the pages or channels of the account, stores the
     * connection, and verifies every channel profile whose id is among them.
     *
     * @return array{0: VideoSocialConnection, 1: int} the connection, and how many profiles it verified
     *
     * @throws RuntimeException when the platform refuses the code or lists no page or channel
     */
    public function connect(string $platform, string $code, string $redirect, ?string $actorId): array
    {
        $app = $this->app($platform);

        try {
            [$tokens, $accounts, $label, $accountId] = $platform === 'youtube' ? $this->google($app, $code, $redirect) : $this->facebook($app, $code, $redirect);
        } catch (ConnectionException|TransferException $e) {
            // The URL of an OAuth exchange holds the app secret and the code: the message is shown only with them hidden.
            throw new RuntimeException('Không kết nối được tới nền tảng: '.Str::limit(SocialRedaction::text($e->getMessage()), 200));
        }

        if ($accounts === []) {
            throw new RuntimeException('Tài khoản vừa cấp quyền không có trang hoặc kênh nào để đăng.');
        }

        return DB::transaction(function () use ($platform, $tokens, $accounts, $label, $accountId, $actorId): array {
            $connection = VideoSocialConnection::query()->where('platform', $platform)->where('external_account_id', $accountId)->lockForUpdate()->first() ?? new VideoSocialConnection(['platform' => $platform, 'external_account_id' => $accountId]);
            $connection->fill([
                'label' => $label,
                'access_token' => $tokens['access_token'],
                // Google sends a refresh token on the first consent only: one already held is kept.
                'refresh_token' => $tokens['refresh_token'] ?? $connection->refresh_token,
                'token_expires_at' => isset($tokens['expires_in']) ? now()->addSeconds((int) $tokens['expires_in']) : null,
                'scopes' => $tokens['scopes'],
                'accounts' => $accounts,
                'status' => VideoSocialConnection::ACTIVE,
                'last_error' => null,
                'connected_by' => $actorId,
            ])->save();

            return [$connection, $this->verify($connection)];
        });
    }

    /**
     * Ties every channel profile of the connection's platform whose id the connection lists to it. A profile whose id
     * the connection no longer lists loses its verification.
     *
     * @return int how many profiles are verified by the connection now
     */
    public function verify(VideoSocialConnection $connection): int
    {
        $ids = array_map('strval', array_column((array) $connection->accounts, 'id'));
        VideoSocialChannel::query()->where('connection_id', $connection->id)->whereNotIn('external_id', $ids === [] ? [''] : $ids)->update(['connection_id' => null, 'verified_at' => null]);

        if ($ids === []) {
            return 0;
        }

        VideoSocialChannel::query()->where('platform', $connection->platform)->whereIn('external_id', $ids)->where(static fn ($query) => $query->whereNull('connection_id')->orWhere('connection_id', '!=', $connection->id))->update(['connection_id' => $connection->id, 'verified_at' => now()]);

        return VideoSocialChannel::query()->where('connection_id', $connection->id)->count();
    }

    /** Ends a connection: its tokens are dropped and its profiles are unverified. What was posted through it stays in the history. */
    public function disconnect(string $connectionId): void
    {
        DB::transaction(static function () use ($connectionId): void {
            $connection = VideoSocialConnection::query()->lockForUpdate()->findOrFail($connectionId);
            $connection->fill(['status' => VideoSocialConnection::REVOKED, 'access_token' => null, 'refresh_token' => null, 'accounts' => [], 'token_expires_at' => null])->save();
            VideoSocialChannel::query()->where('connection_id', $connectionId)->update(['connection_id' => null, 'verified_at' => null]);
        });
    }

    /**
     * @return list<string> why the channel cannot be posted to through the API, known without asking the platform; empty when nothing is known against it
     */
    public function problems(VideoSocialChannel $channel): array
    {
        $connection = $channel->connection;

        if (! $channel->verified() || $connection === null) {
            return ['Kênh chưa được xác minh qua kết nối API — kết nối tài khoản có quyền trên đúng ID '.json_encode($channel->external_id).'.'];
        }

        if ($connection->status !== VideoSocialConnection::ACTIVE) {
            return ['Kết nối của kênh không còn hoạt động ('.$connection->status.') — kết nối lại.'];
        }

        $missing = self::publisher((string) $channel->platform)->missingScopes((array) $connection->scopes);

        return $missing === [] ? [] : ['Kết nối thiếu quyền: '.implode(', ', $missing).' — kết nối lại và cấp đủ quyền.'];
    }

    /**
     * @return array{access_token: string, external_id: string} what a request for the channel is signed with, read now
     *
     * @throws SocialPlatformError when the channel has no live connection, or its token cannot be renewed
     */
    public function account(VideoSocialChannel $channel): array
    {
        $connection = $channel->connection;

        if (! $channel->verified() || $connection === null || $connection->status !== VideoSocialConnection::ACTIVE) {
            throw new SocialPlatformError(SocialPlatformError::CONFIG, 'kênh chưa có kết nối API đang hoạt động.');
        }

        if ($channel->platform === 'facebook') {
            $page = collect((array) $connection->accounts)->firstWhere('id', (string) $channel->external_id);

            if (! is_string($page['access_token'] ?? null) || $page['access_token'] === '') {
                throw new SocialPlatformError(SocialPlatformError::TOKEN, 'kết nối không giữ token của trang này — kết nối lại.');
            }

            return ['access_token' => $page['access_token'], 'external_id' => (string) $channel->external_id];
        }

        return ['access_token' => $this->freshGoogleToken($connection), 'external_id' => (string) $channel->external_id];
    }

    /** Marks a connection the platform refused as expired, so the screen says to connect again. The reason kept names no token. */
    public function expire(VideoSocialChannel $channel, string $reason): void
    {
        if ($channel->connection_id !== null) {
            VideoSocialConnection::query()->whereKey($channel->connection_id)->update(['status' => VideoSocialConnection::EXPIRED, 'last_error' => Str::limit($reason, 500)]);
        }
    }

    /**
     * @return string an access token that is good for at least a minute, renewed with the refresh token when it is not
     *
     * @throws SocialPlatformError
     */
    private function freshGoogleToken(VideoSocialConnection $connection): string
    {
        if (is_string($connection->access_token) && $connection->access_token !== '' && ($connection->token_expires_at === null || $connection->token_expires_at->gt(now()->addMinute()))) {
            return $connection->access_token;
        }

        if (! is_string($connection->refresh_token) || $connection->refresh_token === '') {
            throw new SocialPlatformError(SocialPlatformError::TOKEN, 'kết nối không có refresh token — kết nối lại tài khoản.');
        }

        $app = $this->app('youtube');

        try {
            $response = Http::asForm()->timeout((int) config('video.social.http_timeout_seconds'))->post(self::GOOGLE_TOKEN, ['client_id' => $app['client_id'], 'client_secret' => $app['client_secret'], 'grant_type' => 'refresh_token', 'refresh_token' => $connection->refresh_token]);
        } catch (ConnectionException|TransferException $e) {
            throw new SocialPlatformError(SocialPlatformError::TIMEOUT, 'không làm mới được token: '.Str::limit(SocialRedaction::text($e->getMessage()), 200));
        }

        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            $connection->forceFill(['status' => VideoSocialConnection::EXPIRED, 'last_error' => 'Google từ chối làm mới token: '.Str::limit((string) ($response->json('error') ?? $response->status()), 100)])->save();

            throw new SocialPlatformError(SocialPlatformError::TOKEN, 'Google từ chối làm mới token ('.($response->json('error') ?? $response->status()).') — kết nối lại tài khoản.');
        }

        $connection->forceFill(['access_token' => $response->json('access_token'), 'token_expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600))])->save();

        return (string) $response->json('access_token');
    }

    /**
     * @param  array<string, mixed>  $app
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>, 2: string, 3: string} tokens, the channels of the account, a label and the id of the account
     */
    private function google(array $app, string $code, string $redirect): array
    {
        $tokens = Http::asForm()->timeout((int) config('video.social.http_timeout_seconds'))->post(self::GOOGLE_TOKEN, ['code' => $code, 'client_id' => $app['client_id'], 'client_secret' => $app['client_secret'], 'redirect_uri' => $redirect, 'grant_type' => 'authorization_code']);

        if (! $tokens->successful() || ! is_string($tokens->json('access_token'))) {
            throw new RuntimeException('Google từ chối mã cấp quyền: '.Str::limit((string) ($tokens->json('error_description') ?? $tokens->json('error') ?? $tokens->status()), 200));
        }

        $channels = Http::withToken((string) $tokens->json('access_token'))->timeout((int) config('video.social.http_timeout_seconds'))->get(self::YOUTUBE_CHANNELS, ['part' => 'snippet', 'mine' => 'true']);

        if (! $channels->successful()) {
            throw new RuntimeException('Không đọc được kênh YouTube của tài khoản: '.Str::limit((string) ($channels->json('error.message') ?? $channels->status()), 200));
        }

        $accounts = array_values(array_map(static fn (array $item): array => ['id' => (string) $item['id'], 'name' => (string) ($item['snippet']['title'] ?? '')], array_filter((array) $channels->json('items'), static fn (mixed $item): bool => is_array($item) && is_string($item['id'] ?? null))));

        return [
            ['access_token' => $tokens->json('access_token'), 'refresh_token' => $tokens->json('refresh_token'), 'expires_in' => $tokens->json('expires_in'), 'scopes' => array_values(array_filter(explode(' ', (string) $tokens->json('scope'))))],
            $accounts,
            'YouTube — '.($accounts[0]['name'] ?? 'tài khoản Google'),
            (string) ($accounts[0]['id'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $app
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>, 2: string, 3: string} tokens, the pages of the person, a label and the id of the person
     */
    private function facebook(array $app, string $code, string $redirect): array
    {
        $graph = 'https://graph.facebook.com/'.$app['graph_version'];
        $timeout = (int) config('video.social.http_timeout_seconds');
        $tokens = Http::timeout($timeout)->get($graph.'/oauth/access_token', ['client_id' => $app['client_id'], 'redirect_uri' => $redirect, 'client_secret' => $app['client_secret'], 'code' => $code]);

        if (! $tokens->successful() || ! is_string($tokens->json('access_token'))) {
            throw new RuntimeException('Facebook từ chối mã cấp quyền: '.Str::limit((string) ($tokens->json('error.message') ?? $tokens->status()), 200));
        }

        $token = (string) $tokens->json('access_token');
        $expires = $tokens->json('expires_in');
        // A long-lived token of the person gives page tokens that last; when Facebook does not give one the short-lived token is kept and the connection says when it ends.
        $long = Http::timeout($timeout)->get($graph.'/oauth/access_token', ['grant_type' => 'fb_exchange_token', 'client_id' => $app['client_id'], 'client_secret' => $app['client_secret'], 'fb_exchange_token' => $token]);

        if ($long->successful() && is_string($long->json('access_token'))) {
            $token = (string) $long->json('access_token');
            $expires = $long->json('expires_in');
        }

        $me = Http::timeout($timeout)->get($graph.'/me', ['fields' => 'id,name', 'access_token' => $token]);
        $pages = Http::timeout($timeout)->get($graph.'/me/accounts', ['fields' => 'id,name,access_token,tasks', 'limit' => 100, 'access_token' => $token]);

        if (! $me->successful() || ! $pages->successful()) {
            throw new RuntimeException('Không đọc được danh sách trang của tài khoản Facebook: '.Str::limit((string) ($pages->json('error.message') ?? $me->json('error.message') ?? $pages->status()), 200));
        }

        $accounts = array_values(array_map(static fn (array $page): array => ['id' => (string) $page['id'], 'name' => (string) ($page['name'] ?? ''), 'access_token' => (string) ($page['access_token'] ?? ''), 'tasks' => array_values((array) ($page['tasks'] ?? []))], array_filter((array) $pages->json('data'), static fn (mixed $page): bool => is_array($page) && is_string($page['id'] ?? null))));

        return [
            // Facebook does not send the scopes back with the token: what was asked for is recorded, and what a page lacks shows when a request is refused.
            ['access_token' => $token, 'refresh_token' => null, 'expires_in' => $expires, 'scopes' => array_values((array) $app['scopes'])],
            $accounts,
            'Facebook — '.($me->json('name') ?? 'tài khoản'),
            (string) $me->json('id'),
        ];
    }

    /**
     * @return array<string, mixed> the app a platform is reached through
     *
     * @throws RuntimeException when it is not configured
     */
    private function app(string $platform): array
    {
        $app = (array) config('services.'.$platform);

        if (! in_array($platform, ['youtube', 'facebook'], true) || trim((string) ($app['client_id'] ?? '')) === '' || trim((string) ($app['client_secret'] ?? '')) === '') {
            throw new RuntimeException($platform === 'youtube'
                ? 'Chưa cấu hình ứng dụng Google (YOUTUBE_CLIENT_ID, YOUTUBE_CLIENT_SECRET) — chưa kết nối được.'
                : 'Chưa cấu hình ứng dụng Facebook (FACEBOOK_APP_ID, FACEBOOK_APP_SECRET) — chưa kết nối được.');
        }

        return $app;
    }
}
