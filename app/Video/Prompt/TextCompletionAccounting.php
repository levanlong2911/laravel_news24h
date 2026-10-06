<?php

declare(strict_types=1);

namespace App\Video\Prompt;

final class TextCompletionAccounting
{
    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $rates
     * @return array{0: array{provider_model: string, tokens_in: int, tokens_out: int, thinking_tokens: mixed, cost_usd: float}, 1: array{pricing: string, pricing_version: ?string}}
     */
    public static function measure(array $raw, string $model, int $input, int $output, array $rates): array
    {
        $cached = $raw['prompt_tokens_details']['cached_tokens'] ?? null;
        $priced = ($rates['model'] ?? null) === $model
            && is_string($rates['version'] ?? null)
            && is_int($raw['prompt_tokens'] ?? null) && $raw['prompt_tokens'] === $input
            && is_int($raw['completion_tokens'] ?? null) && $raw['completion_tokens'] === $output
            && is_int($cached) && $cached >= 0 && $cached <= $input && $output >= 0;
        foreach (['input_per_million', 'cached_input_per_million', 'output_per_million'] as $key) {
            $priced = $priced && is_numeric($rates[$key] ?? null) && $rates[$key] >= 0;
        }
        $cost = $priced ? round((($input - $cached) * $rates['input_per_million'] + $cached * $rates['cached_input_per_million'] + $output * $rates['output_per_million']) / 1000000, 6) : 0.0;
        return [[
            'provider_model' => $model, 'tokens_in' => $input, 'tokens_out' => $output,
            'thinking_tokens' => $raw['completion_tokens_details']['reasoning_tokens'] ?? null,
            'cost_usd' => $cost,
        ], ['pricing' => $priced ? 'estimated' : 'unpriced', 'pricing_version' => $priced ? $rates['version'] : null]];
    }
}
