<?php

declare(strict_types=1);

namespace App\Video\Queue;

use App\Video\Concept\Exceptions\AnthropicRequestException;
use App\Video\Llm\AnthropicTransport;
use App\Video\Llm\AnthropicTransportError;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use App\Video\Llm\LlmUnavailable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class ProviderRateLimit
{
    public const RATE_LIMIT = 'rate_limit';

    public const QUOTA = 'quota';

    public const ANTHROPIC = 'anthropic';

    /** @var list<int> */
    private const TRANSIENT_STATUSES = [429, 529];

    /** @var int the calls an image client may send in a web request: the first, and one more after a short rate limit */
    public const IMAGE_ATTEMPTS = 2;

    /** @var list<int> */
    private const IMAGE_TRANSIENT_STATUSES = [429, 503];

    /** @return PendingRequest the request sent once more after a rate limit only in a web request, after exactly the wait the provider asked for and only while that wait is short; a queued render sends once and leaves every rate limit to the queue */
    public static function imageRetry(PendingRequest $request): PendingRequest
    {
        if (! (bool) config('video.async.rate_limit.client_retry', true)) {
            return $request;
        }

        $limit = (int) config('video.async.rate_limit.client_wait_seconds');
        $wait = static fn (\Throwable $e): ?int => $e instanceof RequestException && $e->response->status() === 429 ? (self::imageRetryAfter($e->response) ?? 1) : null;

        return $request->retry(
            self::IMAGE_ATTEMPTS,
            static fn (int $attempt, \Throwable $e): int => max(1, (int) $wait($e)) * 1000,
            static function (\Throwable $e) use ($wait, $limit): bool {
                $seconds = $wait($e);

                return $seconds !== null && $seconds <= $limit;
            },
            false,
        );
    }

    /** @return ?int the seconds an image provider asked to wait: OpenAI retry-after-ms or retry-after, Gemini retryDelay */
    public static function imageRetryAfter(Response $response): ?int
    {
        $milliseconds = $response->header('retry-after-ms');

        if (is_numeric($milliseconds)) {
            return (int) ceil((float) $milliseconds / 1000);
        }

        $delay = collect((array) $response->json('error.details'))->first(static fn (mixed $detail): bool => is_array($detail) && isset($detail['retryDelay']))['retryDelay'] ?? null;

        if (is_string($delay) && preg_match('/^(\d+(?:\.\d+)?)s$/', $delay, $match) === 1) {
            return (int) ceil((float) $match[1]);
        }

        return AnthropicTransport::retryAfter($response->header('retry-after'));
    }

    /** @var list<string> */
    private const QUOTA_CODES = ['insufficient_quota', 'billing_hard_limit_reached', 'billing_not_active'];

    /**
     * @param  string  $provider  openai or gemini, as the cell spec routes it
     * @param  array{status?: mixed, code?: mixed, message?: mixed, retry_after?: mixed}  $refusal  what the image client read from the refused call
     * @return ?array{kind: string, provider: string, retry_after: ?int} a rate limit or overload the queue waits out, or a spent quota a person must settle; null for every other failure
     */
    public static function ofImage(string $provider, array $refusal): ?array
    {
        $status = (int) ($refusal['status'] ?? 0);
        $retryAfter = is_int($refusal['retry_after'] ?? null) ? $refusal['retry_after'] : null;

        if (in_array($status, [402, 403, 429], true)
            && (in_array(mb_strtolower((string) ($refusal['code'] ?? '')), self::QUOTA_CODES, true) || str_contains(mb_strtolower((string) ($refusal['message'] ?? '')), 'billing'))) {
            return ['kind' => self::QUOTA, 'provider' => $provider, 'retry_after' => null];
        }

        return in_array($status, self::IMAGE_TRANSIENT_STATUSES, true)
            ? ['kind' => self::RATE_LIMIT, 'provider' => $provider, 'retry_after' => $retryAfter]
            : null;
    }

    /** @return ?array{kind: string, provider: string, retry_after: ?int} a provider refusal sent back before anything was generated, null for every other failure */
    public static function of(\Throwable $e): ?array
    {
        $unbilled = ($e instanceof LlmUnavailable && $e->billed === null) || ($e instanceof AnthropicRequestException && $e->response === null);

        if (! $unbilled) {
            return null;
        }

        $cause = $e->getPrevious();

        if (! $cause instanceof AnthropicTransportError || $cause->kind !== AnthropicTransportError::HTTP) {
            return null;
        }

        if ($cause->errorType === 'insufficient_quota' || str_contains(mb_strtolower((string) $cause->providerMessage), 'credit balance')) {
            return ['kind' => self::QUOTA, 'provider' => self::ANTHROPIC, 'retry_after' => null];
        }

        if (in_array($cause->status, self::TRANSIENT_STATUSES, true)) {
            return ['kind' => self::RATE_LIMIT, 'provider' => self::ANTHROPIC, 'retry_after' => $cause->retryAfterSeconds];
        }

        return null;
    }

    public static function cooldownRemaining(string $provider, string $model): int
    {
        $until = Cache::get(self::key($provider, $model));

        return is_int($until) ? max(0, $until - time()) : 0;
    }

    /** @return bool false when the lock stayed busy: the shared cooldown is left as it was, never written outside the lock */
    public static function coolDown(string $provider, string $model, int $seconds): bool
    {
        $key = self::key($provider, $model);
        $until = time() + $seconds;

        try {
            Cache::lock(self::lockName($provider, $model), 10)->block((int) config('video.async.rate_limit.cooldown_lock_seconds'), static function () use ($key, $until, $seconds): void {
                if ((int) Cache::get($key, 0) < $until) {
                    Cache::put($key, $until, $seconds + 60);
                }
            });
        } catch (LockTimeoutException $e) {
            Log::warning('video rate limit: the cooldown lock stayed busy, the shared cooldown is not extended; the run still waits its own delay', ['key' => $key, 'seconds' => $seconds]);

            return false;
        }

        return true;
    }

    public static function lockName(string $provider, string $model): string
    {
        return self::key($provider, $model).':lock';
    }

    public static function delay(?int $retryAfter, int $attempt, int $cooldown): int
    {
        $cap = (int) config('video.async.rate_limit.max_delay_seconds');
        $backoff = min($cap, (int) config('video.async.rate_limit.default_retry_seconds') * (2 ** max(0, $attempt - 1)));
        $delay = max($retryAfter ?? 0, $backoff, $cooldown);

        return $delay + random_int(0, max(1, intdiv($delay, 10)));
    }

    private static function key(string $provider, string $model): string
    {
        return 'video:api-cooldown:'.$provider.':'.$model;
    }
}
