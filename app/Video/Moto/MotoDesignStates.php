<?php

declare(strict_types=1);

namespace App\Video\Moto;

/**
 * The build states of a pinned moto design, read by the layout its format names.
 *
 * A design of moto-design-v1 or moto-design-v2 tells twelve stages, and each physical stage, BS06–BS12, is
 * one state of the motorcycle. A design of moto-design-v3 tells seven stages, and each checkpoint of a
 * physical stage, BS03–BS07, is one state of the motorcycle: the state reached at that point of the stage.
 *
 * normalize() turns a pinned answer into the data every later step reads. A legacy answer gives its data as
 * it is, so nothing derived from it changes. A v3 answer gives its design stages followed by one record per
 * checkpoint, each carrying the story fields of its stage and the stage id, together with the original stages
 * and a marker naming the layout. The layout is always read from that marker, which only normalize() writes
 * from the format the answer was pinned with — never guessed from how many stages or which keys the data has.
 */
final class MotoDesignStates
{
    /** @var string the key normalize() writes the layout under */
    public const LAYOUT_KEY = 'state_layout';

    /** @var string the key normalize() keeps the stages of a v3 design under, as the design wrote them */
    public const STAGES_KEY = 'stages';

    public const LEGACY = 'legacy';

    public const CHECKPOINTS = MotoContract::DESIGN_FORMAT_V3;

    /** @var list<string> the physical states of a legacy design, in order */
    public const LEGACY_PHYSICAL = ['BS06', 'BS07', 'BS08', 'BS09', 'BS10', 'BS11', 'BS12'];

    /** @var array<string, array{0: string, 1: string}> the stages each act of the main progress may show in a legacy design */
    private const LEGACY_ACTS = ['idea' => ['BS01', 'BS05'], 'fabrication' => ['BS06', 'BS10'], 'finish' => ['BS09', 'BS11'], 'experience' => ['BS11', 'BS12'], 'hero' => ['BS11', 'BS12']];

    /** @var array<string, string> the seven stages of a v3 design and their kinds, in order */
    public const STAGES = ['BS01' => 'model', 'BS02' => 'handover', 'BS03' => 'build', 'BS04' => 'build', 'BS05' => 'build', 'BS06' => 'finished', 'BS07' => 'finished'];

    /** @var list<string> the kinds of a stage on the real motorcycle */
    public const PHYSICAL_KINDS = ['build', 'finished'];

    private const CHECKPOINT = '/^(BS0[3-7])-CP(?:0[1-9]|[1-9]\d)$/';

    /** @var list<string> the fields of a checkpoint, in the order a flat record lists them after its stage's story fields */
    private const CHECKPOINT_FIELDS = ['label', 'surfaces', 'in_progress', 'exceptions', 'temporary', 'surface'];

    /** @var list<string> the story fields of a stage */
    private const STORY_FIELDS = ['text', 'refs', 'setting_brief', 'participant_roles', 'outcome'];

    /**
     * @param  list<array<string, mixed>>  $stages  the stages, each physical one with its checkpoints (one per state for a legacy design)
     * @param  array<string, array<string, mixed>>  $records  each physical state => its record
     */
    private function __construct(
        private readonly string $layout,
        private readonly array $stages,
        private readonly array $records,
    ) {}

    /**
     * @param  array<string, mixed>  $answer  a pinned design answer: format and data
     * @return array<string, mixed> the data later steps read: the data of a legacy answer as it is; for a v3 answer, the flat states with the stages and the layout marker
     */
    public static function normalize(array $answer): array
    {
        $data = (array) ($answer['data'] ?? []);

        if (($answer['format'] ?? null) !== self::CHECKPOINTS) {
            return $data;
        }

        $flat = [];

        foreach ((array) ($data['build_states'] ?? []) as $stage) {
            if (! is_array($stage)) {
                continue;
            }

            // A stage is physical by its kind, never by whether it lists checkpoints: a physical stage with none is a broken layout, which layoutProblems() reports.
            if (! in_array($stage['kind'] ?? null, self::PHYSICAL_KINDS, true)) {
                $flat[] = $stage;

                continue;
            }

            foreach ((array) ($stage['checkpoints'] ?? []) as $checkpoint) {
                if (! is_array($checkpoint)) {
                    continue;
                }

                $record = ['id' => $checkpoint['id'] ?? null, 'kind' => $stage['kind'] ?? null, 'stage' => $stage['id'] ?? null];

                foreach (self::STORY_FIELDS as $field) {
                    $record[$field] = $stage[$field] ?? null;
                }

                foreach (self::CHECKPOINT_FIELDS as $field) {
                    $record[$field] = $checkpoint[$field] ?? null;
                }

                $flat[] = $record;
            }
        }

        $data[self::STAGES_KEY] = $data['build_states'] ?? [];
        $data['build_states'] = $flat;
        $data[self::LAYOUT_KEY] = self::CHECKPOINTS;

        return $data;
    }

    /** @param  array<string, mixed>  $design  data normalize() gave, or a selection of it that keeps the layout marker and the stages */
    public static function of(array $design): self
    {
        $states = array_values(array_filter((array) ($design['build_states'] ?? []), 'is_array'));

        if (($design[self::LAYOUT_KEY] ?? null) === self::CHECKPOINTS) {
            $records = [];

            foreach ($states as $state) {
                if (isset($state['stage']) && is_string($state['id'] ?? null)) {
                    $records[$state['id']] = $state;
                }
            }

            return new self(self::CHECKPOINTS, array_values(array_filter((array) ($design[self::STAGES_KEY] ?? []), 'is_array')), $records);
        }

        $byId = array_column($states, null, 'id');
        $records = [];
        $stages = [];

        foreach (self::LEGACY_PHYSICAL as $id) {
            $records[$id] = (array) ($byId[$id] ?? ['id' => $id]);
        }

        foreach ($states as $state) {
            $stages[] = $state;
        }

        return new self(self::LEGACY, $stages, $records);
    }

    /** @param  array<string, mixed>  $facts  MotoScriptCheck::facts(), which keeps the layout and the stages of a v3 design */
    public static function ofFacts(array $facts): self
    {
        if (($facts[self::LAYOUT_KEY] ?? null) !== self::CHECKPOINTS) {
            return new self(self::LEGACY, [], array_fill_keys(self::LEGACY_PHYSICAL, []));
        }

        $records = [];

        foreach ((array) ($facts[self::STAGES_KEY] ?? []) as $stage) {
            foreach ((array) ($stage['checkpoints'] ?? []) as $checkpoint) {
                $records[(string) $checkpoint] = ['id' => (string) $checkpoint, 'stage' => (string) $stage['id'], 'kind' => $stage['kind']];
            }
        }

        return new self(self::CHECKPOINTS, array_values((array) ($facts[self::STAGES_KEY] ?? [])), $records);
    }

    public function layout(): string
    {
        return $this->layout;
    }

    /**
     * @return array<string, mixed> what a check context keeps so that ofFacts() reads the same layout again: the marker and each stage with its kind and checkpoints, for a v3 design; nothing for a legacy design, so its contexts stay as they were
     */
    public function summary(): array
    {
        if (! $this->checkpointed()) {
            return [];
        }

        return [
            self::LAYOUT_KEY => self::CHECKPOINTS,
            self::STAGES_KEY => array_map(fn (array $stage): array => [
                'id' => (string) $stage['id'],
                'kind' => $stage['kind'] ?? null,
                'checkpoints' => $this->checkpointsOf((string) $stage['id']),
            ], $this->stages),
        ];
    }

    public function checkpointed(): bool
    {
        return $this->layout === self::CHECKPOINTS;
    }

    /** @return list<string> the physical states, in order: BS06–BS12 for a legacy design, the checkpoint ids for a v3 design */
    public function physical(): array
    {
        return array_keys($this->records);
    }

    /** @return array<string, int> each physical state => its place in the build, increasing; for a legacy design its stage number */
    public function order(): array
    {
        if (! $this->checkpointed()) {
            return array_combine(self::LEGACY_PHYSICAL, range(6, 12));
        }

        $order = [];

        foreach (array_keys($this->records) as $index => $id) {
            $order[$id] = $index + 1;
        }

        return $order;
    }

    /** @return ?int the place of a physical state in the build; null for anything else */
    public function position(mixed $state): ?int
    {
        return is_string($state) ? ($this->order()[$state] ?? null) : null;
    }

    /** @return array<string, array<string, mixed>> each physical state => its record: kind, the story fields, and surfaces, in_progress, exceptions, temporary and surface */
    public function records(): array
    {
        return $this->records;
    }

    /** @return list<array<string, mixed>> the stages, in order */
    public function stages(): array
    {
        return $this->stages;
    }

    /** @return list<string> the stage ids, in order */
    public function stageIds(): array
    {
        return array_values(array_map(static fn (array $stage): string => (string) ($stage['id'] ?? ''), $this->stages));
    }

    /** @return ?string the stage a physical state belongs to; a stage id is its own stage */
    public function stageOf(mixed $state): ?string
    {
        if (! is_string($state)) {
            return null;
        }

        return isset($this->records[$state]) ? (string) ($this->records[$state]['stage'] ?? $state) : (in_array($state, $this->stageIds(), true) ? $state : null);
    }

    /** @return list<string> the physical states of a stage, in order: its checkpoints, or the stage itself in a legacy design; [] for a design stage */
    public function checkpointsOf(string $stage): array
    {
        return array_values(array_filter(array_keys($this->records), fn (string $id): bool => ($this->records[$id]['stage'] ?? $id) === $stage));
    }

    /** @return bool whether the stage is on the real motorcycle */
    /** @return bool whether the stage is on the real motorcycle: by its kind in a v3 design, never by whether it lists checkpoints; BS06–BS12 in a legacy design */
    public function physicalStage(string $stage): bool
    {
        if (! $this->checkpointed()) {
            return $this->checkpointsOf($stage) !== [];
        }

        return in_array(array_column($this->stages, 'kind', 'id')[$stage] ?? null, self::PHYSICAL_KINDS, true);
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows  beats by id, each with its state and, in a v3 design, its checkpoints
     * @return ?array<string, array{stage: ?string, checkpoints: list<string>}> for the work a scene plan follows: each beat with its stage, under which the work keeps a part where it left it, and the checkpoints it runs through, whose facts the work reads; null for a legacy design, whose work reads each beat at its own build state
     */
    public function workSpans(array $rows): ?array
    {
        if (! $this->checkpointed()) {
            return null;
        }

        $spans = [];

        foreach ($rows as $id => $row) {
            $spans[(string) ($row['id'] ?? $id)] = ['stage' => is_string($row['state'] ?? null) ? $row['state'] : null, 'checkpoints' => $this->span($row)];
        }

        return $spans;
    }

    /** @return list<string> the stages whose kind is finished */
    public function finishedStages(): array
    {
        return array_values(array_map(static fn (array $stage): string => (string) $stage['id'], array_filter($this->stages, static fn (array $stage): bool => ($stage['kind'] ?? null) === 'finished')));
    }

    /** @return list<string> the physical states of the finished stages */
    public function finishedStates(): array
    {
        return array_values(array_merge([], ...array_map(fn (string $stage): array => $this->checkpointsOf($stage), $this->finishedStages())));
    }

    /** @return array<string, array{0: string, 1: string}> the first and last stage each act of the main progress may show */
    public function acts(): array
    {
        if (! $this->checkpointed()) {
            return self::LEGACY_ACTS;
        }

        $ids = $this->stageIds();
        $kinds = array_column($this->stages, 'kind', 'id');
        $design = array_values(array_filter($ids, static fn (string $id): bool => ! in_array($kinds[$id] ?? null, self::PHYSICAL_KINDS, true)));
        $build = array_values(array_filter($ids, static fn (string $id): bool => ($kinds[$id] ?? null) === 'build'));
        $finished = array_values(array_filter($ids, static fn (string $id): bool => ($kinds[$id] ?? null) === 'finished'));
        $finish = [...array_slice($build, -2), ...array_slice($finished, 0, 1)];

        return [
            'idea' => [$design[0], $design[count($design) - 1]],
            'fabrication' => [$build[0], $build[count($build) - 1]],
            'finish' => [$finish[0], $finish[count($finish) - 1]],
            'experience' => [$finished[0], $finished[count($finished) - 1]],
            'hero' => [$finished[0], $finished[count($finished) - 1]],
        ];
    }

    /**
     * @param  array<string, mixed>  $row  a beat, scene or shot
     * @return list<string> the physical states it runs through, in order: its own state in a legacy design; from checkpoint_start to checkpoint_end in a v3 design; [] when it holds no physical state
     */
    public function span(array $row): array
    {
        if (! $this->checkpointed()) {
            return is_string($row['state'] ?? null) && isset($this->records[$row['state']]) ? [$row['state']] : [];
        }

        $order = $this->order();
        $start = $order[$row['checkpoint_start'] ?? ''] ?? null;
        $end = $order[$row['checkpoint_end'] ?? ''] ?? null;

        if ($start === null || $end === null || $end < $start) {
            return [];
        }

        return array_values(array_filter(array_keys($order), static fn (string $id): bool => $order[$id] >= $start && $order[$id] <= $end));
    }

    /**
     * @param  array<string, mixed>  $row  a beat, scene or shot
     * @return ?string the physical state it opens on: its own state in a legacy design, checkpoint_start in a v3 design
     */
    public function opening(array $row): ?string
    {
        return $this->checkpointed() ? (is_string($row['checkpoint_start'] ?? null) ? $row['checkpoint_start'] : null) : (is_string($row['state'] ?? null) ? $row['state'] : null);
    }

    /**
     * @param  array<string, mixed>  $row  a beat, scene or shot
     * @return ?string the physical state it ends on: its own state in a legacy design, checkpoint_end in a v3 design
     */
    public function closing(array $row): ?string
    {
        return $this->checkpointed() ? (is_string($row['checkpoint_end'] ?? null) ? $row['checkpoint_end'] : null) : (is_string($row['state'] ?? null) ? $row['state'] : null);
    }

    /**
     * @param  array<string, mixed>  $row  a beat, scene or shot of a run made from a v3 design
     * @param  string  $path  where the row is, for the message
     * @return list<Finding> what is wrong with its checkpoint_start and checkpoint_end: on a design stage or without a stage both are null; on a physical stage both are checkpoints of that stage, the end not before the start. Nothing for a legacy design
     */
    public function rowProblems(array $row, string $path): array
    {
        if (! $this->checkpointed()) {
            return [];
        }

        $stage = is_string($row['state'] ?? null) ? $row['state'] : null;
        $start = $row['checkpoint_start'] ?? null;
        $end = $row['checkpoint_end'] ?? null;

        // The stage is an id of the pinned design before anything is read from it.
        if ($stage !== null && ! in_array($stage, $this->stageIds(), true)) {
            return [Finding::requirement('MotoDesignStates.rowProblems.4', "{$path}.state là {$stage}, không có trong build_states của thiết kế (".implode(', ', $this->stageIds()).').')];
        }

        if ($stage === null || ! $this->physicalStage($stage)) {
            return $start === null && $end === null ? [] : [Finding::requirement('MotoDesignStates.rowProblems.1', "{$path}: ".($stage === null ? 'không có build state' : "{$stage} là bước thiết kế").' — checkpoint_start và checkpoint_end phải là null.')];
        }

        $own = $this->checkpointsOf($stage);
        $problems = [];

        foreach (['checkpoint_start' => $start, 'checkpoint_end' => $end] as $field => $value) {
            if (! is_string($value) || ! in_array($value, $own, true)) {
                $problems[] = Finding::requirement('MotoDesignStates.rowProblems.2', "{$path}.{$field} là ".json_encode($value).", không phải checkpoint của {$stage} (".implode(', ', $own).').');
            }
        }

        if ($problems === [] && $this->order()[$end] < $this->order()[$start]) {
            $problems[] = Finding::requirement('MotoDesignStates.rowProblems.3', "{$path}: checkpoint_end {$end} đứng trước checkpoint_start {$start}.");
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $data  the data of a v3 design answer, as the model wrote it
     * @return list<Finding> what breaks the layout and the references to it: the seven stages in their order with their kinds, the checkpoints of each physical stage with its id prefix and no id twice, and every lifespan, light, feature, marking and identity-rule moment naming a checkpoint (or, for a rule, a design stage) the design has
     */
    public static function layoutProblems(array $data): array
    {
        $problems = [];
        $stages = array_values((array) ($data['build_states'] ?? []));
        $expected = array_keys(self::STAGES);
        $checkpoints = [];

        foreach ($stages as $index => $stage) {
            $id = $stage['id'] ?? null;
            $want = $expected[$index] ?? null;

            if ($want === null) {
                $problems[] = Finding::requirement('MotoDesignStates.layoutProblems.1', "build_states[{$index}]: thiết kế chỉ có ".count($expected).' build state.');

                continue;
            }

            if ($id !== $want || ($stage['kind'] ?? null) !== self::STAGES[$want]) {
                $problems[] = Finding::requirement('MotoDesignStates.layoutProblems.2', "build_states[{$index}] là ".json_encode($id).' kind '.json_encode($stage['kind'] ?? null)."; vị trí này phải là {$want} kind ".self::STAGES[$want].'.');
            }

            $physical = in_array(self::STAGES[$want], self::PHYSICAL_KINDS, true);

            if (! $physical) {
                if (array_key_exists('checkpoints', (array) $stage)) {
                    $problems[] = Finding::requirement('MotoDesignStates.layoutProblems.3', "{$want}: bước thiết kế không có checkpoints.");
                }

                continue;
            }

            // The structure check keeps no count of this list, so a physical stage without a checkpoint is caught here: it would read as no motorcycle at all.
            if (! is_array($stage['checkpoints'] ?? null) || $stage['checkpoints'] === []) {
                $problems[] = Finding::requirement('MotoDesignStates.layoutProblems.8', "{$want}: bước vật lý phải có ít nhất một checkpoint.");
            }

            foreach ((array) ($stage['checkpoints'] ?? []) as $at => $checkpoint) {
                $cp = $checkpoint['id'] ?? null;

                if (! is_string($cp) || preg_match(self::CHECKPOINT, $cp, $match) !== 1 || $match[1] !== $want) {
                    $problems[] = Finding::requirement('MotoDesignStates.layoutProblems.4', "{$want}.checkpoints[{$at}].id là ".json_encode($cp).", không phải checkpoint của {$want} ({$want}-CP01…).");
                } elseif (isset($checkpoints[$cp])) {
                    $problems[] = Finding::requirement('MotoDesignStates.layoutProblems.5', "{$want}.checkpoints[{$at}]: checkpoint {$cp} khai trùng ID.");
                }

                if (is_string($cp)) {
                    $checkpoints[$cp] = true;
                }
            }
        }

        if (count($stages) < count($expected)) {
            $problems[] = Finding::requirement('MotoDesignStates.layoutProblems.6', 'build_states có '.count($stages).' bước; thiết kế phải có đúng '.count($expected).' bước BS01–BS07.');
        }

        $checkpoint = static function (string $path, mixed $value, bool $nullable) use (&$problems, $checkpoints): void {
            if ($value === null && $nullable) {
                return;
            }

            if (! is_string($value) || ! isset($checkpoints[$value])) {
                $problems[] = Finding::requirement('MotoDesignStates.layoutProblems.7', "{$path} là ".json_encode($value).', không phải checkpoint của thiết kế.');
            }
        };

        foreach ((array) ($data['parts'] ?? []) as $part) {
            $id = (string) ($part['id'] ?? '?');
            $checkpoint("{$id}.from_state", $part['from_state'] ?? null, true);
            $checkpoint("{$id}.until_state", $part['until_state'] ?? null, true);

            foreach ((array) ($part['lighting'] ?? []) as $light) {
                $checkpoint(($light['id'] ?? $id).'.installed', $light['installed'] ?? null, false);
                $checkpoint(($light['id'] ?? $id).'.operable', $light['operable'] ?? null, false);
            }
        }

        foreach ((array) ($data['features'] ?? []) as $feature) {
            $checkpoint(($feature['id'] ?? '?').'.from_state', $feature['from_state'] ?? null, true);
        }

        foreach ((array) ($data['motorcycle']['markings'] ?? []) as $index => $marking) {
            $checkpoint("motorcycle.markings[{$index}].from_state", $marking['from_state'] ?? null, false);
        }

        foreach ((array) ($data['identity_rules'] ?? []) as $rule) {
            $from = $rule['from_state'] ?? null;

            if ($from !== null && ! in_array($from, ['BS01', 'BS02'], true)) {
                $checkpoint(($rule['id'] ?? '?').'.from_state', $from, true);
            }
        }

        return $problems;
    }
}
