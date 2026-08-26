<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ClaudeWriterService
{

    private const MODEL_CATALOG = [
        'haiku' => [
            'id' => 'claude-haiku-4-5-20251001',
            'max_tokens' => 4096,
            'input_usd_per_mtok' => 1.00,
            'output_usd_per_mtok' => 5.00,
        ],
        'sonnet' => [
            'id' => 'claude-sonnet-4-6',
            'max_tokens' => 8000,
            'input_usd_per_mtok' => 3.00,
            'output_usd_per_mtok' => 15.00,
        ],
    ];


    private const CACHE_WRITE_MULTIPLIER = 1.25;

    private const CACHE_READ_MULTIPLIER = 0.10;

    private const MAX_RETRIES = 5;

    private const BASE_DELAY_S = 3;   // normal errors: 3s, 6s, 12s...

    private const DELAY_529_S = 30;  // 529 Overloaded: 30s, 60s, 90s...

    private const RPM_LIMIT = 40;  // max requests/phút gửi tới Anthropic

    private const MAX_CONCURRENT = 8;   // max đồng thời (parallel workers)

    public static function supports(string $modelType): bool
    {
        return isset(self::MODEL_CATALOG[$modelType]);
    }

    /**
     * @return array{id: string, max_tokens: int, input_usd_per_mtok: float, output_usd_per_mtok: float}
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

    private function responseFromBody(array $json): ClaudeResponse
    {
        return new ClaudeResponse(
            $json['content'][0]['text'] ?? '',
            $json['usage']['input_tokens'] ?? 0,
            $json['usage']['output_tokens'] ?? 0,
            $json['stop_reason'] ?? '',
            // Luon 0 khi chua bat cache_control — doc de phong, xem
            // docblock CACHE_WRITE_MULTIPLIER.
            $json['usage']['cache_creation_input_tokens'] ?? 0,
            $json['usage']['cache_read_input_tokens'] ?? 0,
            $json['model'] ?? '',
        );
    }

    /** @return array<string, mixed> */
    private function requestBody(string $prompt, string $model, int $maxTokens, string $system, ?float $temperature): array
    {
        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ];

        // `!== null` chu khong phai truthy: 0.0 la mot temperature hop le.
        if ($temperature !== null) {
            $body['temperature'] = $temperature;
        }

        if ($system !== '') {
            $body['system'] = $system;
        }

        return $body;
    }

    /**
     * @param  int|null  $maxTokens  Trần output cho RIÊNG cú gọi này. null → dùng bảng
     *                               trần mặc định theo model (hành vi cũ, không đổi cho caller phía CMS).
     *

     */
    public function generate(string $prompt, string $modelType = 'haiku', string $system = '', ?int $maxTokens = null, ?float $temperature = null): ClaudeResponse
    {
        $model = self::modelId($modelType);
        $maxTokens ??= self::maxTokensFor($modelType);

        $requestBody = $this->requestBody($prompt, $model, $maxTokens, $system, $temperature);
        $encodedBody = json_encode($requestBody, JSON_UNESCAPED_UNICODE);
        if ($encodedBody === false) {
            // Fallback: strip invalid UTF-8 rồi encode lại
            array_walk_recursive($requestBody, function (&$v) {
                if (is_string($v)) {
                    $v = mb_convert_encoding($v, 'UTF-8', 'UTF-8');
                }
            });
            $encodedBody = json_encode($requestBody, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        $lastError = '';

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $this->waitForRpmSlot();       // block nếu đạt RPM_LIMIT
            $this->acquireConcurrent();    // block nếu đạt MAX_CONCURRENT

            $ch = curl_init('https://api.anthropic.com/v1/messages');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'x-api-key: '.config('services.claude.key'),
                    'anthropic-version: '.config('services.claude.version', '2023-06-01'),
                    'content-type: application/json',
                ],
                CURLOPT_POSTFIELDS => $encodedBody,
            ]);

            $body = curl_exec($ch);
            $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            $this->releaseConcurrent();    // giải phóng slot ngay sau HTTP call

            if ($curlError ?? false) {
                $lastError = "cURL error: {$curlError}";
                Log::warning("Claude exception (attempt {$attempt}/".self::MAX_RETRIES."): {$lastError}");
            } else {
                $json = json_decode($body, true);

                if ($httpStatus === 200) {
                    $response = $this->responseFromBody($json);

                    if ($response->stopReason === 'max_tokens') {
                        // Van TRA VE ban cat do (khong nem o day) — caller CMS
                        // co the dung duoc doan van dang do. Nhung tu 2026-07-30
                        // `stop_reason` di THEO ClaudeResponse, nen caller nao
                        // can nguyen ven (vd trich xuat JSON) tu kiem duoc bang
                        // `wasTruncated()` thay vi doan qua log.
                        Log::warning('Claude output truncated at max_tokens — returning partial', [
                            'model' => $model,
                            'tokens' => $response->outputTokens,
                            'max_tokens' => $maxTokens,
                        ]);
                    } else {
                        Log::debug('Claude OK', [
                            'model' => $model,
                            'provider_model' => $response->providerModel,
                            'attempt' => $attempt,
                            'stop_reason' => $response->stopReason,
                            'input_tokens' => $response->inputTokens,
                            'output_tokens' => $response->outputTokens,
                            'cache_write_tokens' => $response->cacheWriteTokens,
                            'cache_read_tokens' => $response->cacheReadTokens,
                            'cost_usd' => round(self::costUsd($response->inputTokens, $response->outputTokens, $modelType, $response->cacheWriteTokens, $response->cacheReadTokens), 6),
                            'chars' => strlen($response->text),
                        ]);
                    }

                    return $response;
                }

                if ($httpStatus === 400) {
                    Log::error('Claude 400 Bad Request', ['body' => $body]);

                    return new ClaudeResponse('', 0, 0);
                }

                $lastError = "HTTP {$httpStatus}: ".($json['error']['message'] ?? $body);
                Log::warning("Claude {$httpStatus} (attempt {$attempt}/".self::MAX_RETRIES."): {$lastError}");
            }

            if ($attempt < self::MAX_RETRIES) {
                $is529 = str_contains($lastError, '529');
                $jitter = random_int(1, 5); // tránh thundering herd khi nhiều worker retry cùng lúc
                $delaySec = $is529
                    ? self::DELAY_529_S * $attempt + $jitter        // ~31-35s, ~61-65s, ...
                    : self::BASE_DELAY_S * (2 ** ($attempt - 1)) + $jitter; // ~4-8s, ~7-11s, ...
                Log::info("Claude retry in {$delaySec}s...");
                sleep($delaySec);
            }
        }

        Log::error('Claude failed after '.self::MAX_RETRIES.' attempts', [
            'model' => $model,
            'last_error' => $lastError,
        ]);

        return new ClaudeResponse('', 0, 0);
    }

    // RPM throttle: cho phép song song nhưng giới hạn tổng request/phút
    private function waitForRpmSlot(): void
    {
        while (true) {
            $minuteKey = 'claude_rpm_'.(int) (time() / 60);
            Cache::add($minuteKey, 0, 65);
            $count = Cache::increment($minuteKey);

            if ($count <= self::RPM_LIMIT) {
                // Stagger nhẹ khi load cao để tránh burst đồng thời
                if ($count > (int) (self::RPM_LIMIT * 0.6)) {
                    usleep(random_int(100, 600) * 1_000); // 100–600ms
                }

                return;
            }

            // Vượt giới hạn — trả lại slot và chờ sang phút mới
            Cache::decrement($minuteKey);
            $waitSec = max(1, 61 - (time() % 60));
            Log::info("Claude RPM limit ({$count}/".self::RPM_LIMIT."), waiting {$waitSec}s");
            sleep($waitSec);
        }
    }

    // Concurrency semaphore: giới hạn số request đang bay cùng lúc
    private function acquireConcurrent(): void
    {
        $key = 'claude_concurrent';
        $waited = 0;

        while (true) {
            Cache::add($key, 0, 120);
            $current = Cache::increment($key);

            if ($current <= self::MAX_CONCURRENT) {
                return;
            }

            Cache::decrement($key);
            usleep(300_000); // chờ 300ms rồi thử lại
            $waited += 300;

            if ($waited >= 60_000) { // tối đa 60s chờ slot
                Log::warning('Claude concurrent slot timeout — proceeding anyway');
                Cache::increment($key); // lấy slot dù vượt ngưỡng

                return;
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
