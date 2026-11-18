<?php

declare(strict_types=1);

namespace App\Video\Moto;

use RuntimeException;

/**
 * What each rule of the moto checkers decides, once an AI answer has passed its JSON structure.
 *
 * RULE: the answer belongs to another run, project or request — it stays a RULE failure.
 * USE: an id the next step looks up, or a condition the code that derives, plans or renders from the
 * answer needs — the answer passes RULE, and the condition is carried as a requirement: the run is
 * not usable (approved, chosen, or read by the next step) until it holds, and the next step refuses
 * before any paid call.
 * NOTE: a judgement of the content (coverage, wording, plausibility, order of a story) — a note
 * shown to the reviewer (kept apart from the warnings a phase already had), never a failure.
 *
 * The decision is made by the checker at the site of each rule, with a stable code (see Finding), and
 * returned beside the messages as issue_kinds and issue_codes. Nothing here reads a message: what an
 * answer writes into one (an id, a quote) can never change the decision. A message no rule site
 * decided keeps the old behaviour (a RULE failure) and is listed under unclassified, which
 * MotoCheckRulesTest holds empty. docs/moto-check-rules.md lists every rule code and its decision.
 */
final class MotoCheckRules
{
    public const VERSION = 'moto-check-rules-v2';

    public const RULE = 'rule';

    public const USE = 'use';

    public const NOTE = 'note';

    /**
     * Sorts the findings of a check that ran its rules by the decision the checker returned for each:
     * RULE stays an issue, USE becomes a requirement, NOTE a note; the verdict is decided again by the
     * issues left. A check that did not run its rules (unscored, a schema failure, an error such as a
     * model status fail) keeps its issues as they are. A check sorted once is returned unchanged.
     *
     * @param  array<string, mixed>  $check
     * @return array<string, mixed>
     */
    public static function apply(array $check): array
    {
        if (array_key_exists('rules', $check)) {
            return $check;
        }

        $issues = array_values(array_map('strval', (array) ($check['issues'] ?? [])));
        $kinds = array_values((array) ($check['issue_kinds'] ?? []));
        $codes = array_values((array) ($check['issue_codes'] ?? []));
        // Kinds that do not line up with the messages decide nothing: every message stays a RULE failure.
        $aligned = count($kinds) === count($issues);
        $unclassified = array_values(array_map('strval', (array) ($check['unclassified'] ?? [])));
        $ran = in_array($check['verdict'] ?? null, ['PASS', 'FAIL'], true) && ($check['schema'] ?? []) === [] && ($check['error'] ?? null) === null;
        unset($check['issue_kinds'], $check['issue_codes']);

        if (! $ran || $issues === []) {
            return ['unclassified' => $unclassified] + $check + ['requirements' => [], 'notes' => [], 'codes' => self::codes([], [], []), 'rules' => self::VERSION];
        }

        $sorted = [self::RULE => [], self::USE => [], self::NOTE => []];
        $sortedCodes = [self::RULE => [], self::USE => [], self::NOTE => []];

        foreach ($issues as $index => $issue) {
            $kind = $aligned && isset($sorted[$kinds[$index]]) ? $kinds[$index] : self::RULE;
            $sorted[$kind][] = $issue;
            $sortedCodes[$kind][] = (string) ($codes[$index] ?? '');

            if (! $aligned && ! in_array($issue, $unclassified, true)) {
                $unclassified[] = $issue;
            }
        }

        return [
            'verdict' => $sorted[self::RULE] === [] ? 'PASS' : 'FAIL',
            'issues' => $sorted[self::RULE],
            'requirements' => $sorted[self::USE],
            // Notes are kept apart from the warnings a phase already had: some phases hold a run with warnings back, and a judgement of the content never does.
            'notes' => $sorted[self::NOTE],
            'codes' => self::codes($sortedCodes[self::RULE], $sortedCodes[self::USE], $sortedCodes[self::NOTE]),
            'unclassified' => $unclassified,
            'rules' => self::VERSION,
        ] + $check;
    }

    /** @param  array<string, mixed>  $check  whether a run may be approved, chosen or read by the next step: it passed RULE and every requirement holds */
    public static function usable(array $check): bool
    {
        return ($check['verdict'] ?? null) === 'PASS' && (array) ($check['requirements'] ?? []) === [];
    }

    /**
     * @param  array<string, mixed>  $check  the check of the run the next step reads
     *
     * @throws RuntimeException naming every requirement left, before the next step makes any paid call
     */
    public static function requireUsable(array $check, string $what): void
    {
        $requirements = array_values((array) ($check['requirements'] ?? []));

        if ($requirements !== []) {
            throw new RuntimeException("{$what} còn điều kiện chưa đạt để dùng cho bước sau: ".implode('; ', $requirements));
        }
    }

    /**
     * @param  list<string>  $issues
     * @param  list<string>  $requirements
     * @param  list<string>  $notes
     * @return array{issues: list<string>, requirements: list<string>, notes: list<string>} the rule code of each message, in the order of its list
     */
    private static function codes(array $issues, array $requirements, array $notes): array
    {
        return ['issues' => $issues, 'requirements' => $requirements, 'notes' => $notes];
    }
}
