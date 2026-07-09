<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ClaudeWriterService
{
    private const MODELS = [
        'haiku'  => 'claude-haiku-4-5-20251001',
        'sonnet' => 'claude-sonnet-4-6',
    ];

    private const MAX_TOKENS = [
        'haiku'  => 6000,
        'sonnet' => 8000,
    ];

    // Pricing per 1M tokens (USD) — update when Anthropic changes rates
    public const PRICE_INPUT = [
        'haiku'  => 0.80,
        'sonnet' => 3.00,
    ];

    public const PRICE_OUTPUT = [
        'haiku'  => 4.00,
        'sonnet' => 15.00,
    ];

    private const MAX_RETRIES    = 5;
    private const BASE_DELAY_S   = 3;   // normal errors: 3s, 6s, 12s...
    private const DELAY_529_S    = 30;  // 529 Overloaded: 30s, 60s, 90s...
    private const RPM_LIMIT      = 40;  // max requests/phút gửi tới Anthropic
    private const MAX_CONCURRENT = 8;   // max đồng thời (parallel workers)

    public static function costUsd(int $inputTokens, int $outputTokens, string $modelType): float
    {
        $priceIn  = self::PRICE_INPUT[$modelType]  ?? self::PRICE_INPUT['sonnet'];
        $priceOut = self::PRICE_OUTPUT[$modelType] ?? self::PRICE_OUTPUT['sonnet'];
        return ($inputTokens * $priceIn + $outputTokens * $priceOut) / 1_000_000;
    }

    public function generate(string $prompt, string $modelType = 'haiku', string $system = ''): ClaudeResponse
    {
        Log::debug('[ClaudeWriterService] prompt', [
            'model'  => $modelType,
            'system' => $system ?: '(none)',
            'prompt' => $prompt,
        ]);

        $model     = self::MODELS[$modelType]     ?? self::MODELS['haiku'];
        $maxTokens = self::MAX_TOKENS[$modelType] ?? 2048;

        $requestBody = [
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ];
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

            $ch = curl_init('https://api.anthropic.com/v1/messages');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 180,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => [
                    'x-api-key: '         . config('services.claude.key'),
                    'anthropic-version: ' . config('services.claude.version', '2023-06-01'),
                    'content-type: application/json',
                ],
                CURLOPT_POSTFIELDS => $encodedBody,
            ]);

            $body       = curl_exec($ch);
            $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError  = curl_error($ch);
            curl_close($ch);

            $this->releaseConcurrent();    // giải phóng slot ngay sau HTTP call

            if ($curlError !== '') {
                $lastError = "cURL error: {$curlError}";
                Log::warning("Claude exception (attempt {$attempt}/" . self::MAX_RETRIES . "): {$lastError}");
            } else {
                $json = json_decode($body, true);

                if ($httpStatus === 200) {
                    $stopReason   = $json['stop_reason'] ?? '';
                    $text         = $json['content'][0]['text'] ?? '';
                    $inputTokens  = $json['usage']['input_tokens']  ?? 0;
                    $outputTokens = $json['usage']['output_tokens'] ?? 0;

                    if ($stopReason === 'max_tokens') {
                        Log::warning('Claude output truncated at max_tokens — returning partial', [
                            'model'  => $model,
                            'tokens' => $outputTokens,
                        ]);
                    } else {
                        Log::debug('Claude OK', [
                            'model'         => $model,
                            'attempt'       => $attempt,
                            'stop_reason'   => $stopReason,
                            'input_tokens'  => $inputTokens,
                            'output_tokens' => $outputTokens,
                            'chars'         => strlen($text),
                        ]);
                    }

                    return new ClaudeResponse($text, $inputTokens, $outputTokens);
                }

                if ($httpStatus === 400) {
                    Log::error('Claude 400 Bad Request', ['body' => $body]);
                    return new ClaudeResponse('', 0, 0);
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
        ]);

        return new ClaudeResponse('', 0, 0);
    }

    // RPM throttle: cho phép song song nhưng giới hạn tổng request/phút
    private function waitForRpmSlot(): void
    {
        while (true) {
            $minuteKey = 'claude_rpm_' . (int) (time() / 60);
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
            Log::info("Claude RPM limit ({$count}/" . self::RPM_LIMIT . "), waiting {$waitSec}s");
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
        // Cache::decrement is atomic; clamp at 0 to prevent negative counter
        // from a prior crash or TOCTOU between get+decrement.
        $result = Cache::decrement('claude_concurrent');
        if ($result !== false && $result < 0) {
            Cache::put('claude_concurrent', 0, 120);
        }
    }
}
