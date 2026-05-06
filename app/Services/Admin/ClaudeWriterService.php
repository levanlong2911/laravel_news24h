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
        'sonnet' => 8000,
    ];

    private const MAX_RETRIES  = 5;
    private const BASE_DELAY_S = 5;

    public function generate(string $prompt, string $modelType = 'haiku', string $system = ''): string
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
                    CURLOPT_POSTFIELDS => $encodedBody,
                ]);

                $body       = curl_exec($ch);
                $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError  = curl_error($ch);

                if ($curlError) {
                    throw new \RuntimeException("cURL error: {$curlError}");
                }

                $json = json_decode($body, true);

                if ($httpStatus === 200) {
                    $stopReason = $json['stop_reason'] ?? '';
                    $text       = $json['content'][0]['text'] ?? '';

                    if ($stopReason === 'max_tokens') {
                        Log::warning('Claude output truncated at max_tokens', [
                            'model'  => $model,
                            'tokens' => $json['usage']['output_tokens'] ?? 0,
                        ]);
                        // Retry cùng prompt vẫn bị truncate — throw ngay để caller xử lý
                        throw new \RuntimeException(
                            "Claude output truncated (max_tokens={$maxTokens}). Reduce prompt length or output fields."
                        );
                    }

                    Log::debug('Claude OK', [
                        'model'       => $model,
                        'attempt'     => $attempt,
                        'stop_reason' => $stopReason,
                        'tokens'      => $json['usage']['output_tokens'] ?? 0,
                        'chars'       => strlen($text),
                    ]);

                    return $text;
                }

                // 400 Bad Request → không retry
                if ($httpStatus === 400) {
                    Log::error('Claude 400 Bad Request', ['body' => $body]);
                    return '';
                }

                // 500/529 → retry với backoff
                $lastError = "HTTP {$httpStatus}: " . ($json['error']['message'] ?? $body);
                Log::warning("Claude {$httpStatus} (attempt {$attempt}/" . self::MAX_RETRIES . "): {$lastError}");

            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                Log::warning("Claude exception (attempt {$attempt}/" . self::MAX_RETRIES . "): {$lastError}");
            }

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
