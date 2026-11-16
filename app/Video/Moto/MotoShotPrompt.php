<?php

declare(strict_types=1);

namespace App\Video\Moto;

final class MotoShotPrompt
{
    /**
     * @param  array<string, mixed>  $context  the check context pinned with the run
     * @return bool whether the build state is a finished motorcycle, or a stage before a physical one exists
     */
    public static function finishShown(?string $state, array $context): bool
    {
        $facts = $state === null ? null : ($context['states'][$state] ?? null);

        return ! (is_array($facts) && $facts['physical']) || in_array($state, (array) ($context['finished_states'] ?? []), true);
    }

    /**
     * @param  array{part: string, presentation: string}  $item  a part in frame
     * @param  array<string, mixed>  $context  the check context pinned with the run
     * @return list<array{element: string, status: string, text: string}> every surface of the part at the shot's first frame, from its build state, how it is shown and what is true at that frame, never what the shot goes on to do: none for a drawing, the design's model stage for a model, and needs_check where the sources give no finish
     */
    public static function surfaces(array $item, ?string $state, string $firstFrame, array $context): array
    {
        $record = (array) ($context['part_records'][$item['part']] ?? []);
        $records = (array) ($context['state_records'] ?? []);
        $at = match ($item['presentation']) {
            'drawing' => null,
            'model' => collect($records)->firstWhere('kind', 'model') ?? ['id' => (string) $state, 'kind' => 'model'],
            default => $state === null ? null : ($records[$state] ?? null),
        };

        if ($record === [] || ! is_array($at)) {
            return [];
        }

        $being = $item['presentation'] === 'being_made' || collect((array) ($at['in_progress'] ?? []))->contains('part', $item['part']);
        $reference = (array) config('video.moto.reference');

        return MotoReferenceSurface::components($record, $at, (array) ($context['palette'] ?? []), $being, array_values(array_filter([
            is_string($at['surface'] ?? null) ? ['path' => "design.build_states.{$at['id']}.surface", 'text' => $at['surface']] : null,
            ['path' => 'shot.first_frame', 'text' => $firstFrame],
        ])), (array) $reference['surface_groups'], (array) $reference['finish_labels'], (string) ($context['surface_rule'] ?? MotoReferenceSurface::RULE_ANY_MATERIAL));
    }

    /**
     * @param  array<string, mixed>  $row  a shot of the plan
     * @return ?string why the timing of the shot cannot be told to a model: result_at_ms is clip time, so a moment outside the rendered clip or the part the film keeps means the source does not say when it shows, and one result text cannot be tied to several moments without guessing
     */
    public static function clipProblem(array $row): ?string
    {
        $length = $row['render_duration_ms'] ?? null;
        $shot = 'Shot '.($row['id'] ?? '?');

        if (! is_int($length) || $length <= 0) {
            return $shot.' không có thời lượng render hợp lệ.';
        }

        $start = is_int($row['used_start_ms'] ?? null) ? $row['used_start_ms'] : 0;
        $used = is_int($row['used_duration_ms'] ?? null) ? $row['used_duration_ms'] : $length;

        if ($start < 0 || $used <= 0 || $start + $used > $length) {
            return $shot.' giữ đoạn '.$start.'+'.$used.' ms ngoài clip render '.$length.' ms.';
        }

        if (isset($row['action_timeline'])) {
            $cursor = 0;

            foreach ((array) $row['action_timeline'] as $step) {
                if (! is_array($step) || ($step['start_ms'] ?? null) !== $cursor || ! is_int($step['end_ms'] ?? null) || $step['end_ms'] <= $cursor) {
                    return $shot.' có các nhịp hành động không nối liền từ 0 ms.';
                }

                $cursor = $step['end_ms'];
            }

            return $start === 0 && $used === $length && $cursor === $length ? null : $shot.' có các nhịp hành động không phủ trọn '.$length.' ms của clip.';
        }

        $moments = [];

        foreach ((array) ($row['coverage'] ?? []) as $item) {
            if (! is_array($item) || ! array_key_exists('result_at_ms', $item)) {
                return $shot.' thiếu mốc kết quả trong nguồn.';
            }

            $at = $item['result_at_ms'];

            if ($at !== null && (! is_int($at) || $at < 0 || $at > $length)) {
                return $shot.' có mốc kết quả ngoài clip render '.$length.' ms.';
            }

            if ($at !== null && ($at < $start || $at > $start + $used)) {
                return $shot.' có mốc kết quả '.$at.' ms ngoài đoạn phim giữ lại.';
            }

            if ($at !== null) {
                $moments[$at] = true;
            }
        }

        return count($moments) > 1
            ? $shot.' có '.count($moments).' mốc kết quả nhưng chỉ một câu kết quả — nguồn chưa nối được kết quả với thời điểm.'
            : null;
    }
}
