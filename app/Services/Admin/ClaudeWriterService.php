<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Log;

class ClaudeWriterService
{
    private const MODELS = [
        'haiku'  => 'claude-haiku-4-5-20251001',
        'sonnet' => 'claude-sonnet-4-6',
    ];

    private const MAX_TOKENS = [
        'haiku'  => 2048,
        'sonnet' => 4096,
    ];

    // Retry config: tối đa 5 lần, delay tăng dần (exponential backoff)
    // 529 = overloaded → chờ lâu hơn; 500/529 → retry; 400 → không retry (bad request)
    private const MAX_RETRIES  = 5;
    private const BASE_DELAY_S = 5; // giây, nhân đôi mỗi lần: 5 → 10 → 20 → 40 → 80

    public function generate(string $prompt, string $modelType = 'haiku'): string
    {
        $model     = self::MODELS[$modelType]     ?? self::MODELS['haiku'];
        $maxTokens = self::MAX_TOKENS[$modelType] ?? 2048;

        $lastError = '';

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $ch = curl_init('https://api.anthropic.com/v1/messages');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 120,
                    CURLOPT_POST           => true,
                    CURLOPT_HTTPHEADER     => [
                        'x-api-key: '         . config('services.claude.key'),
                        'anthropic-version: ' . config('services.claude.version', '2023-06-01'),
                        'content-type: application/json',
                    ],
                    CURLOPT_POSTFIELDS => json_encode([
                        'model'      => $model,
                        'max_tokens' => $maxTokens,
                        'messages'   => [['role' => 'user', 'content' => $prompt]],
                    ]),
                ]);

                $body       = curl_exec($ch);
                $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError  = curl_error($ch);
                curl_close($ch);

                if ($curlError) {
                    throw new \RuntimeException("cURL error: {$curlError}");
                }

                $json = json_decode($body, true);

                // ── Xử lý từng HTTP status ────────────────────────────────────
                if ($httpStatus === 200) {
                    $text = $json['content'][0]['text'] ?? '';
                    Log::debug('Claude OK', [
                        'model'   => $model,
                        'attempt' => $attempt,
                        'tokens'  => $json['usage']['output_tokens'] ?? 0,
                        'chars'   => strlen($text),
                    ]);
                    return $text;
                }

                // 400 Bad Request → không retry (lỗi prompt, không có ích)
                if ($httpStatus === 400) {
                    Log::error('Claude 400 Bad Request', ['body' => $body]);
                    return '';
                }

                // 529 Overloaded hoặc 500 Server Error → retry với backoff
                $lastError = "HTTP {$httpStatus}: " . ($json['error']['message'] ?? $body);
                Log::warning("Claude {$httpStatus} (attempt {$attempt}/" . self::MAX_RETRIES . "): {$lastError}");

            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                Log::warning("Claude exception (attempt {$attempt}/" . self::MAX_RETRIES . "): {$lastError}");
            }

            // Exponential backoff: 5s, 10s, 20s, 40s, 80s
            if ($attempt < self::MAX_RETRIES) {
                $delaySec = self::BASE_DELAY_S * (2 ** ($attempt - 1));
                Log::info("Claude retry in {$delaySec}s...");
                sleep($delaySec);
            }
        }

        Log::error('Claude failed after ' . self::MAX_RETRIES . ' attempts', [
            'model'      => $model,
            'last_error' => $lastError,
        ]);

        return '';
    }
}
