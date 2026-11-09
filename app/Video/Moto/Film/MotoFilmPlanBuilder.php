<?php

declare(strict_types=1);

namespace App\Video\Moto\Film;

use App\Video\FinalComposition\CompositionDecor;

/**
 * Compiles the film plan from what the plan service read: pure, with no database, no file other than the font, and no provider.
 * One frame timeline [start, end) serves the preview, the manifest FFmpeg runs, and the check of the file; every millisecond on it
 * must fall on a frame of the output or the film is blocked, never rounded. A blocker stops the film; a warning is for a person to read
 * and never changes a source.
 */
final class MotoFilmPlanBuilder
{
    public const VERSION = 'moto-film-plan-builder-v1';

    public const FORMAT = 'moto-film-plan-v1';

    public const SCHEMA = 'MotoFilmPlan@1.1.0';

    /** @var array<string, string> what the film does with each transition the shot plan may name */
    private const EFFECTS = ['cut' => 'straight', 'match_cut' => 'straight', 'time_jump' => 'straight', 'dip' => 'dip'];

    /** @var array<string, string> why a shot has no clip the film can use, as the Phase 10 manifest says it */
    private const MISSING = [
        'not_approved' => 'chưa có clip được duyệt',
        'file_missing' => 'file clip đã duyệt không còn trên đĩa',
        'file_hash_mismatch' => 'file clip đã duyệt đã bị đổi nội dung',
    ];

    /**
     * @param  array{phase_id: string, phase_revision: int, policy_sha256: string, policy_hash: string, bundle_hash: string}  $contract
     * @param  array<string, mixed>  $policy  the phase-11 policy, as MotoFilmPolicy accepts it
     * @param  array<string, mixed>  $profile  the output profile the policy names, with its id and hash
     * @param  array<string, mixed>  $style  the card style the policy names, with its id and hash
     * @param  array<string, mixed>  $source  MotoFilmSourceService::source()
     * @return array{ready: bool, blockers: list<array{code: string, subject: string, message: string}>, warnings: list<array<string, mixed>>, plan: ?array<string, mixed>, rows: list<array<string, mixed>>}
     */
    public static function build(string $projectId, array $contract, array $policy, array $profile, array $style, MotoFilmFont $font, array $source): array
    {
        $fps = (int) $profile['fps'];
        $blockers = $source['blockers'];
        $warnings = [];
        $manifest = $source['manifest'];
        $rows = array_values((array) $source['shots']['plan']['shots']);
        $entries = [];

        foreach ((array) $manifest['missing'] as $shotId) {
            $why = (string) ($manifest['problems'][$shotId] ?? '');
            $blockers[] = self::blocker('clip_missing', $shotId, 'Shot '.$shotId.': '.(self::MISSING[$why] ?? 'clip đã duyệt không còn khớp kế hoạch ('.$why.')').'.');
        }

        foreach ((array) $manifest['clips'] as $entry) {
            if (isset($entries[$entry['shot_id']])) {
                $blockers[] = self::blocker('clip_duplicate', $entry['shot_id'], 'Manifest Phase 10 có hai clip cho shot '.$entry['shot_id'].'.');
            }

            $entries[$entry['shot_id']] = $entry;
        }

        $ids = array_column($rows, 'id');

        foreach (array_diff(array_keys($entries), $ids) as $shotId) {
            $blockers[] = self::blocker('clip_unknown', (string) $shotId, 'Manifest Phase 10 có clip cho shot '.$shotId.' mà kế hoạch shot không có.');
        }

        foreach (array_unique(array_diff_assoc($ids, array_unique($ids))) as $shotId) {
            $blockers[] = self::blocker('shot_duplicate', (string) $shotId, 'Kế hoạch shot có shot '.$shotId.' hai lần.');
        }

        $allowed = (array) $policy['transitions']['allowed'];
        $clips = [];
        $display = [];
        $cursorMs = 0;
        $cursorFrame = 0;

        foreach ($rows as $index => $row) {
            $shotId = (string) $row['id'];
            $trim = (int) $row['used_start_ms'];
            $used = (int) $row['used_duration_ms'];
            $in = (int) $row['tl_in_ms'];
            $entry = $entries[$shotId] ?? null;
            $exact = static function (int $ms, string $what) use (&$blockers, $fps, $shotId): ?int {
                $frame = self::frames($ms, $fps);

                if ($frame === null) {
                    $blockers[] = self::blocker('frame_not_exact', $shotId, "Shot {$shotId}: {$what} {$ms} ms không rơi đúng một khung ở {$fps} fps — không làm tròn.");
                }

                return $frame;
            };

            if ($entry === null && ! in_array($shotId, (array) $manifest['missing'], true)) {
                $blockers[] = self::blocker('clip_missing', $shotId, 'Shot '.$shotId.': manifest Phase 10 không có clip cho shot này.');
            }

            $out = $row['tl_out_ms'] ?? null;

            if ($in !== $cursorMs || $out !== $in + $used) {
                $blockers[] = self::blocker('timeline_mismatch', $shotId, "Shot {$shotId} nằm {$in}–".json_encode($out)." ms trên timeline, nhưng sau các shot trước nó phải bắt đầu ở {$cursorMs} ms và dài {$used} ms.");
            }

            $startFrame = $exact($cursorMs, 'điểm cắt vào');
            $frames = $exact($used, 'phần dùng');
            $exact($trim, 'điểm bắt đầu cắt trong clip');
            [$transition, $problem] = self::transition($index, $row['transition_in'] ?? null, $allowed);

            if ($problem !== null) {
                $blockers[] = self::blocker($problem[0], $shotId, "Shot {$shotId}: {$problem[1]}");
            }

            $results = [];

            foreach ((array) $row['coverage'] as $item) {
                $at = $item['result_at_ms'] ?? null;

                if ($at === null) {
                    continue;
                }

                if ($at < $trim || $at >= $trim + $used) {
                    $blockers[] = self::blocker('result_outside_used', $shotId, "Shot {$shotId}: kết quả của {$item['beat']} ở {$at} ms nằm ngoài phần phim giữ [{$trim}, ".($trim + $used).') ms.');

                    continue;
                }

                $offset = $exact((int) $at - $trim, 'mốc kết quả');

                if ($offset !== null && $startFrame !== null) {
                    $results[] = ['beat' => (string) $item['beat'], 'result_at_ms' => (int) $at, 'film_frame' => $startFrame + $offset, 'offset_frames' => $offset];
                }
            }

            if ($entry !== null) {
                $blockers = [...$blockers, ...self::entryProblems($shotId, $index + 1, $entry, $trim, $used, $profile)];
            }

            $clips[] = [
                'shot_id' => $shotId,
                'scene_id' => (string) $row['scene'],
                'order' => $index + 1,
                'entry' => $entry,
                'trim_start_ms' => $trim,
                'used_duration_ms' => $used,
                'timeline_start_ms' => $cursorMs,
                'timeline_end_ms' => $cursorMs + $used,
                'timeline_start_frame' => $startFrame,
                'duration_frames' => $frames,
                'transition_in' => $transition,
                'fade_in_frames' => 0,
                'fade_out_frames' => 0,
                'results' => $results,
            ];
            $display[] = ['shot_id' => $shotId, 'scene_id' => (string) $row['scene'], 'order' => $index + 1, 'tl_in_ms' => $cursorMs, 'used_duration_ms' => $used, 'transition' => $row['transition_in']['kind'] ?? null, 'render_id' => $entry['render_id'] ?? null, 'cards' => (array) ($row['cards'] ?? [])];
            $cursorMs += $used;
            $cursorFrame += $frames ?? 0;
        }

        $dip = self::frames((int) $policy['transitions']['dip']['each_side_ms'], $fps);

        foreach ($clips as $i => $clip) {
            if (($clip['transition_in']['effect'] ?? null) !== 'dip') {
                continue;
            }

            if ($dip === null) {
                $blockers[] = self::blocker('frame_not_exact', $clip['shot_id'], 'Dip '.$policy['transitions']['dip']['each_side_ms'].' ms mỗi bên không rơi đúng một khung ở '.$fps.' fps.');

                continue;
            }

            $clips[$i - 1]['fade_out_frames'] = $dip;
            $clips[$i]['fade_in_frames'] = $dip;
        }

        foreach ($clips as $clip) {
            $blockers = [...$blockers, ...self::dipProblems($clip)];
        }

        $total = (int) $source['shots']['plan']['total_ms'];

        if ($cursorMs !== $total || (int) $manifest['total_ms'] !== $total) {
            $blockers[] = self::blocker('total_mismatch', 'timeline', "Các shot cộng lại {$cursorMs} ms, kế hoạch shot ghi {$total} ms, manifest Phase 10 ghi {$manifest['total_ms']} ms.");
        }

        $totalFrames = self::frames($cursorMs, $fps);
        [$cards, $cardBlockers, $cardWarnings] = self::cards($source['cards'], $source['script'], $rows, $clips, $cursorMs, $profile, $policy, $style, $font);
        $blockers = [...$blockers, ...$cardBlockers];
        $warnings = [...$warnings, ...$cardWarnings];

        if ($totalFrames === null || $totalFrames !== $cursorFrame) {
            $blockers[] = self::blocker('frame_not_exact', 'timeline', "Phim dài {$cursorMs} ms không quy đúng ra khung ở {$fps} fps.");
        }

        if ($blockers !== []) {
            return ['ready' => false, 'blockers' => $blockers, 'warnings' => $warnings, 'plan' => null, 'rows' => $display];
        }

        $plan = [
            'format' => self::FORMAT,
            'schema' => self::SCHEMA,
            'project_id' => $projectId,
            'contract' => $contract + ['builder' => self::VERSION, 'composer' => CompositionDecor::VERSION],
            'policy' => $policy,
            'source' => [
                'shots' => ['planning_stage_id' => (string) $source['shots']['stage_id'], 'revision' => (int) $source['shots']['revision'], 'token' => $source['shots']['token']],
                'clips' => ['format' => (string) $manifest['format'], 'hash' => (string) $manifest['hash'], 'hash_version' => (string) $manifest['hash_version'], 'selection_version' => (int) $manifest['selection_version']],
                'script' => $source['script'],
            ],
            'output' => $profile,
            'timeline' => ['interval' => 'half_open', 'quantization' => 'exact', 'fps' => $fps, 'total_ms' => $cursorMs, 'total_frames' => $totalFrames],
            'audio' => (array) $policy['audio'] + ['sample_rate' => (int) $profile['sample_rate'], 'channels' => (int) $profile['channels']],
            'style' => $style,
            'clips' => array_map(static fn (array $clip): array => self::clip($clip), $clips),
            'cards' => $cards,
            'warnings' => $warnings,
        ];

        return ['ready' => true, 'blockers' => [], 'warnings' => $warnings, 'plan' => $plan, 'rows' => $display];
    }

    /** @return ?int the frame a millisecond falls on, null when it falls between two */
    public static function frames(int $ms, int $fps): ?int
    {
        return ($ms * $fps) % 1000 === 0 ? intdiv($ms * $fps, 1000) : null;
    }

    /**
     * @param  list<string>  $allowed
     * @return array{0: ?array{kind: string, effect: string}, 1: ?array{0: string, 1: string}}
     */
    private static function transition(int $index, mixed $in, array $allowed): array
    {
        $kind = is_array($in) ? ($in['kind'] ?? null) : null;

        if ($index === 0) {
            return $in === null ? [null, null] : [null, ['transition_on_first', 'shot đầu phim có chuyển cảnh vào '.json_encode($kind).' mà không có clip nào trước nó.']];
        }

        if (! is_string($kind)) {
            return [null, ['transition_missing', 'kế hoạch shot không nói chuyển cảnh vào shot này.']];
        }

        if (! isset(self::EFFECTS[$kind]) || ! in_array($kind, $allowed, true)) {
            return [null, ['transition_unsupported', 'chuyển cảnh '.$kind.' không có trong chính sách Phase 11 ('.implode(', ', $allowed).') — không thay bằng cut.']];
        }

        return [['kind' => $kind, 'effect' => self::EFFECTS[$kind]], null];
    }

    /**
     * @param  array<string, mixed>  $entry  a clip of the Phase 10 manifest
     * @param  array<string, mixed>  $profile
     * @return list<array{code: string, subject: string, message: string}>
     */
    private static function entryProblems(string $shotId, int $order, array $entry, int $trim, int $used, array $profile): array
    {
        $measured = (array) $entry['measured'];
        $problems = [];

        if ((int) $entry['order'] !== $order || $entry['used'] != ['start_ms' => $trim, 'duration_ms' => $used]) {
            $problems[] = self::blocker('clip_used_mismatch', $shotId, "Shot {$shotId}: clip đã duyệt giữ phần ".json_encode($entry['used']).' ở vị trí '.$entry['order'].", kế hoạch shot giữ [{$trim}, ".($trim + $used).") ms ở vị trí {$order}.");
        }

        if (! is_bool($measured['has_audio'] ?? null)) {
            $problems[] = self::blocker('audio_unknown', $shotId, "Shot {$shotId}: không biết clip đã duyệt có tiếng hay không — không đoán.");
        }

        if ((int) ($measured['duration_ms'] ?? 0) < $trim + $used) {
            $problems[] = self::blocker('clip_too_short', $shotId, "Shot {$shotId}: clip đo được {$measured['duration_ms']} ms, phim cần tới ".($trim + $used).' ms.');
        }

        $width = (int) ($measured['width'] ?? 0);
        $height = (int) ($measured['height'] ?? 0);
        $target = $profile['width'] / $profile['height'];

        if ($width < 1 || $height < 1 || abs($width / $height - $target) / $target > (float) $profile['aspect_tolerance']) {
            $problems[] = self::blocker('aspect_mismatch', $shotId, "Shot {$shotId}: clip {$width}×{$height} lệch tỉ lệ khung {$profile['width']}×{$profile['height']} quá ".((float) $profile['aspect_tolerance'] * 100).'% — chính sách không cắt khung.');
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $clip
     * @return list<array{code: string, subject: string, message: string}>
     */
    private static function dipProblems(array $clip): array
    {
        $frames = $clip['duration_frames'];
        $in = $clip['fade_in_frames'];
        $out = $clip['fade_out_frames'];

        if ($frames === null || ($in === 0 && $out === 0)) {
            return [];
        }

        if ($frames <= $in + $out) {
            return [self::blocker('dip_too_short', $clip['shot_id'], "Shot {$clip['shot_id']} giữ {$frames} khung, dip cần {$in} khung đầu và {$out} khung cuối — không đủ chỗ, không rút ngắn.")];
        }

        $problems = [];

        foreach ($clip['results'] as $result) {
            if ($result['offset_frames'] < $in || $result['offset_frames'] >= $frames - $out) {
                $problems[] = self::blocker('dip_covers_result', $clip['shot_id'], "Shot {$clip['shot_id']}: dip phủ lên kết quả của {$result['beat']} ở khung {$result['film_frame']}.");
            }
        }

        return $problems;
    }

    /**
     * @param  list<array<string, mixed>>  $source  the cards of the pinned script on the film's time
     * @param  ?array<string, mixed>  $script  the pinned script, null when it could not be read
     * @param  list<array<string, mixed>>  $rows  the shots of the plan
     * @param  list<array<string, mixed>>  $clips
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $policy
     * @param  array<string, mixed>  $style
     * @return array{0: list<array<string, mixed>>, 1: list<array{code: string, subject: string, message: string}>, 2: list<array<string, mixed>>}
     */
    private static function cards(array $source, ?array $script, array $rows, array $clips, int $totalMs, array $profile, array $policy, array $style, MotoFilmFont $font): array
    {
        $fps = (int) $profile['fps'];
        $cards = [];
        $blockers = [];
        $warnings = [];
        $fragments = [];

        foreach ($source as $card) {
            $id = (string) $card['source_card_id'];
            $start = (int) $card['start_ms'];
            $end = (int) $card['end_ms'];

            foreach ($rows as $row) {
                $in = (int) $row['tl_in_ms'];
                $out = (int) ($row['tl_out_ms'] ?? $in);

                if ($start < $out && $end > $in) {
                    $fragments[$row['id']][] = ['type' => (string) $card['type'], 'text' => (string) $card['text'], 'from_ms' => max($start, $in) - $in, 'to_ms' => min($end, $out) - $in];
                }
            }

            $startFrame = self::frames($start, $fps);
            $endFrame = self::frames($end, $fps);

            if ($startFrame === null || $endFrame === null) {
                $blockers[] = self::blocker('frame_not_exact', $id, "Card {$card['type']} {$start}–{$end} ms không rơi đúng khung ở {$fps} fps — không làm tròn.");

                continue;
            }

            if ($start < 0 || $end > $totalMs || $end <= $start) {
                $blockers[] = self::blocker('card_outside_film', $id, "Card {$card['type']} {$start}–{$end} ms nằm ngoài phim 0–{$totalMs} ms.");

                continue;
            }

            [$lines, $problem] = self::layout((string) $card['text'], $style, $font, (int) $profile['width'], (int) $profile['height']);

            if ($problem !== null) {
                $blockers[] = self::blocker($problem[0], $id, "Card {$card['type']} \"{$card['text']}\": {$problem[1]}");

                continue;
            }

            $words = count(preg_split('/\s+/u', trim((string) $card['text'])) ?: []);
            $rate = round($words * $fps / ($endFrame - $startFrame), 3);
            $limit = (float) $policy['cards']['reading_speed']['warning_above'];

            if ($rate > $limit) {
                $warnings[] = ['code' => 'reading_speed_high', 'subject' => $id, 'message' => "Card {$card['type']} có {$words} từ trong ".self::seconds($end - $start)." giây — {$rate} từ/giây, trên ngưỡng cảnh báo {$limit}. Card vẫn giữ nguyên chữ và thời gian của kịch bản.", 'value' => $rate, 'limit' => $limit];
            }

            foreach ($clips as $clip) {
                if ($clip['timeline_start_frame'] === null || $clip['duration_frames'] === null) {
                    continue;
                }

                $faded = [
                    [$clip['timeline_start_frame'], $clip['timeline_start_frame'] + $clip['fade_in_frames']],
                    [$clip['timeline_start_frame'] + $clip['duration_frames'] - $clip['fade_out_frames'], $clip['timeline_start_frame'] + $clip['duration_frames']],
                ];

                foreach ($faded as [$from, $to]) {
                    if ($from < $to && $startFrame < $to && $endFrame > $from) {
                        $warnings[] = ['code' => 'card_over_dip', 'subject' => $id, 'message' => "Card {$card['type']} hiện trong lúc dip của shot {$clip['shot_id']} (khung {$from}–{$to}); chữ vẫn đủ sáng vì card vẽ trên hình đã mờ.", 'value' => null, 'limit' => null];
                    }
                }
            }

            $cards[] = [
                'source_card_id' => $id,
                'source_path' => (string) $card['source_path'],
                'source_script_stage_id' => (string) $card['source_script_stage_id'],
                'type' => (string) $card['type'],
                'text' => (string) $card['text'],
                'start_ms' => $start,
                'end_ms' => $end,
                'start_frame' => $startFrame,
                'end_frame' => $endFrame,
                'style_id' => (string) $style['id'],
                'style_hash' => (string) $style['hash'],
                'font_sha256' => $font->sha256,
                'lines' => $lines,
                'words' => $words,
                'words_per_second' => $rate,
            ];
        }

        // With no pinned script there is nothing to hold the shot plan's copies against; that blocker is already reported.
        foreach ($script === null ? [] : $rows as $row) {
            if (self::sorted($fragments[$row['id']] ?? []) !== self::sorted(array_map(static fn (array $card): array => ['type' => (string) ($card['type'] ?? ''), 'text' => (string) ($card['text'] ?? ''), 'from_ms' => (int) ($card['from_ms'] ?? -1), 'to_ms' => (int) ($card['to_ms'] ?? -1)], (array) ($row['cards'] ?? [])))) {
                $blockers[] = self::blocker('card_fragment_mismatch', (string) $row['id'], "Shot {$row['id']}: phần card kế hoạch shot ghi không đúng phần giao của các card kịch bản với shot này.");
            }
        }

        usort($cards, static fn (array $a, array $b): int => [$a['start_frame'], $a['end_frame']] <=> [$b['start_frame'], $b['end_frame']]);

        for ($i = 1, $n = count($cards); $i < $n; $i++) {
            if ($cards[$i]['start_frame'] < $cards[$i - 1]['end_frame']) {
                $blockers[] = self::blocker('cards_overlap', $cards[$i]['source_card_id'], "Card {$cards[$i]['type']} bắt đầu ở khung {$cards[$i]['start_frame']} khi card {$cards[$i - 1]['type']} còn hiện tới khung {$cards[$i - 1]['end_frame']} — hai card chồng một chỗ.");
            }
        }

        return [$cards, $blockers, $warnings];
    }

    /**
     * Breaks a text into lines between its words, as wide as the safe area allows at the size of the style, and places them.
     * The words joined by one space give the text back; a text that cannot be placed so is refused, never shortened.
     *
     * @param  array<string, mixed>  $style
     * @return array{0: list<array{text: string, baseline_px: int, width_px: int}>, 1: ?array{0: string, 1: string}}
     */
    public static function layout(string $text, array $style, MotoFilmFont $font, int $frameWidth, int $frameHeight): array
    {
        if ($text === '' || $text !== trim($text) || preg_match('/\s{2,}|[^\S ]/u', $text) === 1) {
            return [[], ['card_text_unsupported', 'chữ có khoảng trắng đầu/cuối, xuống dòng hay tab — xếp dòng sẽ phải đổi chữ, nên không xếp.']];
        }

        $missing = $font->missingGlyphs($text);

        if ($missing !== []) {
            return [[], ['card_glyph_missing', 'font '.$style['font']['family'].' không có glyph cho '.implode(' ', $missing).'.']];
        }

        $size = (int) $style['size_px'];
        $border = (int) $style['border']['width_px'];
        $safe = (array) $style['safe_area'];
        $box = $frameWidth - $safe['left_px'] - $safe['right_px'];
        $lines = [];

        foreach (explode(' ', $text) as $word) {
            $last = count($lines) - 1;
            $joined = $last < 0 ? $word : $lines[$last].' '.$word;

            if ($last >= 0 && $font->width($joined, $size) + 2 * $border <= $box) {
                $lines[$last] = $joined;

                continue;
            }

            if ($font->width($word, $size) + 2 * $border > $box) {
                return [[], ['card_overflow', 'từ "'.$word.'" rộng hơn vùng an toàn '.$box.' px ở cỡ '.$size.' px.']];
            }

            $lines[] = $word;
        }

        if (count($lines) > (int) $style['max_lines']) {
            return [[], ['card_overflow', 'cần '.count($lines).' dòng, kiểu card cho tối đa '.$style['max_lines'].'.']];
        }

        $bottom = $frameHeight - $safe['bottom_px'];
        $height = (int) $style['line_height_px'];
        $count = count($lines);

        if ($bottom - ($count - 1) * $height - $size - $border < $safe['top_px']) {
            return [[], ['card_overflow', $count.' dòng cao hơn vùng an toàn.']];
        }

        return [array_map(static fn (string $line, int $i): array => [
            'text' => $line,
            'baseline_px' => $bottom - ($count - 1 - $i) * $height,
            'width_px' => $font->width($line, $size),
        ], $lines, array_keys($lines)), null];
    }

    /**
     * @param  array<string, mixed>  $clip
     * @return array<string, mixed>
     */
    private static function clip(array $clip): array
    {
        $entry = (array) $clip['entry'];

        return [
            'shot_id' => $clip['shot_id'],
            'scene_id' => $clip['scene_id'],
            'order' => $clip['order'],
            'render_id' => (string) $entry['render_id'],
            'request_hash' => (string) $entry['request_hash'],
            'decision' => ['id' => (string) $entry['decision']['id'], 'selection_version' => (int) $entry['decision']['selection_version'], 'intent_version' => (int) $entry['decision']['intent_version']],
            'artifact' => ['disk' => (string) $entry['artifact']['disk'], 'path' => (string) $entry['artifact']['path'], 'sha256' => (string) $entry['artifact']['sha256']],
            'measured' => [
                'duration_ms' => (int) $entry['measured']['duration_ms'],
                'width' => (int) $entry['measured']['width'],
                'height' => (int) $entry['measured']['height'],
                'bytes' => (int) $entry['measured']['bytes'],
                'has_audio' => (bool) $entry['measured']['has_audio'],
            ],
            'trim_start_ms' => $clip['trim_start_ms'],
            'used_duration_ms' => $clip['used_duration_ms'],
            'timeline_start_ms' => $clip['timeline_start_ms'],
            'timeline_end_ms' => $clip['timeline_end_ms'],
            'timeline_start_frame' => $clip['timeline_start_frame'],
            'duration_frames' => $clip['duration_frames'],
            'transition_in' => $clip['transition_in'],
            'fade_in_frames' => $clip['fade_in_frames'],
            'fade_out_frames' => $clip['fade_out_frames'],
            'audio' => $entry['measured']['has_audio'] ? 'clip' : 'silence',
            'protected_results' => array_map(static fn (array $result): array => array_diff_key($result, ['offset_frames' => true]), $clip['results']),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $fragments
     * @return list<array<string, mixed>>
     */
    private static function sorted(array $fragments): array
    {
        usort($fragments, static fn (array $a, array $b): int => [$a['from_ms'], $a['to_ms'], $a['type'], $a['text']] <=> [$b['from_ms'], $b['to_ms'], $b['type'], $b['text']]);

        return $fragments;
    }

    private static function seconds(int $ms): string
    {
        return rtrim(rtrim(number_format($ms / 1000, 3, ',', ''), '0'), ',');
    }

    /** @return array{code: string, subject: string, message: string} */
    private static function blocker(string $code, string $subject, string $message): array
    {
        return ['code' => $code, 'subject' => $subject, 'message' => $message];
    }
}
