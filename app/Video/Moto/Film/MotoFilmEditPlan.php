<?php

declare(strict_types=1);

namespace App\Video\Moto\Film;

use App\Video\Superyacht\Support\CanonicalJson;

/**
 * The edit plan of a film: the decisions it is cut from, as a document of its own — what the film is cut for, its segments in
 * order with the take, the interval and the transition of each, the shots it lacks, and its sound, colour and text tracks.
 *
 * Pure: no database, no file, no provider. The default plan is written here from the approved sources and holds nothing that
 * changes between two readings of the same sources — no time, no random id — so the same sources give the same plan and the
 * same hash. Who wrote a plan and who confirmed it are recorded by the caller beside the plan, never inside it.
 *
 * Only ids, hashes, intervals and the values the policies name are compared here; no wording is read for its meaning.
 */
final class MotoFilmEditPlan
{
    public const VERSION = 'moto-film-edit-plan-builder-v1';

    public const FORMAT = 'moto-film-edit-plan-v1';

    public const SCHEMA = 'MotoFilmEditPlan@1.0.0';

    public const POLICY_FORMAT = 'moto-film-edit-policy-v1';

    /** The plan code makes from the approved sources. */
    public const ORIGIN_DEFAULT = 'default';

    /** A plan a person changed. Named so the server can record it; the first edit policy takes none. */
    public const ORIGIN_MANUAL = 'manual';

    /** A plan a model proposed. Named so the server can record it; the first edit policy takes none. */
    public const ORIGIN_AI = 'ai';

    /** @var list<string> */
    public const ORIGINS = [self::ORIGIN_DEFAULT, self::ORIGIN_MANUAL, self::ORIGIN_AI];

    /**
     * @param  string  $purpose  MotoFilmScope::PREVIEW or MotoFilmScope::FINAL
     * @param  array<string, mixed>  $source  MotoFilmSourceService::source()
     * @param  array{contract: array<string, mixed>, policy: array<string, mixed>, profile: array<string, mixed>, style: array<string, mixed>}  $film  the phase-11 contract: the film policy, the output profile and the card style, each with its hash
     * @param  array{contract: array<string, mixed>, policy: array<string, mixed>}  $edit  the phase-11-edit contract: the edit policy with its hash
     * @return array<string, mixed> the default plan: the segments in the order of the approved shot plan, each from the clip approved for its shot, keeping the interval and the transition the shot plan names; a shot with no usable clip is listed as missing and decided nothing about
     */
    public static function default(string $projectId, string $purpose, array $source, array $film, array $edit): array
    {
        $rows = array_values((array) $source['shots']['plan']['shots']);
        $manifest = (array) $source['manifest'];
        $entries = array_column((array) $manifest['clips'], null, 'shot_id');
        $sequence = [];
        $placed = [];

        foreach ($rows as $index => $row) {
            $shotId = (string) $row['id'];
            $entry = $entries[$shotId] ?? null;

            if ($entry === null) {
                continue;
            }

            $in = (int) $row['used_start_ms'];
            $kind = $row['transition_in']['kind'] ?? null;
            $segment = self::segmentId($index + 1);
            $sequence[] = [
                'id' => $segment,
                'shot_id' => $shotId,
                'scene_id' => (string) $row['scene'],
                'take' => ['render_id' => (string) $entry['render_id'], 'sha256' => (string) $entry['artifact']['sha256']],
                'source_in_ms' => $in,
                'source_out_ms' => $in + (int) $row['used_duration_ms'],
                // The first segment of the film has no clip before it, whatever the shot plan names for its shot.
                'transition_in' => $sequence === [] || ! is_string($kind) ? null : ['kind' => $kind],
            ];
            $placed[] = ['segment' => $segment, 'in' => (int) $row['tl_in_ms'], 'out' => (int) $row['tl_in_ms'] + (int) $row['used_duration_ms']];
        }

        $scope = MotoFilmScope::of($rows, $manifest, array_column($sequence, 'shot_id'));

        return [
            'format' => self::FORMAT,
            'schema' => self::SCHEMA,
            'project_id' => $projectId,
            'purpose' => $purpose,
            'source' => [
                'shots' => ['planning_stage_id' => (string) $source['shots']['stage_id'], 'revision' => (int) $source['shots']['revision'], 'token' => $source['shots']['token']],
                'clips' => ['format' => (string) $manifest['format'], 'hash' => (string) $manifest['hash'], 'hash_version' => (string) $manifest['hash_version'], 'selection_version' => (int) $manifest['selection_version']],
                'script' => $source['script'],
                'film_policy' => self::policyPin((array) $film['contract']),
                'edit_policy' => self::policyPin((array) $edit['contract']),
                'output_profile' => ['id' => (string) $film['profile']['id'], 'hash' => (string) $film['profile']['hash']],
                'card_style' => ['id' => (string) $film['style']['id'], 'hash' => (string) $film['style']['hash']],
            ],
            'editorial_intent' => ['basis' => 'approved_shot_plan', 'summary' => null],
            'sequence' => $sequence,
            'omissions' => [],
            // What the film lacks is kept as the sources report it: ids, places and causes. How a cause is worded for a person is the screen's, and stays out of what is hashed.
            'scope' => [
                'expected' => $scope['expected'],
                'used' => $scope['used'],
                'missing' => array_map(static fn (array $shot): array => array_diff_key($shot, ['message' => true]), $scope['missing']),
            ],
            'audio_tracks' => [['id' => 'A1', 'kind' => 'clip_audio']],
            'colour' => ['mode' => 'none'],
            'text_tracks' => [['id' => 'T1', 'kind' => 'script_cards', 'cards' => self::cards((array) $source['cards'], $placed)]],
            'asset_requests' => [],
            'review_notes' => [],
        ];
    }

    /** @param array<string, mixed> $plan */
    public static function hash(array $plan): string
    {
        return CanonicalJson::hash(['edit_plan' => $plan]);
    }

    /** @return string the id of the segment of the shot at that place of the approved shot plan, the same in a preview and in a final */
    public static function segmentId(int $order): string
    {
        return sprintf('SEG%03d', $order);
    }

    /**
     * Holds a plan against the sources and the policies in force. Every id must be one the sources have, and every decision one
     * the edit policy allows; a plan is refused with the reason, never changed into another plan.
     *
     * @param  array<string, mixed>  $plan  an edit plan the schema accepts
     * @param  array<string, mixed>  $source  MotoFilmSourceService::source()
     * @param  array{contract: array<string, mixed>, policy: array<string, mixed>, profile: array<string, mixed>, style: array<string, mixed>}  $film
     * @param  array{contract: array<string, mixed>, policy: array<string, mixed>}  $edit
     * @return list<array{code: string, subject: string, message: string}> why the plan cannot be cut; empty when it can
     */
    public static function problems(array $plan, string $projectId, array $source, array $film, array $edit): array
    {
        $problems = [];
        $purpose = $plan['purpose'] ?? null;

        if (($plan['project_id'] ?? null) !== $projectId) {
            $problems[] = self::problem('edit_project', 'project', 'Phương án dựng thuộc dự án khác.');
        }

        if (! in_array($purpose, MotoFilmScope::PURPOSES, true)) {
            return [...$problems, self::problem('edit_purpose', 'purpose', 'Phương án dựng ghi mục đích không hợp lệ: '.json_encode($purpose).'.')];
        }

        $expected = self::default($projectId, $purpose, $source, $film, $edit);
        $changed = array_keys(array_filter((array) $expected['source'], static fn (mixed $pin, string $name): bool => ! self::same($pin, $plan['source'][$name] ?? null), ARRAY_FILTER_USE_BOTH));

        if ($changed !== []) {
            $problems[] = self::problem('edit_source_changed', 'source', 'Phương án dựng được lập từ nguồn khác nguồn đang hiện hành ('.implode(', ', $changed).') — lập lại phương án từ nguồn mới.');
        }

        $rows = [];

        foreach (array_values((array) $source['shots']['plan']['shots']) as $index => $row) {
            $rows[(string) $row['id']] = ['order' => $index + 1] + $row;
        }

        $entries = array_column((array) $source['manifest']['clips'], null, 'shot_id');
        $policy = (array) $edit['policy']['sequence'];
        $allowed = (array) $film['policy']['transitions']['allowed'];
        $seen = [];
        $last = 0;

        foreach (array_values((array) ($plan['sequence'] ?? [])) as $position => $segment) {
            $id = (string) ($segment['id'] ?? '');
            $shotId = (string) ($segment['shot_id'] ?? '');
            $row = $rows[$shotId] ?? null;

            if ($row === null) {
                $problems[] = self::problem('edit_shot_unknown', $id, "Đoạn {$id} dùng shot {$shotId} mà kế hoạch shot đã duyệt không có.");

                continue;
            }

            if (isset($seen[$shotId])) {
                $problems[] = self::problem('edit_shot_duplicate', $id, "Đoạn {$id} dùng lại shot {$shotId} đã có ở đoạn {$seen[$shotId]}.");
            } elseif ($id !== self::segmentId($row['order'])) {
                $problems[] = self::problem('edit_segment_id', $id, "Đoạn {$id} của shot {$shotId} phải mang mã ".self::segmentId($row['order']).' theo vị trí của shot trong kế hoạch shot.');
            }

            if ($policy['order'] === 'approved_plan' && $row['order'] <= $last) {
                $problems[] = self::problem('edit_order_changed', $id, "Đoạn {$id} (shot {$shotId}) đứng sai thứ tự kế hoạch shot đã duyệt — chính sách dựng chưa cho đổi thứ tự.");
            }

            $seen[$shotId] ??= $id;
            $last = max($last, $row['order']);
            $entry = $entries[$shotId] ?? null;
            $take = (array) ($segment['take'] ?? []);

            if ($entry === null || ($take['render_id'] ?? null) !== (string) $entry['render_id'] || ($take['sha256'] ?? null) !== (string) $entry['artifact']['sha256']) {
                $problems[] = self::problem('edit_take_unknown', $id, "Đoạn {$id} dùng take ".json_encode($take['render_id'] ?? null)." không phải clip đã duyệt của shot {$shotId}.");
            } else {
                $in = $segment['source_in_ms'] ?? null;
                $out = $segment['source_out_ms'] ?? null;
                $length = (int) ($entry['measured']['duration_ms'] ?? 0);
                $keptIn = (int) $row['used_start_ms'];
                $keptOut = $keptIn + (int) $row['used_duration_ms'];

                if (! is_int($in) || ! is_int($out) || $in < 0 || $out <= $in || $out > $length) {
                    $problems[] = self::problem('edit_trim_outside_file', $id, "Đoạn {$id} giữ [".json_encode($in).', '.json_encode($out).") ms, ngoài clip dài {$length} ms.");
                } elseif ($policy['trim'] === 'approved_used_interval' && ($in !== $keptIn || $out !== $keptOut)) {
                    $problems[] = self::problem('edit_trim_changed', $id, "Đoạn {$id} giữ [{$in}, {$out}) ms, khác phần [{$keptIn}, {$keptOut}) ms kế hoạch shot đã duyệt giữ — chính sách dựng chưa cho đổi điểm cắt.");
                }
            }

            $kind = is_array($segment['transition_in'] ?? null) ? ($segment['transition_in']['kind'] ?? null) : null;
            $planned = $row['transition_in']['kind'] ?? null;

            if ($position === 0) {
                if ($kind !== null) {
                    $problems[] = self::problem('edit_transition_on_first', $id, "Đoạn đầu phim {$id} có chuyển cảnh vào ".json_encode($kind).' mà không có đoạn nào trước nó.');
                }
            } elseif (! is_string($kind)) {
                $problems[] = self::problem('edit_transition_missing', $id, "Đoạn {$id} không nói chuyển cảnh vào.");
            } elseif (! in_array($kind, $allowed, true)) {
                $problems[] = self::problem('edit_transition_unsupported', $id, "Đoạn {$id} dùng chuyển cảnh {$kind} mà chính sách Phase 11 chưa dựng được (".implode(', ', $allowed).') — không thay bằng cut.');
            } elseif ($policy['transition'] === 'approved_plan' && $kind !== $planned) {
                $problems[] = self::problem('edit_transition_changed', $id, "Đoạn {$id} vào bằng {$kind}, kế hoạch shot đã duyệt ghi ".json_encode($planned).' — chính sách dựng chưa cho đổi chuyển cảnh.');
            }
        }

        if ($policy['omissions'] === false) {
            foreach (array_keys($entries) as $shotId) {
                if (isset($rows[$shotId]) && ! isset($seen[$shotId])) {
                    $problems[] = self::problem('edit_shot_omitted', (string) $shotId, "Shot {$shotId} có clip đã duyệt nhưng phương án dựng không dùng — chính sách dựng chưa cho bỏ shot có chủ ý.");
                }
            }
        }

        if ($problems === [] && ! self::same($expected['scope'], $plan['scope'] ?? null)) {
            $problems[] = self::problem('edit_scope_mismatch', 'scope', 'Danh sách shot thiếu của phương án dựng không khớp nguồn đang hiện hành.');
        }

        if ($purpose === MotoFilmScope::FINAL && $expected['scope']['missing'] !== []) {
            $problems[] = self::problem('edit_final_incomplete', 'scope', 'Phương án dựng final phải có đủ mọi shot của kế hoạch shot đã duyệt; còn thiếu '.implode(', ', array_column($expected['scope']['missing'], 'shot_id')).'.');
        }

        if ($problems === [] && ! self::same($expected['text_tracks'], $plan['text_tracks'] ?? null)) {
            $problems[] = self::problem('edit_cards_changed', 'text_tracks', 'Card chữ của phương án dựng khác card của kịch bản đã ghim — chữ và thời gian card giữ nguyên theo kịch bản.');
        }

        // What is left is everything the edit policy fixes: a plan that passes every check above and still differs from the default decides something this policy does not take.
        if ($problems === [] && ! self::same($expected, $plan)) {
            $differs = array_keys(array_filter($expected, static fn (mixed $value, string $key): bool => ! self::same($value, $plan[$key] ?? null), ARRAY_FILTER_USE_BOTH));
            $problems[] = self::problem('edit_decision_not_allowed', implode(',', $differs) ?: 'plan', 'Phương án dựng quyết định điều chính sách dựng hiện hành chưa nhận ('.(implode(', ', $differs) ?: 'trường ngoài phương án mặc định').').');
        }

        return $problems;
    }

    /**
     * Holds a plan against the film compiled for it: the timeline must cut exactly what the plan decides, segment for segment and
     * card for card. The card spans of the plan are counted from the segments, the cards of the film from the film's own time; the
     * two are worked out apart, so a card moved, lost or split differently by either side shows here.
     *
     * @param  array<string, mixed>  $plan  an edit plan problems() accepts
     * @param  array<string, mixed>  $film  the compiled film plan
     * @return list<array{code: string, subject: string, message: string}>
     */
    public static function compiled(array $plan, array $film): array
    {
        $sequence = array_values((array) $plan['sequence']);
        $clips = array_values((array) $film['clips']);

        if (count($sequence) !== count($clips)) {
            return [self::problem('edit_compile_mismatch', 'sequence', 'Phương án dựng có '.count($sequence).' đoạn, timeline biên dịch có '.count($clips).' clip.')];
        }

        $problems = [];
        $start = [];

        foreach ($sequence as $i => $segment) {
            $clip = $clips[$i];
            $start[(string) $segment['id']] = (int) $clip['timeline_start_ms'];
            $decided = [$segment['shot_id'], $segment['take']['render_id'], $segment['take']['sha256'], $segment['source_in_ms'], $segment['source_out_ms'] - $segment['source_in_ms'], $segment['transition_in']['kind'] ?? null];
            $cut = [$clip['shot_id'], $clip['render_id'], $clip['artifact']['sha256'], $clip['trim_start_ms'], $clip['used_duration_ms'], $clip['transition_in']['kind'] ?? null];

            if ($decided !== $cut) {
                $problems[] = self::problem('edit_compile_mismatch', (string) $segment['id'], "Đoạn {$segment['id']}: timeline biên dịch ".json_encode($cut).' khác quyết định của phương án '.json_encode($decided).'.');
            }
        }

        $shown = [];

        foreach ((array) $film['cards'] as $card) {
            $shown[(string) $card['source_card_id']][] = [(int) $card['start_ms'], (int) $card['end_ms'], (string) $card['text']];
        }

        $decided = [];

        foreach ((array) ($plan['text_tracks'][0]['cards'] ?? []) as $card) {
            $pieces = [];

            foreach ((array) $card['spans'] as $span) {
                $at = $start[(string) $span['segment']] ?? null;

                if ($at === null) {
                    $problems[] = self::problem('edit_compile_mismatch', (string) $card['source_card_id'], "Card {$card['type']} nằm trên đoạn {$span['segment']} mà phương án dựng không có.");

                    continue 2;
                }

                $from = $at + (int) $span['from_ms'];
                $to = $at + (int) $span['to_ms'];
                $last = count($pieces) - 1;

                if ($last >= 0 && $pieces[$last][1] === $from) {
                    $pieces[$last][1] = $to;
                } else {
                    $pieces[] = [$from, $to, (string) $card['text']];
                }
            }

            $decided[(string) $card['source_card_id']] = $pieces;
        }

        ksort($shown);
        ksort($decided);

        if ($shown !== $decided) {
            $problems[] = self::problem('edit_compile_mismatch', 'text_tracks', 'Card trên timeline biên dịch không khớp card của phương án dựng sau khi ánh xạ từ đoạn sang thời gian phim.');
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return list<array{id: string, shot_id: string, scene_id: string, render_id: string, in_ms: int, out_ms: int, transition: ?string}> the segments as the screen lists them
     */
    public static function rows(array $plan): array
    {
        return array_map(static fn (array $segment): array => [
            'id' => (string) $segment['id'],
            'shot_id' => (string) $segment['shot_id'],
            'scene_id' => (string) $segment['scene_id'],
            'render_id' => (string) $segment['take']['render_id'],
            'in_ms' => (int) $segment['source_in_ms'],
            'out_ms' => (int) $segment['source_out_ms'],
            'transition' => $segment['transition_in']['kind'] ?? null,
        ], array_values((array) $plan['sequence']));
    }

    /**
     * @param  list<array<string, mixed>>  $cards  the cards of the pinned script on the time of the shot plan
     * @param  list<array{segment: string, in: int, out: int}>  $placed  the segments, each with where its shot stands in the shot plan
     * @return list<array{source_card_id: string, type: string, text: string, spans: list<array{segment: string, from_ms: int, to_ms: int}>}> every card that falls on a segment, in script order, with its part on each; a card of a shot the film lacks is left out with that shot
     */
    private static function cards(array $cards, array $placed): array
    {
        $track = [];

        foreach ($cards as $card) {
            $start = (int) $card['start_ms'];
            $end = (int) $card['end_ms'];
            $spans = [];

            foreach ($placed as $slot) {
                if ($start < $slot['out'] && $end > $slot['in']) {
                    $spans[] = ['segment' => $slot['segment'], 'from_ms' => max($start, $slot['in']) - $slot['in'], 'to_ms' => min($end, $slot['out']) - $slot['in']];
                }
            }

            if ($spans !== []) {
                $track[] = ['source_card_id' => (string) $card['source_card_id'], 'type' => (string) $card['type'], 'text' => (string) $card['text'], 'spans' => $spans];
            }
        }

        return $track;
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return array{phase_id: string, phase_revision: int, policy_hash: string}
     */
    private static function policyPin(array $contract): array
    {
        return ['phase_id' => (string) $contract['phase_id'], 'phase_revision' => (int) $contract['phase_revision'], 'policy_hash' => (string) $contract['policy_hash']];
    }

    /** @return bool whether two values are the same JSON, whatever the order of their keys */
    private static function same(mixed $a, mixed $b): bool
    {
        return CanonicalJson::encode($a) === CanonicalJson::encode($b);
    }

    /** @return array{code: string, subject: string, message: string} */
    private static function problem(string $code, string $subject, string $message): array
    {
        return ['code' => $code, 'subject' => $subject, 'message' => $message];
    }
}
