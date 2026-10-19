<?php

declare(strict_types=1);

namespace App\Video\Scene;

final class StoryboardPlanCheck
{
    public const VERSION = 'storyboard-check-v1';

    public const ERROR = 'error';

    public const WARNING = 'warning';

    public const OPEN = 'open';

    public const ACCEPTED = 'accepted';

    public const DURATION_BUDGET = 'duration_budget';

    public const DURATION_CONFLICT = 'duration_conflict';

    public const FILM_BUDGET = 'film_budget';

    public const TIME_JUMP_IN_SHOT = 'time_jump_in_shot';

    public const HEURISTIC = 'heuristic';

    /**
     * @param  list<array<string, mixed>>  $shots  the validated shots, in film order
     * @param  list<array<string, mixed>>  $sources  the screenplay scenes
     * @param  array<string, string>  $conflicts  scene id => the time conflict the model declared
     * @param  int|null  $filmTotalMs  the length of the finished film; null when the storyboard is not held to one
     * @param  list<string>  $warnings  heuristic warnings, each "where: text"
     * @return array{timeline: array<string, array{order: int, start_ms: int, end_ms: int}>,
     *               findings: list<array{id: string, severity: string, rule: string, scene_id: ?string, shot_code: ?string, evidence: string}>}
     */
    public static function run(array $shots, array $sources, array $conflicts, ?int $filmTotalMs, array $warnings): array
    {
        $scenes = [];

        foreach ($sources as $source) {
            if (is_array($source) && is_string($source['id'] ?? null)) {
                $scenes[$source['id']] = $source;
            }
        }

        $timeline = [];
        $used = [];
        $cursor = 0;
        $findings = [];

        foreach ($shots as $order => $shot) {
            $sceneId = (string) $shot['screenplay_scene_code'];
            $length = (int) ($shot['video']['used_ms'] ?? 0);
            $timeline[(string) $shot['scene_code']] = ['order' => $order + 1, 'start_ms' => $cursor, 'end_ms' => $cursor + $length];
            $cursor += $length;
            $used[$sceneId] = ($used[$sceneId] ?? 0) + $length;
            $findings = [...$findings, ...self::timeJumps($shot, $scenes[$sceneId] ?? [])];
        }

        foreach ($scenes as $sceneId => $scene) {
            $budget = $scene['duration_estimate_ms'] ?? null;
            $spent = $used[$sceneId] ?? 0;

            if (is_string($conflicts[$sceneId] ?? null)) {
                $findings[] = self::finding(self::ERROR, self::DURATION_CONFLICT, $sceneId, null, trim($conflicts[$sceneId])
                    .(is_int($budget) ? ' (approved scene length '.$budget.' ms; the shots use '.$spent.' ms)' : ''));
            } elseif (is_int($budget) && $budget > 0 && $spent !== $budget) {
                $findings[] = self::finding(self::ERROR, self::DURATION_BUDGET, $sceneId, null,
                    'The shots use '.$spent.' ms of the edit; the approved scene runs '.$budget.' ms.');
            }
        }

        if ($filmTotalMs !== null && $filmTotalMs > 0 && $cursor !== $filmTotalMs) {
            $findings[] = self::finding(self::ERROR, self::FILM_BUDGET, null, null,
                'The shots use '.$cursor.' ms of the edit; the finished film runs '.$filmTotalMs.' ms.');
        }

        foreach ($warnings as $warning) {
            [$sceneId, $shotCode] = self::placeOf($warning);
            $findings[] = self::finding(self::WARNING, self::HEURISTIC, $sceneId, $shotCode, $warning);
        }

        return ['timeline' => $timeline, 'findings' => $findings];
    }

    /**
     * @param  mixed  $findings  findings a stage recorded
     * @param  mixed  $decisions  finding id => decision a stage recorded
     * @return list<array<string, mixed>> each finding with its status and the reason it was accepted
     */
    public static function withStatus(mixed $findings, mixed $decisions): array
    {
        $decisions = is_array($decisions) ? $decisions : [];
        $out = [];

        foreach (is_array($findings) ? $findings : [] as $finding) {
            if (! is_array($finding) || ! is_string($finding['id'] ?? null)) {
                continue;
            }

            $decision = is_array($decisions[$finding['id']] ?? null) ? $decisions[$finding['id']] : null;
            $accepted = ($finding['severity'] ?? null) === self::WARNING && ($decision['status'] ?? null) === self::ACCEPTED;
            $out[] = $finding + [
                'status' => $accepted ? self::ACCEPTED : self::OPEN,
                'reason' => $accepted ? (string) ($decision['reason'] ?? '') : null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $shot
     * @param  array<string, mixed>  $scene
     * @return list<array<string, mixed>>
     */
    private static function timeJumps(array $shot, array $scene): array
    {
        $jumps = [];

        foreach ((array) ($scene['beats'] ?? []) as $beat) {
            if (is_array($beat) && is_string($beat['id'] ?? null) && is_string($beat['time_jump'] ?? null) && trim($beat['time_jump']) !== '') {
                $jumps[$beat['id']] = trim($beat['time_jump']);
            }
        }

        $findings = [];

        foreach (array_slice((array) ($shot['beat_coverage'] ?? []), 1) as $item) {
            $beatId = is_array($item) ? (string) ($item['beat_id'] ?? '') : '';

            if (isset($jumps[$beatId]) && in_array($item['part'] ?? null, ['whole', 'opening'], true)) {
                $findings[] = self::finding(self::ERROR, self::TIME_JUMP_IN_SHOT, (string) $shot['screenplay_scene_code'], (string) $shot['scene_code'],
                    $beatId.' starts after a time jump ("'.$jumps[$beatId].'") inside one continuous clip.');
            }
        }

        return $findings;
    }

    /** @return array{0: ?string, 1: ?string} [$sceneId, $shotCode] */
    private static function placeOf(string $warning): array
    {
        if (preg_match('/^(sc_[a-z0-9_]+?)(?:\.(s\d{2}))?:/', $warning, $match) !== 1) {
            return [null, null];
        }

        return [$match[1], isset($match[2]) && $match[2] !== '' ? $match[1].'_'.$match[2] : null];
    }

    /** @return array{id: string, severity: string, rule: string, scene_id: ?string, shot_code: ?string, evidence: string} */
    private static function finding(string $severity, string $rule, ?string $sceneId, ?string $shotCode, string $evidence): array
    {
        return [
            'id' => substr(hash('sha256', implode('|', [$rule, $sceneId ?? '', $shotCode ?? '', $evidence])), 0, 16),
            'severity' => $severity,
            'rule' => $rule,
            'scene_id' => $sceneId,
            'shot_code' => $shotCode,
            'evidence' => $evidence,
        ];
    }
}
