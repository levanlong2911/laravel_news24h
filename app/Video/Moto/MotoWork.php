<?php

declare(strict_types=1);

namespace App\Video\Moto;

final class MotoWork
{
    public const ON = 'on_motorcycle';

    public const OFF = 'off_motorcycle';

    /** @var list<string> the work that moves a part from off to on the motorcycle */
    private const FITTING = ['test_fit', 'install', 'reinstall'];

    /** @var list<string> the fittings of a part that has not been on the motorcycle */
    private const FIRST_FITTING = ['test_fit', 'install'];

    /** @var list<string> the work that keeps a part where it is */
    private const IN_PLACE = ['make', 'finish', 'assemble', 'paint'];

    /** @return string the id code gives a work item of the scene plan */
    public static function key(string $scene, int $index): string
    {
        return $scene.'-W'.($index + 1);
    }

    /**
     * @param  list<array<string, mixed>>  $scenes  the scenes in screen order, each with its id, beats and work
     * @param  array<string, ?string>  $beatStates  the build state of every beat
     * @param  array<string, array<string, mixed>>  $states  the facts of every physical build state the beats use: present, in_progress
     * @param  array{first_states: array<string, ?string>, parents: array<string, ?string>, finished: list<string>}  $design  the first build state with each part of the design on the motorcycle, the part each is mounted on and the finished build states
     * @return array{issues: list<string>, items: array<string, array{beat: string, part: string, operation: string, parts: array<string, array{start: string, end: string, start_status: string, end_status: string}>}>, beats: array<string, array<string, list<array{location: string, status: string, item: string, moment: string}>>>, scenes: array<string, array{start: array<string, array{location: string, status: string, item: string, moment: string}>, end: array<string, array{location: string, status: string, item: string, moment: string}>}>} work outside its scene, out of beat order, before a physical motorcycle exists or on a part the design does not have; making a part its build state does not list in progress and other work on a part it neither has nor makes; installing a part it only makes, which is test-fitted, removed and refitted while being made and starts off the motorcycle until work of that build state puts it on; a move the work does not make, a first fitting of a part that was on, a first test fit included, and a refitting of one never on; a part that changes place within a build state with no work between; a finished motorcycle left with a part off. For every item, every beat and every scene, where each part the work moves or keeps in place is and how it is shown — the part, the parts worked with it and the parts mounted on them: a beat holds every part where the last work of its build state left it, from its start, then after each of its items
     */
    public static function trace(array $scenes, array $beatStates, array $states, array $design): array
    {
        $issues = [];
        $items = [];
        $beats = [];
        $held = [];
        $last = [];
        $on = [];

        foreach ($scenes as $scene) {
            $sceneId = (string) $scene['id'];
            $order = array_values((array) $scene['beats']);
            $sceneState = $beatStates[$order[0] ?? ''] ?? null;
            $start = self::heldAt($last, $sceneState);
            $work = array_values((array) ($scene['work'] ?? []));
            $position = -1;

            foreach ($work as $index => $item) {
                $at = array_search($item['beat'], $order, true);
                $label = self::key($sceneId, $index)." ({$item['beat']} {$item['part']} {$item['operation']})";

                if ($at === false) {
                    $issues[] = "{$label}: {$item['beat']} không thuộc {$sceneId}.";
                } elseif ($at < $position) {
                    $issues[] = "{$label}: work của {$sceneId} phải theo thứ tự beat.";
                }

                $position = $at === false ? $position : max($position, $at);
            }

            foreach ($order as $beat) {
                $state = $beatStates[$beat] ?? null;

                foreach (self::heldAt($last, $state) as $part => $place) {
                    $beats[$beat][$part] = [$place];
                }

                foreach ($work as $index => $item) {
                    if ($item['beat'] !== $beat) {
                        continue;
                    }

                    $key = self::key($sceneId, $index);
                    $label = "{$key} ({$item['beat']} {$item['part']} {$item['operation']})";
                    $facts = is_string($state) ? ($states[$state] ?? null) : null;

                    if (! is_array($facts) || ! isset($facts['present']) || ($facts['physical'] ?? true) === false) {
                        $issues[] = "{$label}: {$beat} ở ".($state ?? 'state null').' chưa có xe thật — không có công việc trên part.';

                        continue;
                    }

                    $named = array_values(array_unique([(string) $item['part'], ...array_map('strval', (array) ($item['with'] ?? []))]));
                    $unknown = array_values(array_filter($named, static fn (string $part): bool => ! array_key_exists($part, $design['first_states'])));

                    if ($unknown !== []) {
                        $issues[] = "{$label}: ".implode(', ', $unknown).' không có trong thiết kế.';

                        continue;
                    }

                    $present = static fn (string $part): bool => in_array($part, (array) $facts['present'], true);
                    $making = static fn (string $part): bool => in_array($part, (array) $facts['in_progress'], true);
                    $issues = [...$issues, ...self::basisIssues($label, $item, $state, $present, $making)];
                    $issues = [...$issues, ...self::moveIssues($label, $item, self::wasOn($on, (string) $item['part'], $state, $design))];
                    $from = (string) $item['location_start'];
                    $to = (string) $item['location_end'];
                    $parts = [];

                    foreach ([...$named, ...self::mounted($named, (array) $facts['present'], $design['parents'])] as $part) {
                        $before = $last[$part] ?? null;

                        if ($before !== null && $before['state'] === $state && $before['location'] !== $from) {
                            $issues[] = "{$label}: {$part} rời {$before['beat']} ở {$before['location']} nhưng bắt đầu ở {$from} trong cùng {$state} mà không có công việc nào ở giữa.";
                        } elseif (($before === null || $before['state'] !== $state) && $making($part) && ! $present($part) && $from !== self::OFF) {
                            $issues[] = "{$label}: {$part} đang chế tạo ở {$state} và chưa có công việc nào trong build state này đặt nó lên xe — công việc đầu tiên trên nó phải bắt đầu ở off_motorcycle.";
                        }

                        $wasOn = self::wasOn($on, $part, $state, $design);
                        $startStatus = self::status($from, $making($part), $wasOn);
                        $on[$part] = $wasOn || $from === self::ON || $to === self::ON;
                        $endStatus = self::status($to, $making($part), $on[$part]);
                        $parts[$part] = ['start' => $from, 'end' => $to, 'start_status' => $startStatus, 'end_status' => $endStatus];
                        $start[$part] ??= ['location' => $from, 'status' => $startStatus, 'item' => $key, 'moment' => 'start'];
                        $beats[$beat][$part] ??= [['location' => $from, 'status' => $startStatus, 'item' => $key, 'moment' => 'start']];
                        $beats[$beat][$part][] = ['location' => $to, 'status' => $endStatus, 'item' => $key, 'moment' => 'end'];
                        $last[$part] = ['state' => $state, 'location' => $to, 'status' => $endStatus, 'beat' => $beat, 'item' => $key];
                    }

                    if (in_array($state, $design['finished'], true) && $to !== self::ON) {
                        $issues[] = "{$label}: {$state} là xe hoàn thiện — part không được kết thúc ngoài xe.";
                    }

                    $items[$key] = ['beat' => $beat, 'part' => (string) $item['part'], 'operation' => (string) $item['operation'], 'parts' => $parts];
                }
            }

            $held[$sceneId] = ['start' => $start, 'end' => self::heldAt($last, $sceneState)];
        }

        return ['issues' => $issues, 'items' => $items, 'beats' => $beats, 'scenes' => $held];
    }

    /**
     * @param  list<array{id: string, work: list<string>, beats: list<string>}>  $shots  the shots in screen order, each with the work items it shows and the beats it covers
     * @param  array<string, mixed>  $work  trace() of the scene plan
     * @return array{issues: list<string>, shots: array<string, array<string, array{location: string, status: string, item: string, moment: string}>>} work items a shot shows out of the scene plan's order, within the shot or after a later item an earlier shot showed; and for every shot, where each part the work holds is at its first frame: at the start of the first item the shot shows on it in its first beat, otherwise where the work shown so far left it in that beat
     */
    public static function shotPlaces(array $shots, array $work): array
    {
        $order = array_flip(array_keys((array) $work['items']));
        $issues = [];
        $places = [];
        $current = [];
        $entered = [];
        $reached = null;
        $enter = static function (string $beat) use (&$current, &$entered, $work): void {
            if (! isset($entered[$beat])) {
                $entered[$beat] = true;
                $current = array_map(static fn (array $held): array => $held[0], (array) ($work['beats'][$beat] ?? []));
            }
        };

        foreach ($shots as $shot) {
            $shown = array_values(array_filter($shot['work'], static fn (string $key): bool => isset($order[$key])));

            foreach ($shown as $key) {
                if ($reached !== null && $order[$key] < $order[$reached]) {
                    $issues[] = "{$shot['id']}: {$key} thể hiện sau {$reached} — work phải theo thứ tự của bản chia scene, trong một shot và giữa các shot.";
                }

                $reached = $reached === null || $order[$key] > $order[$reached] ? $key : $reached;
            }

            usort($shown, static fn (string $a, string $b): int => $order[$a] <=> $order[$b]);
            $first = $shot['beats'][0] ?? null;

            if ($first === null) {
                continue;
            }

            $enter($first);
            $at = [];

            foreach ($shown as $key) {
                if ($work['items'][$key]['beat'] === $first) {
                    foreach ($work['items'][$key]['parts'] as $part => $place) {
                        $at[$part] ??= ['location' => $place['start'], 'status' => $place['start_status'], 'item' => $key, 'moment' => 'start'];
                    }
                }
            }

            $places[$shot['id']] = $at + $current;

            foreach (array_values(array_unique($shot['beats'])) as $beat) {
                $enter($beat);

                foreach ($shown as $key) {
                    if ($work['items'][$key]['beat'] === $beat) {
                        foreach ($work['items'][$key]['parts'] as $part => $place) {
                            $current[$part] = ['location' => $place['end'], 'status' => $place['end_status'], 'item' => $key, 'moment' => 'end'];
                        }
                    }
                }
            }
        }

        return ['issues' => $issues, 'shots' => $places];
    }

    /**
     * @param  ?list<array{part: string, status: string}>  $detached  the detached parts a scene gives at one moment
     * @param  array<string, array{location: string, status: string}>  $held  where the work holds each part it tracks at that moment
     * @return list<string> a part the work holds off the motorcycle that the list leaves out or gives another status, and a part the work holds on it that the list names
     */
    public static function detachedIssues(string $label, ?array $detached, array $held): array
    {
        $issues = [];
        $listed = $detached === null ? null : array_column($detached, 'status', 'part');

        foreach ($held as $part => $place) {
            $given = $listed[$part] ?? null;

            if ($place['location'] === self::OFF && $listed === null) {
                $issues[] = "{$label}.detached_parts là null nhưng work để {$part} ngoài xe ({$place['status']}) — phải liệt kê.";
            } elseif ($place['location'] === self::OFF && $given !== $place['status']) {
                $issues[] = "{$label}.detached_parts: work để {$part} ngoài xe — cần {$part} {$place['status']}".($given === null ? '' : ", không phải {$given}").'.';
            } elseif ($place['location'] === self::ON && $given !== null) {
                $issues[] = "{$label}.detached_parts nêu {$part} ({$given}) nhưng work để nó trên xe lúc này.";
            }
        }

        return $issues;
    }

    /**
     * @param  list<string>  $parts  the ids of the parts of the design
     * @param  array<string, array<string, mixed>>  $facts  MotoScriptCheck::facts()['states'], the physical build states in order
     * @return array<string, ?string> the first build state with each part on the motorcycle, null for a part never on it
     */
    public static function firstStates(array $parts, array $facts): array
    {
        $first = [];

        foreach ($parts as $part) {
            $first[$part] = null;

            foreach ($facts as $state => $fact) {
                if (in_array($part, (array) $fact['present'], true)) {
                    $first[$part] = (string) $state;

                    break;
                }
            }
        }

        return $first;
    }

    /**
     * @param  array<string, array{location: string, status: string, state: string, beat: string, item: string}>  $last
     * @return array<string, array{location: string, status: string, item: string, moment: string}> where the work last left each part within the build state, with the item that left it there
     */
    private static function heldAt(array $last, ?string $state): array
    {
        $held = [];

        foreach ($last as $part => $record) {
            if ($state !== null && $record['state'] === $state) {
                $held[$part] = ['location' => $record['location'], 'status' => $record['status'], 'item' => $record['item'], 'moment' => 'end'];
            }
        }

        return $held;
    }

    /**
     * @param  array<string, bool>  $on  for every part the work has handled so far, whether it has been on the motorcycle
     * @param  array{first_states: array<string, ?string>}  $design
     * @return bool whether the part has been on the motorcycle before now: by the work so far, or by its first build state on the motorcycle when no work has handled it
     */
    private static function wasOn(array $on, string $part, string $state, array $design): bool
    {
        return array_key_exists($part, $on) ? $on[$part] : self::earlier($design['first_states'][$part] ?? null, $state);
    }

    /**
     * @param  list<string>  $named  the parts an item names
     * @param  list<string>  $present  the parts on the motorcycle at the build state
     * @param  array<string, ?string>  $parents
     * @return list<string> the parts present at the build state mounted on a named part, directly or through other parts
     */
    private static function mounted(array $named, array $present, array $parents): array
    {
        $mounted = [];

        foreach ($present as $part) {
            if (in_array($part, $named, true)) {
                continue;
            }

            $seen = [];

            for ($parent = $parents[$part] ?? null; is_string($parent) && ! isset($seen[$parent]); $parent = $parents[$parent] ?? null) {
                $seen[$parent] = true;

                if (in_array($parent, $named, true)) {
                    $mounted[] = (string) $part;

                    break;
                }
            }
        }

        return $mounted;
    }

    private static function status(string $location, bool $making, bool $wasOn): string
    {
        return match (true) {
            $making => 'being_made',
            $location === self::ON => 'installed',
            $wasOn => 'removed',
            default => 'awaiting_install',
        };
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  \Closure(string): bool  $present
     * @param  \Closure(string): bool  $making
     * @return list<string> making a part its state does not list in progress, other work on a part the state neither has nor makes, installing a part the state only makes, and a part worked with it the state neither has nor makes, or only makes when it is installed; a part being made may be test-fitted, removed and refitted
     */
    private static function basisIssues(string $label, array $item, string $state, \Closure $present, \Closure $making): array
    {
        $issues = [];
        $part = (string) $item['part'];

        if ($item['operation'] === 'make' && ! $making($part)) {
            $issues[] = "{$label}: {$part} không có trong in_progress của {$state} — part đã làm xong thì không ghi make.";
        }

        if ($item['operation'] !== 'make' && ! $present($part) && ! $making($part)) {
            $issues[] = "{$label}: {$part} chưa có ở {$state} — không có công việc trên nó.";
        } elseif ($item['operation'] === 'install' && ! $present($part)) {
            $issues[] = "{$label}: {$part} đang chế tạo ở {$state} — install chỉ lắp part mà build state ghi đã có trên xe; part đang chế tạo thì thử lắp bằng test_fit và lắp lại bằng reinstall.";
        }

        foreach ((array) ($item['with'] ?? []) as $other) {
            if (! $present((string) $other) && ! $making((string) $other)) {
                $issues[] = "{$label}: {$other} chưa có ở {$state}.";
            } elseif ($item['operation'] === 'install' && ! $present((string) $other)) {
                $issues[] = "{$label}: {$other} đang chế tạo ở {$state} — không install cùng được.";
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  bool  $wasOn  whether the part has been on the motorcycle before this work
     * @return list<string> a move the operation does not make, a first fitting of a part that was on and a refitting of one never on
     */
    private static function moveIssues(string $label, array $item, bool $wasOn): array
    {
        $issues = [];
        $move = [(string) $item['location_start'], (string) $item['location_end']];
        $operation = (string) $item['operation'];

        if (in_array($operation, self::FITTING, true) && $move !== [self::OFF, self::ON]) {
            $issues[] = "{$label}: {$operation} đưa part từ ngoài xe lên xe — cần off_motorcycle → on_motorcycle.";
        }

        if ($operation === 'remove' && $move !== [self::ON, self::OFF]) {
            $issues[] = "{$label}: remove đưa part từ trên xe ra ngoài — cần on_motorcycle → off_motorcycle.";
        }

        if (in_array($operation, self::IN_PLACE, true) && $move[0] !== $move[1]) {
            $issues[] = "{$label}: {$operation} không đổi vị trí part — đầu và cuối phải giống nhau.";
        }

        if (in_array($operation, self::FIRST_FITTING, true) && $wasOn) {
            $issues[] = "{$label}: {$item['part']} đã từng ở trên xe — lắp lại là reinstall.";
        }

        if ($operation === 'reinstall' && ! $wasOn) {
            $issues[] = "{$label}: {$item['part']} chưa từng ở trên xe — không reinstall.";
        }

        return $issues;
    }

    private static function earlier(?string $first, string $state): bool
    {
        return is_string($first) && (int) substr($first, 2) < (int) substr($state, 2);
    }
}
