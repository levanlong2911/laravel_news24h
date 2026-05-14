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

    private const MAX_RETRIES  = 3;
    private const BASE_DELAY_S = 3;

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
                curl_close($ch);

                if ($curlError) {
                    throw new \RuntimeException("cURL error: {$curlError}");
                }

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
                        return new ClaudeResponse($text, $inputTokens, $outputTokens);
                    }

                    Log::debug('Claude OK', [
                        'model'        => $model,
                        'attempt'      => $attempt,
                        'stop_reason'  => $stopReason,
                        'input_tokens' => $inputTokens,
                        'output_tokens'=> $outputTokens,
                        'chars'        => strlen($text),
                    ]);

                    return new ClaudeResponse($text, $inputTokens, $outputTokens);
                }

                // 400 Bad Request → không retry
                if ($httpStatus === 400) {
                    Log::error('Claude 400 Bad Request', ['body' => $body]);
                    return new ClaudeResponse('', 0, 0);
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

        return new ClaudeResponse('', 0, 0);
    }
}
