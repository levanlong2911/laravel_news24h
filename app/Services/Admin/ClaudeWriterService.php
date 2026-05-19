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
        'haiku'  => 4096,
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

    private const MAX_RETRIES   = 5;
    private const BASE_DELAY_S  = 3;    // normal errors: 3s, 6s, 12s...
    private const DELAY_529_S   = 30;   // 529 Overloaded: 30s, 60s, 90s...
    private const RPM_LIMIT     = 40;   // max requests/phút gửi tới Anthropic

    public static function costUsd(int $inputTokens, int $outputTokens, string $modelType): float
    {
        $priceIn  = self::PRICE_INPUT[$modelType]  ?? self::PRICE_INPUT['sonnet'];
        $priceOut = self::PRICE_OUTPUT[$modelType] ?? self::PRICE_OUTPUT['sonnet'];
        return ($inputTokens * $priceIn + $outputTokens * $priceOut) / 1_000_000;
    }

    public function generate(string $prompt, string $modelType = 'haiku', string $system = ''): ClaudeResponse
    {
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
        $encodedBody = json_encode($requestBody);

        $lastError = '';

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            // Throttle RPM trước mỗi lần gửi (kể cả retry)
            $this->waitForRpmSlot();

            // Lock serialize concurrent: chỉ 1 request gửi tới Anthropic tại một thời điểm
            $lock = Cache::lock('claude_request_lock', 120);
            $lock->block(120); // đợi tối đa 120s để lấy lock

            try {
                $ch = curl_init('https://api.anthropic.com/v1/messages');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 60,
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

            } finally {
                $lock->release(); // release ngay sau HTTP call, trước khi sleep
            }

            if ($curlError ?? false) {
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
                // 529 Overloaded: dùng delay dài hơn nhiều so với lỗi thường
                $is529    = str_contains($lastError, '529');
                $delaySec = $is529
                    ? self::DELAY_529_S * $attempt           // 30s, 60s, 90s, 120s, 150s
                    : self::BASE_DELAY_S * (2 ** ($attempt - 1)); // 3s, 6s, 12s, 24s
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

    // Throttle: đếm request theo window 60s, block nếu đạt RPM_LIMIT
    private function waitForRpmSlot(): void
    {
        $minuteKey = 'claude_rpm_' . (int) (time() / 60);

        // Cache::add chỉ set khi key chưa tồn tại (atomic, có TTL ngay từ đầu)
        Cache::add($minuteKey, 0, 65);
        $count = Cache::increment($minuteKey);

        if ($count > self::RPM_LIMIT) {
            $waitSec = max(1, 61 - (time() % 60));
            Log::info("Claude RPM limit ({$count}/" . self::RPM_LIMIT . "), waiting {$waitSec}s");
            sleep($waitSec);
        }
    }
}
