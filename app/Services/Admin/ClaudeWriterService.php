<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ClaudeWriterService
{

    public const PRICING_VERSION = 'anthropic-v2026-09-04';

    private const MODEL_CATALOG = [
        'haiku' => [
            'id' => 'claude-haiku-4-5-20251001',
            'max_tokens' => 4096,
            'input_usd_per_mtok' => 1.00,
            'output_usd_per_mtok' => 5.00,
            'thinking' => null,
            'effort' => null,
        ],
        'haiku5' => [
            'id' => 'claude-haiku-5-5',
            'max_tokens' => 16000,
            'input_usd_per_mtok' => 0.10,
            'output_usd_per_mtok' => 0.50,
            'thinking' => null,
            'effort' => null,
        ],
        'sonnet55' => [
            'id' => 'claude-sonnet-5-5',
            'max_tokens' => 32000,
            'input_usd_per_mtok' => 2.00,
            'output_usd_per_mtok' => 10.00,
            'thinking' => null,
            'effort' => null,
        ],
        'opus55' => [
            'id' => 'claude-opus-5-5',
            'max_tokens' => 32000,
            'input_usd_per_mtok' => 4.00,
            'output_usd_per_mtok' => 20.00,
            'thinking' => null,
            'effort' => null,
        ],
        'sonnet' => [
            'id' => 'claude-sonnet-5',
            'max_tokens' => 8000,
            'input_usd_per_mtok' => 2.00,
            'output_usd_per_mtok' => 10.00,
            'thinking' => 'disabled',
            'effort' => 'medium',
        ],
        'sonnet5' => [
            'id' => 'claude-sonnet-5',
            'max_tokens' => 8000,
            'input_usd_per_mtok' => 2.00,
            'output_usd_per_mtok' => 10.00,
            'thinking' => 'disabled',
            'effort' => null,
        ],
    ];


    private const CACHE_WRITE_MULTIPLIER = 1.25;

    private const CACHE_READ_MULTIPLIER = 0.10;

    private const MAX_RETRIES = 5;

    private const MAX_TIMEOUT_ATTEMPTS = 2;

    private const TIMEOUT_S = 60;

    private const BASE_DELAY_S = 3;   // normal errors: 3s, 6s, 12s...

    private const DELAY_529_S = 30;  // 529 Overloaded: 30s, 60s, 90s...

    private const RPM_LIMIT = 40;  // max requests/phút gửi tới Anthropic

    private const MAX_CONCURRENT = 8;   // max đồng thời (parallel workers)

    public function __construct(
        private readonly RequestLedgerRecorder $ledger = new RequestLedgerRecorder(),
    ) {}

    public static function supports(string $modelType): bool
    {
        return isset(self::MODEL_CATALOG[$modelType]);
    }

    /**
     * @return array{id: string, max_tokens: int, input_usd_per_mtok: float, output_usd_per_mtok: float, thinking: ?string, effort: ?string}
     */
    private static function entry(string $modelType): array
    {
        if (! isset(self::MODEL_CATALOG[$modelType])) {
            throw new \InvalidArgumentException(sprintf(
                'Khoa model khong co trong MODEL_CATALOG: "%s". Hop le: %s',
                $modelType,
                implode(', ', array_keys(self::MODEL_CATALOG)),
            ));
        }

        return self::MODEL_CATALOG[$modelType];
    }

    public static function modelId(string $modelType): string
    {
        return self::entry($modelType)['id'];
    }

    public static function maxTokensFor(string $modelType): int
    {
        return self::entry($modelType)['max_tokens'];
    }

    /**
     * @param  int  $cacheWriteTokens  `usage.cache_creation_input_tokens`
     * @param  int  $cacheReadTokens  `usage.cache_read_input_tokens`

     */
    public static function costUsd(
        int $inputTokens,
        int $outputTokens,
        string $modelType,
        int $cacheWriteTokens = 0,
        int $cacheReadTokens = 0,
    ): float {
        $entry = self::entry($modelType);
        $priceIn = $entry['input_usd_per_mtok'];
        $priceOut = $entry['output_usd_per_mtok'];

        return (
            $inputTokens * $priceIn
            + $outputTokens * $priceOut
            + $cacheWriteTokens * $priceIn * self::CACHE_WRITE_MULTIPLIER
            + $cacheReadTokens * $priceIn * self::CACHE_READ_MULTIPLIER
        ) / 1_000_000;
    }

    /** @return float the cost of the usage at its model's price, at the sonnet price for a model the catalog does not have */
    public static function costOf(TokenUsage $usage): float
    {
        return self::costUsd(
            $usage->inputTokens,
            $usage->outputTokens,
            self::supports($usage->modelType) ? $usage->modelType : 'sonnet',
            $usage->cacheCreationTokens,
            $usage->cacheReadTokens,
        );
    }

    public function generate(
        string  $prompt,
        string  $modelType = 'haiku',
        string  $system = '',
        ?RequestContext $context = null,
    ): ClaudeResponse {
        $entry     = self::MODEL_CATALOG[$modelType] ?? null;
        $model     = ($entry ?? self::MODEL_CATALOG['haiku'])['id'];
        $maxTokens = $entry['max_tokens'] ?? 2048;

        // Một lượt gọi logic — tối đa MAX_RETRIES dòng sổ cái cùng call_uuid này.
        $callUuid   = (string) Str::uuid();
        $promptHash = hash('sha256', $system . "\n" . $prompt);

        $previousRequestId = null;
        $retryReason       = null;

        $requestBody = [
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ];
        if (($entry['effort'] ?? null) !== null) {
            $requestBody['output_config'] = ['effort' => $entry['effort']];
        }
        if (($entry['thinking'] ?? null) !== null) {
            $requestBody['thinking'] = ['type' => $entry['thinking']];
        }
        if ($system !== '') {
            $requestBody['system'] = $system;
        }
        $encodedBody = json_encode($requestBody, JSON_UNESCAPED_UNICODE);
        if ($encodedBody === false) {
            // Fallback: strip invalid UTF-8 rồi encode lại
            array_walk_recursive($requestBody, function (&$v) {
                if (is_string($v)) $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
            });
            $encodedBody = json_encode($requestBody, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        $lastError = '';

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $this->waitForRpmSlot();       // block nếu đạt RPM_LIMIT
            $this->acquireConcurrent();    // block nếu đạt MAX_CONCURRENT

            $requestId = null;

            $ch = curl_init('https://api.anthropic.com/v1/messages');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::TIMEOUT_S,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => [
                    'x-api-key: '         . config('services.claude.key'),
                    'anthropic-version: ' . config('services.claude.version', '2023-06-01'),
                    'content-type: application/json',
                ],
                CURLOPT_POSTFIELDS     => $encodedBody,
                CURLOPT_HEADERFUNCTION => function ($ch, string $header) use (&$requestId): int {
                    if (stripos($header, 'request-id:') === 0) {
                        $requestId = trim(substr($header, strlen('request-id:')));
                    }

                    // Bắt buộc trả về số byte đã đọc, nếu không cURL coi là lỗi ghi.
                    return strlen($header);
                },
            ]);

            $startedAt  = new \DateTimeImmutable();
            $startedMs  = microtime(true);

            $body       = curl_exec($ch);
            $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError  = curl_error($ch);
            $curlErrno  = curl_errno($ch);

            $latencyMs  = (int) round((microtime(true) - $startedMs) * 1000);
            $finishedAt = new \DateTimeImmutable();

            $this->releaseConcurrent();    // giải phóng slot ngay sau HTTP call

            $httpStatus = $httpStatus > 0 ? (int) $httpStatus : null;

            $json      = is_string($body) ? json_decode($body, true) : null;
            $usageJson = is_array($json) ? ($json['usage'] ?? null) : null;

            $attemptUsage   = null;
            $attemptText    = null;
            $attemptError   = $curlError ?: null;

            if ($httpStatus === 200 && is_array($json)) {
                $attemptText = '';
                foreach ($json['content'] ?? [] as $block) {
                    if (is_array($block) && ($block['type'] ?? null) === 'text') {
                        $attemptText .= $block['text'] ?? '';
                    }
                }

                $attemptUsage = TokenUsage::fromResponse(
                    $usageJson ?? [],
                    model:     $model,
                    modelType: $modelType,
                    requestId: $requestId,
                );

                if ($attemptText === '' && $attemptUsage->outputTokens > 0) {
                    Log::error('Claude 200 nhung khong doc duoc text', [
                        'model'         => $model,
                        'stop_reason'   => $json['stop_reason'] ?? null,
                        'block_types'   => array_column($json['content'] ?? [], 'type'),
                        'output_tokens' => $attemptUsage->outputTokens,
                    ]);
                }
            } elseif ($attemptError === null) {
                $attemptError = is_array($json) ? ($json['error']['message'] ?? $body) : $body;
            }

            $this->ledger->record(
                callUuid:        $callUuid,
                attempt:         $attempt,
                model:           $model,
                modelType:       $modelType,
                pricingVersion:  self::PRICING_VERSION,
                billed:          Billing::fromHttpStatus($httpStatus),
                startedAt:       $startedAt,
                finishedAt:      $finishedAt,
                latencyMs:       $latencyMs,
                httpStatus:      $httpStatus,
                requestId:          $requestId,
                previousRequestId:  $previousRequestId,
                context:            $context,
                usage:              $attemptUsage,
                usageJson:       is_array($usageJson) ? $usageJson : null,
                promptHash:      $promptHash,
                responseHash:    $attemptText === null ? null : hash('sha256', $attemptText),
                retryReason:     $retryReason,
                error:           $attemptError,
            );

            // Lượt sau sẽ tham chiếu ngược về lượt này.
            $previousRequestId = $requestId;
            $retryReason     = $curlError ? 'network' : ($httpStatus === null ? 'timeout' : 'http_' . $httpStatus);

            if ($curlError ?? false) {
                $lastError = "cURL error: {$curlError}";
                Log::warning("Claude exception (attempt {$attempt}/" . self::MAX_RETRIES . "): {$lastError}");

                if ($curlErrno === CURLE_OPERATION_TIMEDOUT && $attempt >= self::MAX_TIMEOUT_ATTEMPTS) {
                    Log::error('Claude timeout — dung retry, moi lan timeout van bi tinh tien', [
                        'model'    => $model,
                        'attempts' => $attempt,
                    ]);
                    break;
                }
            } else {
                if ($httpStatus === 200) {
                    // Đã parse ở trên để ghi sổ — dùng lại, không parse hai lần.
                    $stopReason = $json['stop_reason'] ?? '';
                    $text       = $attemptText ?? '';
                    $usage      = $attemptUsage ?? TokenUsage::none($model, $modelType);

                    if ($stopReason === 'max_tokens') {
                        Log::warning('Claude output truncated at max_tokens — returning partial', [
                            'model'  => $model,
                            'tokens' => $usage->outputTokens,
                        ]);
                    } else {
                        Log::debug('Claude OK', [
                            'model'         => $model,
                            'attempt'       => $attempt,
                            'stop_reason'   => $stopReason,
                            'input_tokens'  => $usage->inputTokens,
                            'output_tokens' => $usage->outputTokens,
                            'cache_write'   => $usage->cacheCreationTokens,
                            'cache_read'    => $usage->cacheReadTokens,
                            'request_id'    => $usage->requestId,
                            'cost_usd'      => $usage->costUsd(),
                            'chars'         => strlen($text),
                        ]);
                    }

                    return new ClaudeResponse($text, $usage, $stopReason);
                }

                if ($httpStatus === 400) {
                    // 400 không bị tính tiền — usage rỗng là đúng, không phải mất dữ liệu.
                    Log::error('Claude 400 Bad Request', ['body' => $body, 'request_id' => $requestId]);
                    return new ClaudeResponse('', TokenUsage::none($model, $modelType));
                }

                $lastError = "HTTP {$httpStatus}: " . ($json['error']['message'] ?? $body);
                Log::warning("Claude {$httpStatus} (attempt {$attempt}/" . self::MAX_RETRIES . "): {$lastError}");
            }

            if ($attempt < self::MAX_RETRIES) {
                $is529    = str_contains($lastError, '529');
                $jitter   = random_int(1, 5); // tránh thundering herd khi nhiều worker retry cùng lúc
                $delaySec = $is529
                    ? self::DELAY_529_S * $attempt + $jitter        // ~31-35s, ~61-65s, ...
                    : self::BASE_DELAY_S * (2 ** ($attempt - 1)) + $jitter; // ~4-8s, ~7-11s, ...
                Log::info("Claude retry in {$delaySec}s...");
                sleep($delaySec);
            }
        }

        Log::error('Claude failed after ' . self::MAX_RETRIES . ' attempts', [
            'model'      => $model,
            'last_error' => $lastError,
            'request_id' => $requestId ?? null,
        ]);

        return new ClaudeResponse('', TokenUsage::none($model, $modelType));
    }

    /**
     * @param  ?float  $deadline  microtime(true) after which waiting stops; null waits as generate() does
     * @return bool false when the deadline came first: no slot is held and no request may be sent
     */
    public function acquireSlot(?float $deadline = null): bool
    {
        if (! $this->waitForRpmSlot($deadline, $minuteKey)) {
            return false;
        }

        if (! $this->acquireConcurrent($deadline)) {
            Cache::decrement((string) $minuteKey);

            return false;
        }

        return true;
    }

    /**
     * @param  float  $deadline  microtime(true) after which waiting stops
     * @return ?string the minute counter the request was counted in, for releaseRequestSlot(); null when the deadline came first
     */
    public function acquireRequestSlot(float $deadline): ?string
    {
        return $this->waitForRpmSlot($deadline, $taken) ? $taken : null;
    }

    /** @param string $minuteKey what acquireRequestSlot() returned for a request that was never sent */
    public function releaseRequestSlot(string $minuteKey): void
    {
        Cache::decrement($minuteKey);
    }

    private static function pause(float $seconds, ?float $deadline): void
    {
        $seconds = $deadline === null ? $seconds : min($seconds, $deadline - microtime(true));

        if ($seconds > 0) {
            usleep((int) ($seconds * 1_000_000));
        }
    }

    private static function expired(?float $deadline): bool
    {
        return $deadline !== null && microtime(true) >= $deadline;
    }

    public function releaseSlot(): void
    {
        $this->releaseConcurrent();
    }

    // RPM throttle: cho phép song song nhưng giới hạn tổng request/phút
    /** @param ?string $taken the minute counter the slot was taken from */
    private function waitForRpmSlot(?float $deadline = null, ?string &$taken = null): bool
    {
        while (true) {
            if (self::expired($deadline)) {
                return false;
            }

            $minuteKey = 'claude_rpm_'.(int) (time() / 60);
            Cache::add($minuteKey, 0, 65);
            $count = Cache::increment($minuteKey);

            if ($count <= self::RPM_LIMIT) {
                // Stagger nhẹ khi load cao để tránh burst đồng thời
                if ($count > (int) (self::RPM_LIMIT * 0.6)) {
                    self::pause(random_int(100, 600) / 1000, $deadline); // 100–600ms
                }

                if (self::expired($deadline)) {
                    Cache::decrement($minuteKey);

                    return false;
                }

                $taken = $minuteKey;

                return true;
            }

            // Vượt giới hạn — trả lại slot và chờ sang phút mới
            Cache::decrement($minuteKey);
            $waitSec = max(1, 61 - (time() % 60));
            Log::info("Claude RPM limit ({$count}/".self::RPM_LIMIT."), waiting {$waitSec}s");

            if ($deadline !== null && microtime(true) + $waitSec >= $deadline) {
                self::pause($waitSec, $deadline);

                return false;
            }

            sleep($waitSec);
        }
    }

    // Concurrency semaphore: giới hạn số request đang bay cùng lúc
    private function acquireConcurrent(?float $deadline = null): bool
    {
        $key = 'claude_concurrent';
        $waited = 0;

        while (true) {
            if (self::expired($deadline)) {
                return false;
            }

            Cache::add($key, 0, 120);
            $current = Cache::increment($key);

            if ($current <= self::MAX_CONCURRENT) {
                return true;
            }

            Cache::decrement($key);

            if ($deadline !== null && microtime(true) + 0.3 >= $deadline) {
                self::pause(0.3, $deadline);

                return false;
            }

            usleep(300_000); // chờ 300ms rồi thử lại
            $waited += 300;

            if ($waited >= 60_000) { // tối đa 60s chờ slot
                Log::warning('Claude concurrent slot timeout — proceeding anyway');
                Cache::increment($key); // lấy slot dù vượt ngưỡng

                return true;
            }
        }
    }

    private function releaseConcurrent(): void
    {
        $current = Cache::get('claude_concurrent', 0);
        if ($current > 0) {
            Cache::decrement('claude_concurrent');
        }
    }
}
