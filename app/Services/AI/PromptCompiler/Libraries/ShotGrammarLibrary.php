<?php

namespace App\Services\AI\PromptCompiler\Libraries;

/**
 * Rule-based shot grammar library.
 *
 * Defines: ShotPurpose constants, grammar sequences per information_type,
 * information_density mapping, and shot-count formula weights.
 *
 * Rule 1: no provider names here or anywhere this library is used.
 */
final class ShotGrammarLibrary
{
    public const VERSION = '1.0';

    // ShotPurpose constants — cinematographic intent, not camera hardware
    public const HOOK       = 'HOOK';
    public const ESTABLISH  = 'ESTABLISH';
    public const PROCESS    = 'PROCESS';
    public const DETAIL     = 'DETAIL';
    public const MACRO      = 'MACRO';
    public const TRANSITION = 'TRANSITION';
    public const EMOTION    = 'EMOTION';
    public const PAYOFF     = 'PAYOFF';
    public const CTA        = 'CTA';

    // information_type → information_density (HIGH / MEDIUM / LOW)
    private const TYPE_DENSITY = [
        'PROCESS'     => 'HIGH',
        'DETAIL'      => 'HIGH',
        'FACT'        => 'MEDIUM',
        'COMPARISON'  => 'MEDIUM',
        'EMOTION'     => 'LOW',
        'SPECULATION' => 'LOW',
        'SUMMARY'     => 'LOW',
    ];

    // density → shots per second of beat duration
    private const DENSITY_WEIGHT = [
        'HIGH'   => 1.5,
        'MEDIUM' => 1.0,
        'LOW'    => 0.5,
    ];

    // density → how many Visual Moments Claude should generate per beat
    private const DENSITY_MOMENT_COUNT = [
        'HIGH'   => 4,
        'MEDIUM' => 3,
        'LOW'    => 2,
    ];

    // importance → relative weight for shot distribution
    public const IMPORTANCE_WEIGHT = [
        'HIGH'   => 3,
        'MEDIUM' => 2,
        'LOW'    => 1,
    ];

    // Base ShotPurpose grammar sequences per information_type
    private const SEQUENCES = [
        'PROCESS'     => [self::ESTABLISH, self::PROCESS, self::DETAIL, self::MACRO, self::PROCESS, self::PAYOFF],
        'FACT'        => [self::ESTABLISH, self::DETAIL, self::PAYOFF],
        'COMPARISON'  => [self::ESTABLISH, self::DETAIL, self::TRANSITION, self::DETAIL, self::PAYOFF],
        'EMOTION'     => [self::HOOK, self::EMOTION, self::PAYOFF],
        'DETAIL'      => [self::ESTABLISH, self::DETAIL, self::MACRO, self::DETAIL, self::PAYOFF],
        'SPECULATION' => [self::HOOK, self::ESTABLISH, self::EMOTION, self::CTA],
        'SUMMARY'     => [self::ESTABLISH, self::EMOTION, self::PAYOFF],
    ];

    private const DEFAULT_SEQUENCE = [self::ESTABLISH, self::DETAIL, self::PAYOFF];

    /** Returns ordered ShotPurpose[] for this information_type. */
    public static function sequence(string $informationType): array
    {
        return self::SEQUENCES[strtoupper($informationType)] ?? self::DEFAULT_SEQUENCE;
    }

    /** Derives density category (HIGH/MEDIUM/LOW) from information_type. */
    public static function densityFor(string $informationType): string
    {
        return self::TYPE_DENSITY[strtoupper($informationType)] ?? 'MEDIUM';
    }

    /**
     * Target shot count for a beat using the formula:
     *   target = max(1, round(density_weight × beat_duration))
     * Scales naturally from 15s → 30s → 60s video without code changes.
     */
    public static function targetShots(string $density, float $beatDuration): int
    {
        $weight = self::DENSITY_WEIGHT[strtoupper($density)] ?? 1.0;
        return max(1, (int) round($weight * $beatDuration));
    }

    /** How many Visual Moments Claude should generate for a beat at this density. */
    public static function momentCount(string $density): int
    {
        return self::DENSITY_MOMENT_COUNT[strtoupper($density)] ?? 3;
    }
}
