<?php

declare(strict_types=1);

namespace App\Video\YachtDesign;

final class BuildStateText
{
    /**
     * @param  array{parts: array<string, string>, surfaces: array<string, string>}  $phrases
     */
    public function __construct(private readonly array $phrases) {}

    public static function fromConfig(): self
    {
        return new self((array) config('video.superyacht.build_state_text'));
    }

    public function isPart(string $key): bool
    {
        return array_key_exists($key, $this->phrases['parts']);
    }

    /**
     * @param  array<mixed>  $state
     * @return array{parts: list<string>, surfaces: list<string>}
     */
    public function unknown(array $state): array
    {
        $surfaces = is_array($state['surfaces_by_part'] ?? null) ? $state['surfaces_by_part'] : [];
        $parts = [
            ...(is_array($state['present_parts'] ?? null) ? $state['present_parts'] : []),
            ...(is_array($state['absent_parts'] ?? null) ? $state['absent_parts'] : []),
            ...array_keys($surfaces),
        ];
        $outside = static fn (array $keys, array $known): array => array_values(array_unique(array_map(
            static fn (mixed $key): string => is_scalar($key) ? (string) $key : gettype($key),
            array_filter($keys, static fn (mixed $key): bool => ! is_string($key) || ! array_key_exists($key, $known)),
        )));

        return ['parts' => $outside($parts, $this->phrases['parts']), 'surfaces' => $outside(array_values($surfaces), $this->phrases['surfaces'])];
    }

    /**
     * @param  array<mixed>  $state
     */
    public function compile(array $state): ?string
    {
        $present = is_array($state['present_parts'] ?? null) ? $state['present_parts'] : [];
        $absent = is_array($state['absent_parts'] ?? null) ? $state['absent_parts'] : [];
        $surfaces = is_array($state['surfaces_by_part'] ?? null) ? $state['surfaces_by_part'] : [];
        $part = fn (mixed $key): ?string => is_string($key) ? ($this->phrases['parts'][$key] ?? null) : null;
        $surface = fn (mixed $key): ?string => is_string($key) ? ($this->phrases['surfaces'][$key] ?? null) : null;

        $presentText = array_map($part, $present);
        $absentText = array_map($part, $absent);
        $surfaceText = [];

        foreach ($surfaces as $key => $value) {
            $surfaceText[] = $part($key) === null || $surface($value) === null ? null : $part($key).' in '.$surface($value);
        }

        if ($presentText === [] || in_array(null, [...$presentText, ...$absentText, ...$surfaceText], true)) {
            return null;
        }

        $segments = [implode(', ', $presentText)];

        if ($absentText !== []) {
            $segments[] = implode(', ', array_map(static fn (string $text): string => 'no '.$text, $absentText));
        }

        if ($surfaceText !== []) {
            $segments[] = implode(', ', $surfaceText);
        }

        return implode('; ', $segments);
    }
}
