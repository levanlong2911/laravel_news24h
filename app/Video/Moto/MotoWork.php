<?php

declare(strict_types=1);

namespace App\Video\Moto;

final class MotoWork
{
    public const ON = 'on_motorcycle';

    public const OFF = 'off_motorcycle';

    /** @var string the work that holds a part up to its place to compare it, leaving it off the motorcycle */
    public const ALIGN_CHECK = 'align_check';

    /** @var list<string> every operation a work item may have */
    private const OPERATIONS = ['make', 'finish', 'test_fit', 'assemble', 'paint', 'remove', 'install', 'reinstall', self::ALIGN_CHECK];

    /** @var list<string> the work that moves a part from off to on the motorcycle */
    private const FITTING = ['test_fit', 'install', 'reinstall'];

    /** @var list<string> the fittings of a part that has not been on the motorcycle */
    private const FIRST_FITTING = ['test_fit', 'install'];

    /** @var list<string> the work that keeps a part where it is */
    private const IN_PLACE = ['make', 'finish', 'assemble', 'paint'];

    /** @var string the law of trace(): a part being made starts off the motorcycle, and the parts the design mounts on a named part go with it. A scene plan that names no law is followed under it */
    public const LAW_V1 = 'moto-work-tracking-v1';

    /** @var string the law of traceConnected(): making a part never decides where it is, a part goes with another only through a connection the work established, and the detached parts of a scene are derived from the work */
    public const LAW_V2 = 'moto-work-tracking-v2';

    /**
     * @param  ?string  $law  the law the scene plan pinned, null for a plan made before laws were named
     * @param  list<array<string, mixed>>  $scenes
     * @param  array<string, ?string>  $beatStates
     * @param  array<string, array<string, mixed>>  $states
     * @param  array{first_states: array<string, ?string>, parents: array<string, ?string>, finished: list<string>}  $design
     * @param  ?array<string, array{stage: ?string, checkpoints: list<string>}>  $spans  for a design of seven stages (MotoDesignStates::workSpans()): each beat's checkpoints, whose facts the work reads, while $beatStates gives its stage, under which the work keeps a part where it left it; null for a legacy design, read at each beat's own build state
     * @return array<string, mixed> trace() for a plan under LAW_V1 or under no named law, traceConnected() for one under LAW_V2
     *
     * @throws \RuntimeException when the plan names a law this code does not have
     */
    public static function follow(?string $law, array $scenes, array $beatStates, array $states, array $design, ?array $spans = null): array
    {
        $trace = self::traced($law, $scenes, $beatStates, $states, $design, $spans);

        // What code derives, pins and plans from keeps the messages as text, exactly as it always read them.
        return ['issues' => array_map('strval', $trace['issues']), 'contradictions' => array_map('strval', $trace['contradictions'])] + $trace;
    }

    /**
     * follow(), with each finding still carrying the decision and the code of its rule: for the scene check only.
     *
     * @param  list<array<string, mixed>>  $scenes
     * @param  array<string, ?string>  $beatStates
     * @param  array<string, array<string, mixed>>  $states
     * @param  array{first_states: array<string, ?string>, parents: array<string, ?string>, finished: list<string>}  $design
     * @param  ?array<string, array{stage: ?string, checkpoints: list<string>}>  $spans  as follow()
     * @return array<string, mixed>
     */
    public static function traced(?string $law, array $scenes, array $beatStates, array $states, array $design, ?array $spans = null): array
    {
        return match ($law) {
            null, self::LAW_V1 => self::trace($scenes, $beatStates, $states, $design, $spans),
            self::LAW_V2 => self::traceConnected($scenes, $beatStates, $states, $design, $spans),
            default => throw new \RuntimeException("Bản chia scene ghim luật work {$law} mà code này không có."),
        };
    }

    /**
     * @param  array<string, array{location: string, status: string}>  $held  where the work holds each part at one moment
     * @return list<array{part: string, status: string}> the parts the work holds off the motorcycle then, each being_made, removed or awaiting_install; a part on the motorcycle is not listed
     */
    public static function detached(array $held): array
    {
        $rows = [];

        foreach ($held as $part => $place) {
            if ($place['location'] === self::OFF) {
                $rows[] = ['part' => (string) $part, 'status' => (string) $place['status']];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $facts  the facts of a physical build state: present, in_progress
     * @param  array<string, array{location: string, status: string}>  $held  where the work holds each part at one moment
     * @return list<string> the parts the build state only makes whose place no work has established by then: neither on the motorcycle nor off it is known
     */
    public static function unplaced(array $facts, array $held): array
    {
        return array_values(array_filter(
            array_map('strval', (array) ($facts['in_progress'] ?? [])),
            static fn (string $part): bool => ! in_array($part, (array) ($facts['present'] ?? []), true) && ! isset($held[$part]),
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $scenes  the scenes in screen order, each with its id, beats and work; a work item may carry connection_changes
     * @param  array<string, ?string>  $beatStates  the build state of every beat
     * @param  array<string, array<string, mixed>>  $states  the facts of every physical build state the beats use: present, in_progress
     * @param  array{first_states: array<string, ?string>, parents: array<string, ?string>, finished: list<string>}  $design
     * @return array{law: string, issues: list<string>, items: array<string, array<string, mixed>>, beats: array<string, array<string, list<array{location: string, status: string, item: string, moment: string}>>>, scenes: array<string, array{start: array<string, array{location: string, status: string, item: string, moment: string}>, end: array<string, array{location: string, status: string, item: string, moment: string}>}>} the work followed under LAW_V2, in the shape of trace(). What differs: making, finishing, painting or assembling a part keeps it where it is and the first such work establishes that place, on the motorcycle or off it; within a build state a place holds until a fitting or a removal; only the parts an item names and the parts a connection of the same build state attaches to them, directly or through other parts, go with it — the design's mounting tree and a shared place attach nothing; a connection is attached or detached by connection_changes of an item that names one of its two parts; attaching two parts in different places, attaching a part still attached elsewhere, detaching a part from another part than the one it is attached to, and moving an attached part away from the part it is attached to with no detach are contradictions, each naming the work, the part and the evidence; so are a connection change on a part its build state neither has nor makes, and an attachment to or of a part whose place is not established. Where a part is at an instant of a build state: where its last work of that state left it, otherwise where its first work of that state starts, otherwise on the motorcycle when the state has it present and does not make it, otherwise not established. The places a scene and a beat hold are read the same way: a part whose first work of the build state comes in a later beat or scene is held, from the start of that build state, where that work starts, named by that work. A connection change is recorded only once it stands; a part detached by the work is known to be attached to nothing until it is attached again, so detaching it once more is a contradiction, while a part no work has attached or detached may be detached on its event
     */
    public static function traceConnected(array $scenes, array $beatStates, array $states, array $design, ?array $spans = null): array
    {
        $issues = [];
        $items = [];
        $beats = [];
        $held = [];
        $last = [];
        $on = [];
        $links = [];
        $first = [];
        $freed = [];
        $ids = [];
        $doubt = [];

        foreach ($scenes as $scene) {
            foreach (array_values((array) ($scene['work'] ?? [])) as $index => $item) {
                $state = $beatStates[$item['beat']] ?? null;

                foreach ([(string) $item['part'], ...array_map('strval', (array) ($item['with'] ?? []))] as $part) {
                    $first[(string) $state][$part] ??= ['location' => (string) $item['location_start'], 'item' => self::key((string) $scene['id'], $index)];
                }
            }
        }

        $placed = static function (?string $state, ?string $beat = null, bool $end = false) use (&$last, &$on, $first, $states, $design, $spans): array {
            $held = self::heldAt($last, $state);
            // One instant: in a v3 design the checkpoint the beat opens on, or at the end of a scene the one its last beat ends on.
            $at = $end ? self::closingAt($beat, $state, $spans) : self::openingAt($beat, $state, $spans);
            $facts = $spans === null ? self::factsAt($beat, $state, $states, null) : (is_string($at) ? ($states[$at] ?? null) : null);

            if (! is_array($facts) || ! isset($facts['present'])) {
                return $held;
            }

            if ($spans !== null) {
                // Where the work left each part, standing as the checkpoint of this instant has it.
                $held = self::restated($held, $facts, $on, (string) $at, $design);
            }

            foreach ($first[$state] ?? [] as $part => $opening) {
                // Later work of the stage places a part only at a checkpoint that already has it, present or being made.
                $exists = $spans === null || in_array($part, (array) $facts['present'], true) || in_array($part, (array) $facts['in_progress'], true);

                if (! isset($held[$part]) && $exists && array_key_exists($part, $design['first_states'])) {
                    $held[$part] = [
                        'location' => $opening['location'],
                        'status' => self::status($opening['location'], in_array($part, (array) $facts['in_progress'], true), self::wasOn($on, (string) $part, (string) $at, $design)),
                        'item' => $opening['item'],
                        'moment' => 'start',
                    ];
                }
            }

            return $held;
        };

        foreach ($scenes as $scene) {
            $sceneId = (string) $scene['id'];
            $order = array_values((array) $scene['beats']);
            $sceneState = $beatStates[$order[0] ?? ''] ?? null;
            $start = $placed($sceneState, $order[0] ?? null);
            $work = array_values((array) ($scene['work'] ?? []));
            $position = -1;

            foreach ($work as $index => $item) {
                $at = array_search($item['beat'], $order, true);
                $label = self::key($sceneId, $index)." ({$item['beat']} {$item['part']} {$item['operation']})";

                if ($at === false) {
                    $ids[] = Finding::requirement('MotoWork.ids.beat_scene', "{$label}: {$item['beat']} không thuộc {$sceneId}.");
                } elseif ($at < $position) {
                    $ids[] = Finding::requirement('MotoWork.ids.beat_order', "{$label}: work của {$sceneId} phải theo thứ tự beat.");
                }

                $position = $at === false ? $position : max($position, $at);
            }

            foreach ($order as $beat) {
                $state = $beatStates[$beat] ?? null;

                foreach ($placed($state, $beat) as $part => $place) {
                    $beats[$beat][$part] = [$place];
                }

                // The facts the work of this beat is read against, and the state it opens on: its build state, or in a v3 design its checkpoints and the first of them.
                $opening = (string) self::openingAt($beat, $state, $spans);

                foreach ($work as $index => $item) {
                    if ($item['beat'] !== $beat) {
                        continue;
                    }

                    $key = self::key($sceneId, $index);
                    $label = "{$key} ({$item['beat']} {$item['part']} {$item['operation']})";
                    $facts = self::factsAt($beat, $state, $states, $spans);
                    $named = array_values(array_unique([(string) $item['part'], ...array_map('strval', (array) ($item['with'] ?? []))]));
                    $unknown = self::unknownParts($named, $design);

                    if ($unknown !== []) {
                        $ids[] = Finding::requirement('MotoWork.ids.part', "{$label}: ".implode(', ', $unknown).' không có trong thiết kế.');
                    }

                    foreach (array_values((array) ($item['connection_changes'] ?? [])) as $change) {
                        $strangers = self::unknownParts([(string) $change['part'], (string) $change['parent']], $design);

                        if ($strangers !== []) {
                            $ids[] = Finding::requirement('MotoWork.ids.connection', "{$label}: connection_changes nêu ".implode(', ', $strangers).', không có trong thiết kế.');
                        }
                    }

                    if (! is_array($facts) || ! isset($facts['present']) || ($facts['physical'] ?? true) === false) {
                        $issues[] = Finding::requirement('MotoWork.traceConnected.1', "{$label}: {$beat} ở ".($state ?? 'state null').' chưa có xe thật — không có công việc trên part.');

                        continue;
                    }

                    if ($unknown !== []) {
                        continue;
                    }

                    $present = static fn (string $part): bool => in_array($part, (array) $facts['present'], true);
                    $making = static fn (string $part): bool => in_array($part, (array) $facts['in_progress'], true);
                    // The facts of the whole span decide whether the work may be done; how a part stands at an instant is read at that instant's checkpoint.
                    $shown = self::instants($beat, $state, $states, $spans, $work, $index);
                    $issues = [...$issues, ...self::basisIssues($label, $item, $state, $present, $making)];
                    $issues = [...$issues, ...self::moveIssues($label, $item, self::wasOn($on, (string) $item['part'], $opening, $design))];
                    $doubted = self::basisIssues($label, $item, $state, $present, $making) !== [] || self::moveIssues($label, $item, self::wasOn($on, (string) $item['part'], $opening, $design)) !== [];
                    $mark = count($issues);
                    $from = (string) $item['location_start'];
                    $to = (string) $item['location_end'];
                    $attaches = [];
                    $recorded = [];

                    foreach (array_values((array) ($item['connection_changes'] ?? [])) as $change) {
                        $loose = ($freed[(string) $change['part']]['state'] ?? null) === $state ? $freed[(string) $change['part']] : null;
                        $child = (string) $change['part'];
                        $parent = (string) $change['parent'];
                        $cited = implode(', ', array_column((array) ($change['evidence'] ?? []), 'path'));
                        $strangers = array_values(array_filter([$child, $parent], static fn (string $part): bool => ! array_key_exists($part, $design['first_states'])));
                        $absent = array_values(array_filter([$child, $parent], static fn (string $part): bool => ! $present($part) && ! $making($part)));
                        $existing = ($links[$child]['state'] ?? null) === $state ? $links[$child] : null;

                        if ($strangers !== []) {
                            continue;
                        }

                        if ($child === $parent) {
                            $issues[] = Finding::requirement('MotoWork.traceConnected.2', "{$label}: connection_changes nối {$child} với chính nó.");
                        } elseif (! in_array($child, $named, true) && ! in_array($parent, $named, true)) {
                            $issues[] = Finding::requirement('MotoWork.traceConnected.3', "{$label}: connection_changes {$change['change']} {$child} — {$parent} nhưng công việc này không nêu part nào trong hai part đó (part hoặc with).");
                        } elseif ($absent !== []) {
                            $issues[] = Finding::requirement('MotoWork.traceConnected.4', "{$label}: connection_changes {$change['change']} {$child} — {$parent} nhưng ".implode(', ', $absent)." chưa có ở {$state} (không có trên xe, không đang chế tạo) — không nối hay tách part chưa tồn tại; bằng chứng {$cited}.");
                        } elseif ($change['change'] === 'detach') {
                            if ($existing !== null && $existing['parent'] !== $parent) {
                                $issues[] = Finding::requirement('MotoWork.traceConnected.5', "{$label}: connection_changes tách {$child} khỏi {$parent} nhưng {$child} đang gắn vào {$existing['parent']} (xác lập ở {$existing['item']}) — không ghi nhận; bằng chứng {$cited}.");

                                continue;
                            }

                            if ($existing === null && $loose !== null) {
                                $issues[] = Finding::requirement('MotoWork.traceConnected.6', "{$label}: connection_changes tách {$child} khỏi {$parent} nhưng {$child} đã được tách khỏi {$loose['parent']} ở {$loose['item']} và chưa gắn lại — hiện không gắn vào part nào; bằng chứng {$cited}.");

                                continue;
                            }

                            unset($links[$child]);
                            $freed[$child] = ['parent' => $parent, 'state' => $state, 'item' => $key];
                            $recorded[] = ['part' => $child, 'parent' => $parent, 'change' => 'detach'];
                        } else {
                            $attaches[] = ['part' => $child, 'parent' => $parent, 'evidence' => $cited];
                        }
                    }

                    $moved = [...$named, ...self::attached($named, $links, $state)];
                    $parts = [];

                    foreach ($moved as $part) {
                        $before = $last[$part] ?? null;
                        $link = ($links[$part]['state'] ?? null) === $state ? $links[$part] : null;

                        if ($before !== null && $before['state'] === $state && $before['location'] !== $from) {
                            $issues[] = Finding::requirement('MotoWork.traceConnected.7', in_array($part, $named, true)
                                ? "{$label}: {$part} rời {$before['beat']} ở {$before['location']} nhưng bắt đầu ở {$from} trong cùng {$state} mà không có công việc nào ở giữa."
                                : "{$label}: {$part} đang gắn vào {$link['parent']} (xác lập ở {$link['item']}) nên đi cùng công việc này, nhưng {$part} rời {$before['beat']} ở {$before['location']} còn công việc bắt đầu ở {$from}.");
                        }

                        $wasOn = self::wasOn($on, $part, $opening, $design);

                        if ($item['operation'] === self::ALIGN_CHECK && in_array($part, $named, true) && $wasOn && $present($part) && ($before === null || $before['state'] !== $state)) {
                            $issues[] = Finding::requirement('MotoWork.traceConnected.8', "{$label}: {$part} đang ở trên xe tại {$state} — align_check chỉ đối chiếu khi chi tiết còn ngoài xe.");
                        }

                        $startStatus = self::status($from, $shown === null ? $making($part) : $shown['start']($part), $wasOn);
                        $on[$part] = $wasOn || $from === self::ON || $to === self::ON;
                        $endStatus = self::status($to, $shown === null ? $making($part) : $shown['end']($part), $on[$part]);
                        $parts[$part] = ['start' => $from, 'end' => $to, 'start_status' => $startStatus, 'end_status' => $endStatus];
                        $start[$part] ??= ['location' => $from, 'status' => $startStatus, 'item' => $key, 'moment' => 'start'];
                        $beats[$beat][$part] ??= [['location' => $from, 'status' => $startStatus, 'item' => $key, 'moment' => 'start']];
                        $beats[$beat][$part][] = ['location' => $to, 'status' => $endStatus, 'item' => $key, 'moment' => 'end'];
                        $last[$part] = ['state' => $state, 'location' => $to, 'status' => $endStatus, 'beat' => $beat, 'item' => $key];
                    }

                    $place = static fn (string $part): ?array => match (true) {
                        ($last[$part]['state'] ?? null) === $state => ['location' => $last[$part]['location'], 'basis' => "sau {$last[$part]['item']}"],
                        isset($first[(string) $state][$part]) => ['location' => $first[(string) $state][$part]['location'], 'basis' => "nơi công việc đầu tiên của nó ở {$state}, {$first[(string) $state][$part]['item']}, bắt đầu"],
                        $present($part) && ! $making($part) => ['location' => self::ON, 'basis' => "{$state} ghi có trên xe, không công việc nào dời nó"],
                        default => null,
                    };

                    foreach ($moved as $part) {
                        $link = ($links[$part]['state'] ?? null) === $state ? $links[$part] : null;
                        $anchor = $link === null || in_array($link['parent'], $moved, true) ? null : $place($link['parent']);

                        if ($anchor !== null && $anchor['location'] !== $to) {
                            $issues[] = Finding::requirement('MotoWork.traceConnected.9', "{$label}: {$part} đang gắn vào {$link['parent']} (xác lập ở {$link['item']}) nhưng công việc này để {$part} ở {$to} trong khi {$link['parent']} ở {$anchor['location']} — tách thì phải khai connection_changes detach có bằng chứng.");
                        }
                    }

                    foreach ($attaches as $attach) {
                        $existing = ($links[$attach['part']]['state'] ?? null) === $state ? $links[$attach['part']] : null;
                        $a = $place($attach['part']);
                        $b = $place($attach['parent']);
                        $unplaced = array_values(array_filter([$a === null ? $attach['part'] : null, $b === null ? $attach['parent'] : null]));

                        if ($existing !== null && $existing['parent'] !== $attach['parent']) {
                            $issues[] = Finding::requirement('MotoWork.traceConnected.10', "{$label}: connection_changes gắn {$attach['part']} vào {$attach['parent']} nhưng {$attach['part']} đang gắn vào {$existing['parent']} (xác lập ở {$existing['item']}) — phải detach trước; bằng chứng {$attach['evidence']}.");
                        } elseif ($unplaced !== []) {
                            $issues[] = Finding::requirement('MotoWork.traceConnected.11', "{$label}: connection_changes gắn {$attach['part']} vào {$attach['parent']} nhưng vị trí của ".implode(', ', $unplaced)." ở {$state} chưa được xác lập (đang chế tạo, chưa công việc nào cho biết trên xe hay ngoài xe) — không nối được với part chưa rõ ở đâu; bằng chứng {$attach['evidence']}.");
                        } elseif ($a['location'] !== $b['location']) {
                            $issues[] = Finding::requirement('MotoWork.traceConnected.12', "{$label}: connection_changes gắn {$attach['part']} vào {$attach['parent']} nhưng {$attach['part']} ở {$a['location']} ({$a['basis']}) còn {$attach['parent']} ở {$b['location']} ({$b['basis']}) — hai part khác chỗ thì không nối được; bằng chứng {$attach['evidence']}.");
                        } else {
                            $links[$attach['part']] = ['parent' => $attach['parent'], 'state' => $state, 'item' => $key];
                            unset($freed[$attach['part']]);
                            $recorded[] = ['part' => $attach['part'], 'parent' => $attach['parent'], 'change' => 'attach'];
                        }
                    }

                    if (in_array($opening, $design['finished'], true) && $to !== self::ON) {
                        $issues[] = Finding::requirement('MotoWork.traceConnected.13', "{$label}: {$state} là xe hoàn thiện — part không được kết thúc ngoài xe.");
                    }

                    if ($doubted || count($issues) > $mark) {
                        foreach ([...$moved, ...array_merge([], ...array_map(static fn (array $change): array => [(string) $change['part'], (string) $change['parent']], array_values((array) ($item['connection_changes'] ?? []))))] as $part) {
                            $doubt[(string) $state][(string) $part] = true;
                        }
                    }

                    $items[$key] = ['beat' => $beat, 'part' => (string) $item['part'], 'operation' => (string) $item['operation'], 'parts' => $parts, 'connections' => $recorded];
                }
            }

            // The scene ends where its last beat ends; in a legacy design every beat of a scene has the same build state.
            $held[$sceneId] = ['start' => $start, 'end' => $placed($sceneState, $spans === null ? ($order[0] ?? null) : ($order[count($order) - 1] ?? null), true)];
        }

        return ['law' => self::LAW_V2, 'issues' => $ids, 'contradictions' => $issues, 'doubt' => array_map(static fn (array $parts): array => array_map('strval', array_keys($parts)), $doubt), 'items' => $items, 'beats' => $beats, 'scenes' => $held];
    }

    /**
     * @param  list<string>  $named  the parts an item names
     * @param  array<string, array{parent: string, state: ?string, item: string}>  $links  the connections the work established so far, by the attached part
     * @param  ?string  $state  the build state of the item
     * @return list<string> the parts a connection of that build state attaches to a named part, directly or through other attached parts
     */
    private static function attached(array $named, array $links, ?string $state): array
    {
        $attached = [];

        foreach (array_keys($links) as $part) {
            $part = (string) $part;

            if (in_array($part, $named, true)) {
                continue;
            }

            $seen = [];

            for ($at = $part; isset($links[$at]) && $links[$at]['state'] === $state && ! isset($seen[$at]); $at = $links[$at]['parent']) {
                $seen[$at] = true;

                if (in_array($links[$at]['parent'], $named, true)) {
                    $attached[] = $part;

                    break;
                }
            }
        }

        return $attached;
    }

    /** @return string the id code gives a work item of the scene plan */
    /**
     * @param  list<string>  $parts  the part ids a work item or a connection change names
     * @param  array<string, mixed>  $design  the design facts a trace reads, with first_states by part id
     * @return list<string> those the design does not have; read before anything else of the work, so no state of a beat hides an id that does not exist
     */
    private static function unknownParts(array $parts, array $design): array
    {
        return array_values(array_unique(array_filter($parts, static fn (string $part): bool => ! array_key_exists($part, (array) $design['first_states']))));
    }

    public static function key(string $scene, int $index): string
    {
        return $scene.'-W'.($index + 1);
    }

    /**
     * @param  list<array<string, mixed>>  $scenes  the scenes in screen order, each with its id, beats and work
     * @param  array<string, ?string>  $beatStates  the build state of every beat
     * @param  array<string, array<string, mixed>>  $states  the facts of every physical build state the beats use: present, in_progress
     * @param  array{first_states: array<string, ?string>, parents: array<string, ?string>, finished: list<string>}  $design  the first build state with each part of the design on the motorcycle, the part each is mounted on and the finished build states
     * @return array{issues: list<string>, items: array<string, array{beat: string, part: string, operation: string, parts: array<string, array{start: string, end: string, start_status: string, end_status: string}>}>, beats: array<string, array<string, list<array{location: string, status: string, item: string, moment: string}>>>, scenes: array<string, array{start: array<string, array{location: string, status: string, item: string, moment: string}>, end: array<string, array{location: string, status: string, item: string, moment: string}>}>} work outside its scene, out of beat order, before a physical motorcycle exists or on a part the design does not have; making a part its build state does not list in progress and other work on a part it neither has nor makes; installing a part it only makes, which is test-fitted, removed and refitted while being made and starts off the motorcycle until work of that build state puts it on; a move the work does not make, a first fitting of a part that was on, a first test fit included, and a refitting of one never on; a part named by align_check that its build state has on the motorcycle; a part that changes place within a build state with no work between; a finished motorcycle left with a part off. For every item, every beat and every scene, where each part the work moves or keeps in place is and how it is shown — the part, the parts worked with it and the parts mounted on them: a beat holds every part where the last work of its build state left it, from its start, then after each of its items
     */
    public static function trace(array $scenes, array $beatStates, array $states, array $design, ?array $spans = null): array
    {
        $issues = [];
        $items = [];
        $beats = [];
        $held = [];
        $last = [];
        $on = [];
        $ids = [];

        foreach ($scenes as $scene) {
            $sceneId = (string) $scene['id'];
            $order = array_values((array) $scene['beats']);
            $sceneState = $beatStates[$order[0] ?? ''] ?? null;
            $start = self::heldNow($last, $sceneState, $order[0] ?? null, false, $states, $spans, $on, $design);
            $work = array_values((array) ($scene['work'] ?? []));
            $position = -1;

            foreach ($work as $index => $item) {
                $at = array_search($item['beat'], $order, true);
                $label = self::key($sceneId, $index)." ({$item['beat']} {$item['part']} {$item['operation']})";

                if ($at === false) {
                    $ids[] = Finding::requirement('MotoWork.ids.beat_scene', "{$label}: {$item['beat']} không thuộc {$sceneId}.");
                } elseif ($at < $position) {
                    $ids[] = Finding::requirement('MotoWork.ids.beat_order', "{$label}: work của {$sceneId} phải theo thứ tự beat.");
                }

                $position = $at === false ? $position : max($position, $at);
            }

            foreach ($order as $beat) {
                $state = $beatStates[$beat] ?? null;

                foreach (self::heldNow($last, $state, $beat, false, $states, $spans, $on, $design) as $part => $place) {
                    $beats[$beat][$part] = [$place];
                }

                foreach ($work as $index => $item) {
                    if ($item['beat'] !== $beat) {
                        continue;
                    }

                    $key = self::key($sceneId, $index);
                    $label = "{$key} ({$item['beat']} {$item['part']} {$item['operation']})";
                    $facts = self::factsAt($beat, $state, $states, $spans);
                    $opening = (string) self::openingAt($beat, $state, $spans);
                    $named = array_values(array_unique([(string) $item['part'], ...array_map('strval', (array) ($item['with'] ?? []))]));
                    $unknown = self::unknownParts($named, $design);

                    if ($unknown !== []) {
                        $ids[] = Finding::requirement('MotoWork.ids.part', "{$label}: ".implode(', ', $unknown).' không có trong thiết kế.');
                    }

                    if (! is_array($facts) || ! isset($facts['present']) || ($facts['physical'] ?? true) === false) {
                        $issues[] = Finding::requirement('MotoWork.trace.1', "{$label}: {$beat} ở ".($state ?? 'state null').' chưa có xe thật — không có công việc trên part.');

                        continue;
                    }

                    if ($unknown !== []) {
                        continue;
                    }

                    $present = static fn (string $part): bool => in_array($part, (array) $facts['present'], true);
                    $making = static fn (string $part): bool => in_array($part, (array) $facts['in_progress'], true);
                    $shown = self::instants($beat, $state, $states, $spans, $work, $index);
                    $issues = [...$issues, ...self::basisIssues($label, $item, $state, $present, $making)];
                    $issues = [...$issues, ...self::moveIssues($label, $item, self::wasOn($on, (string) $item['part'], $opening, $design))];
                    $from = (string) $item['location_start'];
                    $to = (string) $item['location_end'];
                    $parts = [];

                    foreach ([...$named, ...self::mounted($named, (array) $facts['present'], $design['parents'])] as $part) {
                        $before = $last[$part] ?? null;

                        if ($before !== null && $before['state'] === $state && $before['location'] !== $from) {
                            $issues[] = Finding::requirement('MotoWork.trace.2', "{$label}: {$part} rời {$before['beat']} ở {$before['location']} nhưng bắt đầu ở {$from} trong cùng {$state} mà không có công việc nào ở giữa.");
                        } elseif (($before === null || $before['state'] !== $state) && $making($part) && ! $present($part) && $from !== self::OFF) {
                            $issues[] = Finding::requirement('MotoWork.trace.3', "{$label}: {$part} đang chế tạo ở {$state} và chưa có công việc nào trong build state này đặt nó lên xe — công việc đầu tiên trên nó phải bắt đầu ở off_motorcycle.");
                        }

                        $wasOn = self::wasOn($on, $part, $opening, $design);

                        if ($item['operation'] === self::ALIGN_CHECK && in_array($part, $named, true) && $wasOn && $present($part) && ($before === null || $before['state'] !== $state)) {
                            $issues[] = Finding::requirement('MotoWork.trace.4', "{$label}: {$part} đang ở trên xe tại {$state} — align_check chỉ đối chiếu khi chi tiết còn ngoài xe.");
                        }

                        $startStatus = self::status($from, $shown === null ? $making($part) : $shown['start']($part), $wasOn);
                        $on[$part] = $wasOn || $from === self::ON || $to === self::ON;
                        $endStatus = self::status($to, $shown === null ? $making($part) : $shown['end']($part), $on[$part]);
                        $parts[$part] = ['start' => $from, 'end' => $to, 'start_status' => $startStatus, 'end_status' => $endStatus];
                        $start[$part] ??= ['location' => $from, 'status' => $startStatus, 'item' => $key, 'moment' => 'start'];
                        $beats[$beat][$part] ??= [['location' => $from, 'status' => $startStatus, 'item' => $key, 'moment' => 'start']];
                        $beats[$beat][$part][] = ['location' => $to, 'status' => $endStatus, 'item' => $key, 'moment' => 'end'];
                        $last[$part] = ['state' => $state, 'location' => $to, 'status' => $endStatus, 'beat' => $beat, 'item' => $key];
                    }

                    if (in_array($opening, $design['finished'], true) && $to !== self::ON) {
                        $issues[] = Finding::requirement('MotoWork.trace.5', "{$label}: {$state} là xe hoàn thiện — part không được kết thúc ngoài xe.");
                    }

                    $items[$key] = ['beat' => $beat, 'part' => (string) $item['part'], 'operation' => (string) $item['operation'], 'parts' => $parts];
                }
            }

            $held[$sceneId] = ['start' => $start, 'end' => self::heldNow($last, $sceneState, $order[count($order) - 1] ?? null, true, $states, $spans, $on, $design)];
        }

        return ['issues' => $ids, 'contradictions' => $issues, 'items' => $items, 'beats' => $beats, 'scenes' => $held];
    }

    /**
     * @param  list<array{id: string, work: list<string>, beats: list<string>, done_before?: list<string>, done_after?: list<string>}>  $shots  the shots in screen order, each with the work items it shows and the beats it covers and, in a plan that leaves work between its shots, the work items done outside the picture before its first frame and, for the last shot of a scene, after it
     * @param  array<string, mixed>  $work  trace() of the scene plan
     * @return array{issues: list<string>, shots: array<string, array<string, array{location: string, status: string, item: string, moment: string}>>} work items shown or done outside the picture out of the scene plan's order, within the shot or after a later item an earlier shot reached, and work done before a shot that belongs to a beat after the one the shot opens in; and for every shot, where each part the work holds is at its first frame: at the start of the first item the shot shows on it in its first beat, otherwise where the work shown or done outside the picture so far left it in that beat
     */
    public static function shotPlaces(array $shots, array $work): array
    {
        $order = array_flip(array_keys((array) $work['items']));
        $beatOrder = array_flip(array_keys((array) ($work['beats'] ?? [])));
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
        $done = static function (string $shotId, array $keys, ?string $opens) use (&$current, &$reached, &$issues, $order, $beatOrder, $work, $enter): void {
            $keys = array_values(array_filter($keys, static fn (string $key): bool => isset($order[$key])));
            usort($keys, static fn (string $a, string $b): int => $order[$a] <=> $order[$b]);

            foreach ($keys as $key) {
                $beat = (string) $work['items'][$key]['beat'];

                if ($reached !== null && $order[$key] < $order[$reached]) {
                    $issues[] = Finding::requirement('MotoWork.shotPlaces.1', "{$shotId}: {$key} khai làm ngoài hình sau {$reached} — work phải theo thứ tự của bản chia scene, kể cả phần lược giữa các shot.");
                }

                if ($opens !== null && isset($beatOrder[$beat], $beatOrder[$opens]) && $beatOrder[$beat] > $beatOrder[$opens]) {
                    $issues[] = Finding::requirement('MotoWork.shotPlaces.2', "{$shotId}: {$key} thuộc {$beat}, sau beat {$opens} mà shot mở đầu — công việc chưa tới không thể đã xong trước shot.");
                }

                $reached = $reached === null || $order[$key] > $order[$reached] ? $key : $reached;
                $enter($beat);

                foreach ($work['items'][$key]['parts'] as $part => $place) {
                    $current[$part] = ['location' => $place['end'], 'status' => $place['end_status'], 'item' => $key, 'moment' => 'end'];
                }
            }
        };

        foreach ($shots as $shot) {
            $done((string) $shot['id'], (array) ($shot['done_before'] ?? []), $shot['beats'][0] ?? null);
            $shown = array_values(array_filter($shot['work'], static fn (string $key): bool => isset($order[$key])));

            foreach ($shown as $key) {
                if ($reached !== null && $order[$key] < $order[$reached]) {
                    $issues[] = Finding::requirement('MotoWork.shotPlaces.3', "{$shot['id']}: {$key} thể hiện sau {$reached} — work phải theo thứ tự của bản chia scene, trong một shot và giữa các shot.");
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

            $done((string) $shot['id'], (array) ($shot['done_after'] ?? []), null);
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
                $issues[] = Finding::requirement('MotoWork.detachedIssues.1', "{$label}.detached_parts là null nhưng work để {$part} ngoài xe ({$place['status']}) — phải liệt kê.");
            } elseif ($place['location'] === self::OFF && $given !== $place['status']) {
                $issues[] = Finding::requirement('MotoWork.detachedIssues.2', "{$label}.detached_parts: work để {$part} ngoài xe — cần {$part} {$place['status']}".($given === null ? '' : ", không phải {$given}").'.');
            } elseif ($place['location'] === self::ON && $given !== null) {
                $issues[] = Finding::requirement('MotoWork.detachedIssues.3', "{$label}.detached_parts nêu {$part} ({$given}) nhưng work để nó trên xe lúc này.");
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
     * @param  array<string, array<string, mixed>>  $states  the facts of the physical states, by id
     * @param  ?array<string, array{stage: ?string, checkpoints: list<string>}>  $spans  MotoDesignStates::workSpans(); null for a legacy design
     * @return ?array<string, mixed> the facts the work of a beat is read against: those of its build state in a legacy design; in a v3 design those of the checkpoints it runs through, a part present or being made at one of them counting as such for the beat; null when it has no physical state
     */
    private static function factsAt(?string $beat, ?string $state, array $states, ?array $spans): ?array
    {
        if ($spans === null) {
            return is_string($state) ? ($states[$state] ?? null) : null;
        }

        $facts = array_values(array_filter(array_map(static fn (string $checkpoint): mixed => $states[$checkpoint] ?? null, (array) ($spans[(string) $beat]['checkpoints'] ?? [])), 'is_array'));

        if ($facts === [] || ! isset($facts[0]['present'])) {
            return $facts[0] ?? null;
        }

        return [
            'physical' => array_filter($facts, static fn (array $fact): bool => ($fact['physical'] ?? true) !== false) !== [],
            'present' => array_values(array_unique(array_merge([], ...array_map(static fn (array $fact): array => (array) $fact['present'], $facts)))),
            'in_progress' => array_values(array_unique(array_merge([], ...array_map(static fn (array $fact): array => (array) ($fact['in_progress'] ?? []), $facts)))),
        ];
    }

    /**
     * @param  ?array<string, array{stage: ?string, checkpoints: list<string>}>  $spans  MotoDesignStates::workSpans(); null for a legacy design
     * @return ?string the state a beat opens on: its build state in a legacy design, its first checkpoint in a v3 design
     */
    private static function openingAt(?string $beat, ?string $state, ?array $spans): ?string
    {
        return $spans === null ? $state : ($spans[(string) $beat]['checkpoints'][0] ?? $state);
    }

    /**
     * @param  ?array<string, array{stage: ?string, checkpoints: list<string>}>  $spans  MotoDesignStates::workSpans(); null for a legacy design
     * @return ?string the state a beat ends on: its build state in a legacy design, its last checkpoint in a v3 design
     */
    private static function closingAt(?string $beat, ?string $state, ?array $spans): ?string
    {
        $checkpoints = $spans === null ? [] : (array) ($spans[(string) $beat]['checkpoints'] ?? []);

        return $checkpoints === [] ? $state : (string) $checkpoints[count($checkpoints) - 1];
    }

    /**
     * @param  array<string, array<string, mixed>>  $states  the facts of the physical states, by id
     * @param  ?array<string, array{stage: ?string, checkpoints: list<string>}>  $spans  MotoDesignStates::workSpans(); null for a legacy design
     * @param  list<array<string, mixed>>  $work  the work of the scene, in order
     * @return ?array{start: \Closure(string): bool, end: \Closure(string): bool} for a work item of a v3 design, whether a part is being made where the item starts and where it ends. The make items of the beat on the part are the evidence of its making, wherever the checkpoints between the ends of the beat put it: from its first make item through its last it is being made; before them it stands as the checkpoint the beat opens on has it, after them as the one the beat ends on has it. With no make item, it keeps a state both ends share, otherwise it is complete where the work reaches it. Nothing is set back to the checkpoint the beat opened on, and two equal ends never hide a making between them. The span's facts decide whether the work may be done; these say how the part stands. null for a legacy design, read at its build state
     */
    private static function instants(?string $beat, ?string $state, array $states, ?array $spans, array $work, int $index): ?array
    {
        if ($spans === null) {
            return null;
        }

        $opens = (array) ($states[(string) self::openingAt($beat, $state, $spans)] ?? []);
        $closes = (array) ($states[(string) self::closingAt($beat, $state, $spans)] ?? []);
        $making = static fn (array $facts, string $part): bool => in_array($part, (array) ($facts['in_progress'] ?? []), true);
        // The parts some checkpoint of the beat makes: a make item is evidence of making only for those. A part worked with it (with) is not made by it on that account, and neither is one no checkpoint makes.
        $made = array_merge([], ...array_map(static fn (string $checkpoint): array => array_map('strval', (array) ($states[$checkpoint]['in_progress'] ?? [])), (array) ($spans[(string) $beat]['checkpoints'] ?? [])));
        // The make items of the beat on each part a checkpoint makes, by their place in the scene's work.
        $makes = [];

        foreach ($work as $at => $other) {
            if (($other['beat'] ?? null) === $beat && ($other['operation'] ?? null) === 'make') {
                foreach ([(string) $other['part'], ...array_map('strval', (array) ($other['with'] ?? []))] as $part) {
                    if (in_array($part, $made, true)) {
                        $makes[$part][] = (int) $at;
                    }
                }
            }
        }

        $now = static function (string $part) use ($opens, $closes, $making, $makes, $index): bool {
            $from = $making($opens, $part);
            $to = $making($closes, $part);
            $made = $makes[$part] ?? [];

            if ($made === []) {
                // No make item says when it is made: it keeps a state both ends share, otherwise it is complete wherever the work reaches it.
                return $from === $to && $from;
            }

            // The make items are the making, wherever the checkpoints between the ends put it: before the first the part stands as the beat opens, after the last as it ends.
            return match (true) {
                $index < min($made) => $from,
                $index <= max($made) => true,
                default => $to,
            };
        };

        return ['start' => $now, 'end' => $now];
    }

    /**
     * @param  array<string, array{location: string, status: string, state: string, beat: string, item: string}>  $last
     * @param  array<string, array<string, mixed>>  $states
     * @param  ?array<string, array{stage: ?string, checkpoints: list<string>}>  $spans
     * @param  array<string, bool>  $on
     * @return array<string, array{location: string, status: string, item: string, moment: string}> heldAt() for trace(): as it is for a legacy design; for a v3 design restated at the checkpoint the beat opens on, or ends on at the end of a scene
     */
    private static function heldNow(array $last, ?string $state, ?string $beat, bool $end, array $states, ?array $spans, array $on, array $design): array
    {
        $held = self::heldAt($last, $state);

        if ($spans === null) {
            return $held;
        }

        $at = $end ? self::closingAt($beat, $state, $spans) : self::openingAt($beat, $state, $spans);

        return is_string($at) ? self::restated($held, $states[$at] ?? null, $on, $at, $design) : $held;
    }

    /**
     * @param  array<string, array{location: string, status: string, item: string, moment: string}>  $held  where the work holds each part, as heldAt() gives it
     * @param  ?array<string, mixed>  $facts  the facts of the checkpoint of that instant
     * @param  array<string, bool>  $on  whether each part the work handled has been on the motorcycle
     * @return array<string, array{location: string, status: string, item: string, moment: string}> for a v3 design, each part where the work left it, standing as the checkpoint of the instant has it: a checkpoint is the state established there, so a part the work left being made is complete once a checkpoint says so
     */
    private static function restated(array $held, ?array $facts, array $on, string $at, array $design): array
    {
        if (! is_array($facts)) {
            return $held;
        }

        foreach ($held as $part => $place) {
            $held[$part]['status'] = self::status((string) $place['location'], in_array((string) $part, (array) ($facts['in_progress'] ?? []), true), self::wasOn($on, (string) $part, $at, $design));
        }

        return $held;
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
        return array_key_exists($part, $on) ? $on[$part] : self::earlier($design['first_states'][$part] ?? null, $state, isset($design['order']) ? (array) $design['order'] : null);
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
            $issues[] = Finding::requirement('MotoWork.basisIssues.1', "{$label}: {$part} không có trong in_progress của {$state} — part đã làm xong thì không ghi make.");
        }

        if ($item['operation'] !== 'make' && ! $present($part) && ! $making($part)) {
            $issues[] = Finding::requirement('MotoWork.basisIssues.2', "{$label}: {$part} chưa có ở {$state} — không có công việc trên nó.");
        } elseif ($item['operation'] === 'install' && ! $present($part)) {
            $issues[] = Finding::requirement('MotoWork.basisIssues.3', "{$label}: {$part} đang chế tạo ở {$state} — install chỉ lắp part mà build state ghi đã có trên xe; part đang chế tạo thì thử lắp bằng test_fit và lắp lại bằng reinstall.");
        }

        foreach ((array) ($item['with'] ?? []) as $other) {
            if (! $present((string) $other) && ! $making((string) $other)) {
                $issues[] = Finding::requirement('MotoWork.basisIssues.4', "{$label}: {$other} chưa có ở {$state}.");
            } elseif ($item['operation'] === 'install' && ! $present((string) $other)) {
                $issues[] = Finding::requirement('MotoWork.basisIssues.5', "{$label}: {$other} đang chế tạo ở {$state} — không install cùng được.");
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  bool  $wasOn  whether the part has been on the motorcycle before this work
     * @return list<string> an operation that is not supported, a move the operation does not make, a first fitting of a part that was on and a refitting of one never on
     */
    private static function moveIssues(string $label, array $item, bool $wasOn): array
    {
        $issues = [];
        $move = [(string) $item['location_start'], (string) $item['location_end']];
        $operation = (string) $item['operation'];

        if (! in_array($operation, self::OPERATIONS, true)) {
            return [Finding::requirement('MotoWork.moveIssues.operation', "{$label}: {$operation} không phải thao tác được hỗ trợ — chọn một trong ".implode(', ', self::OPERATIONS).'.')];
        }

        if ($operation === 'test_fit' && $move !== [self::OFF, self::ON]) {
            $issues[] = Finding::requirement('MotoWork.moveIssues.1', "{$label}: test_fit nghĩa là đặt thử chi tiết lên xe: cần off_motorcycle → on_motorcycle. Nếu chỉ cầm tới đối chiếu, dùng align_check.");
        } elseif (in_array($operation, self::FITTING, true) && $move !== [self::OFF, self::ON]) {
            $issues[] = Finding::requirement('MotoWork.moveIssues.2', "{$label}: {$operation} đưa part từ ngoài xe lên xe — cần off_motorcycle → on_motorcycle.");
        }

        if ($operation === self::ALIGN_CHECK && $move !== [self::OFF, self::OFF]) {
            $issues[] = Finding::requirement('MotoWork.moveIssues.3', "{$label}: align_check chỉ đối chiếu khi chi tiết còn ngoài xe: cần off_motorcycle → off_motorcycle.");
        }

        if ($operation === 'remove' && $move !== [self::ON, self::OFF]) {
            $issues[] = Finding::requirement('MotoWork.moveIssues.4', "{$label}: remove đưa part từ trên xe ra ngoài — cần on_motorcycle → off_motorcycle.");
        }

        if (in_array($operation, self::IN_PLACE, true) && $move[0] !== $move[1]) {
            $issues[] = Finding::requirement('MotoWork.moveIssues.5', "{$label}: {$operation} không đổi vị trí part — đầu và cuối phải giống nhau.");
        }

        if (in_array($operation, self::FIRST_FITTING, true) && $wasOn) {
            $issues[] = Finding::requirement('MotoWork.moveIssues.6', "{$label}: {$item['part']} đã từng ở trên xe — lắp lại là reinstall.");
        }

        if ($operation === 'reinstall' && ! $wasOn) {
            $issues[] = Finding::requirement('MotoWork.moveIssues.7', "{$label}: {$item['part']} chưa từng ở trên xe — không reinstall.");
        }

        return $issues;
    }

    /** @param  ?array<string, int>  $order  the place of each checkpoint of a v3 design (MotoScenes::workDesign()); null for a legacy design, placed by stage number */
    private static function earlier(?string $first, string $state, ?array $order = null): bool
    {
        if ($order !== null) {
            return is_string($first) && isset($order[$first], $order[$state]) && $order[$first] < $order[$state];
        }

        return is_string($first) && (int) substr($first, 2) < (int) substr($state, 2);
    }
}
