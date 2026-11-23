<?php

declare(strict_types=1);

namespace App\Video\Moto;

/**
 * The set design of a moto-locations-v4 dossier read by code: the state a set stands in for one plate, the words the
 * image prompt and the later steps take from the structured source, and the data in the shape the later steps read.
 * Every fact has one source — the design of the place, or the use the plate states — and code only joins stored values
 * behind fixed words; it never writes a value the answer does not hold.
 */
final class MotoSetDesign
{
    /** @var list<string> the fields of an object a plate may change, in the order they are told */
    private const STATE_FIELDS = ['present', 'position', 'facing', 'condition'];

    /** @var list<string> the values a light source states by an enumeration or a number that belong to the place and are the same from every camera */
    private const PLACE_LIGHT = ['kind', 'quality', 'intensity', 'tone', 'kelvin'];

    /** @var list<string> the values a light source states by an enumeration, a flag or an id that only one frame can say: the role it plays for that frame, whether that frame shows its fixture and which */
    private const FRAME_LIGHT = ['role', 'in_frame', 'fixture'];

    /** @var list<string> the words of a light source that belong to the place: where it comes from */
    private const PLACE_LIGHT_WORDS = ['position'];

    /** @var list<string> the words of a light source only one frame can say: the direction it falls in as that frame sees it */
    private const FRAME_LIGHT_WORDS = ['direction'];

    /** @var array<string, string> how the cleanliness of a surface is told in a prompt */
    private const CLEANLINESS = ['clean' => 'clean', 'working' => 'marked by daily work', 'dirty' => 'dirty'];

    /** @var array<string, string> how the role of a light source is told in a prompt */
    private const LIGHT_ROLES = ['key' => 'key light', 'fill' => 'fill light', 'back' => 'back light', 'rim' => 'rim light', 'practical' => 'practical light', 'ambient' => 'ambient light'];

    /** @var array<string, string> how the subject zone stands against the rest of the frame, told in a prompt */
    private const EXPOSURE = ['bright' => 'brighter than the rest of the frame', 'mid' => 'at the middle of the range of the frame', 'dark' => 'darker than the rest of the frame'];

    /**
     * @param  array<string, mixed>  $place  a place of a moto-locations-v4 dossier
     * @param  array<string, mixed>  $plate  a plate that stands on it
     * @return array{name: string, objects: list<array<string, mixed>>, architecture: list<array<string, mixed>>, changes: list<array{ref: string, name: string, fields: list<string>, reason: string, beats: list<string>}>, refused: list<array{ref: string, why: string}>} the state the set stands in for the plate: the base state of the place with every valid change of the plate applied, never two descriptions joined by guesswork. An object keeps its base value wherever the change leaves a field null. Its size and where it stands are kept apart: every object carries base_footprint, the floor its base state numbers; an object moved by words keeps that size there but loses its footprint, since no number places it now, and is marked unplaced when its base had numbers — so a check that needs them reports it unverified instead of passing over it. A change the contract does not allow — of something the place does not have, a fixed object moved, turned, added or removed, a part of the architecture moved or removed — and a change that leaves everything as it was are not applied and are listed under refused
     */
    public static function state(array $place, array $plate): array
    {
        $objects = array_column(array_map(static fn (array $object): array => $object + ['changed' => [], 'base_footprint' => $object['footprint'] ?? null, 'unplaced' => false], (array) ($place['set_design']['objects'] ?? [])), null, 'id');
        $architecture = array_column(array_map(static fn (array $part): array => $part + ['state' => null], (array) ($place['set_design']['architecture'] ?? [])), null, 'id');
        $changes = [];
        $refused = [];

        foreach ((array) ($plate['set_state']['changes'] ?? []) as $change) {
            $ref = (string) $change['ref'];
            $why = self::refusal($change, $objects[$ref] ?? null, $architecture[$ref] ?? null);
            $fields = $why !== null || isset($architecture[$ref]) ? [] : array_values(array_filter(self::STATE_FIELDS, static fn (string $field): bool => $change[$field] !== null && $change[$field] !== $objects[$ref][$field]));

            if ($why === null && ! isset($architecture[$ref]) && $fields === []) {
                $why = 'empty';
            }

            if ($why !== null) {
                $refused[] = ['ref' => $ref, 'why' => $why];

                continue;
            }

            if (isset($architecture[$ref])) {
                $architecture[$ref]['state'] = $change['condition'];
                $fields = ['condition'];
            } else {
                foreach ($fields as $field) {
                    $objects[$ref][$field] = $change[$field];
                }

                if (in_array('position', $fields, true)) {
                    $objects[$ref]['footprint'] = null;
                    $objects[$ref]['unplaced'] = $objects[$ref]['base_footprint'] !== null;
                }

                $objects[$ref]['changed'] = $fields;
            }

            $changes[] = ['ref' => $ref, 'name' => (string) ($objects[$ref]['name'] ?? $architecture[$ref]['name']), 'fields' => $fields, 'reason' => (string) $change['reason'], 'beats' => array_values(array_map('strval', (array) $change['beats']))];
        }

        return ['name' => (string) ($plate['set_state']['name'] ?? ''), 'objects' => array_values($objects), 'architecture' => array_values($architecture), 'changes' => $changes, 'refused' => $refused];
    }

    /**
     * @param  array<string, mixed>  $plan  the lighting plan of a plate
     * @param  bool  $frame  the two plates compared are one frame; false when their cameras differ
     * @return array{sources: list<array<string, mixed>>, subject_exposure?: mixed} what of the light two plates are held to: the values a plate states by an enumeration, a number, a flag or an id. Of every source, what belongs to the place and is the same from every camera: its kind, quality, strength, tone and colour temperature — and so how many sources there are. For two plates of one frame also what only a frame can say: the role a source plays for it, whether the frame shows its fixture and which, and how its subject stands against the rest of the frame; from another camera those differ although the light is the same, so they tell nothing of two frames. The words of the plan — where a source comes from, the direction it falls in, the bright and dark areas — are compared apart by wording(), since code cannot tell whether two wordings say one thing. Every item is brought to one form first (comparable()) and each list is then put in one order, so neither the order a plate writes the keys of an item in nor the order it lists the items in tells two plates apart; the id a plate numbers a source by, its basis and the evidence or reason of the plan are left out
     */
    public static function look(array $plan, bool $frame = true): array
    {
        $sources = array_map(static fn (array $source): array => $source['values'], self::sources($plan, $frame));

        return $frame ? ['sources' => $sources, 'subject_exposure' => $plan['subject_exposure'] ?? null] : ['sources' => $sources];
    }

    /**
     * @param  array<string, mixed>  $one  the lighting plan of a plate
     * @param  array<string, mixed>  $other  the lighting plan of another plate whose look() is the same
     * @param  bool  $frame  the two plates are one frame
     * @return list<array{0: string, 1: mixed, 2: string, 3: mixed}> every wording of the light the two plates state differently, each as the path inside the first plan, its words, the path inside the other plan and its words: where a source comes from, and for two plates of one frame also the direction a source falls in and the bright and dark areas. Sources are paired by their values (look()) and then by their words, since a plate numbers its sources by ids of its own; words are compared as comparable() reads them. Code only reports that the wordings differ, never whether they say the same
     */
    public static function wording(array $one, array $other, bool $frame): array
    {
        $differ = [];
        $mine = self::sources($one, $frame);
        $theirs = self::sources($other, $frame);

        foreach ($mine as $index => $source) {
            $match = $theirs[$index] ?? null;

            foreach ([...self::PLACE_LIGHT_WORDS, ...($frame ? self::FRAME_LIGHT_WORDS : [])] as $field) {
                if ($match !== null && self::comparable($source['raw'][$field] ?? null) !== self::comparable($match['raw'][$field] ?? null)) {
                    $differ[] = ["sources.{$source['raw']['id']}.{$field}", $source['raw'][$field] ?? null, "sources.{$match['raw']['id']}.{$field}", $match['raw'][$field] ?? null];
                }
            }
        }

        foreach ($frame ? ['bright_areas', 'dark_areas'] : [] as $field) {
            $areas = static function (array $plan) use ($field): array {
                $list = array_map(self::comparable(...), array_values((array) ($plan[$field] ?? [])));
                usort($list, static fn (mixed $a, mixed $b): int => strcmp((string) json_encode($a), (string) json_encode($b)));

                return $list;
            };

            if ($areas($one) !== $areas($other)) {
                $differ[] = [$field, array_values((array) ($one[$field] ?? [])), $field, array_values((array) ($other[$field] ?? []))];
            }
        }

        return $differ;
    }

    /**
     * @param  array<string, mixed>  $plan  the lighting plan of a plate
     * @param  bool  $frame  the values a frame states are compared too
     * @return list<array{values: array<string, mixed>, raw: array<string, mixed>}> every source of the plan with the values look() compares it by, in one order: by those values, then by its words, so that two plans with the same look pair their sources one by one
     */
    private static function sources(array $plan, bool $frame): array
    {
        $fields = array_flip($frame ? [...self::PLACE_LIGHT, ...self::FRAME_LIGHT] : self::PLACE_LIGHT);
        $words = [...self::PLACE_LIGHT_WORDS, ...($frame ? self::FRAME_LIGHT_WORDS : [])];
        $sources = array_map(static fn (array $source): array => ['values' => self::comparable(array_intersect_key($source, $fields)), 'raw' => $source], array_values(array_filter((array) ($plan['sources'] ?? []), 'is_array')));
        usort($sources, static fn (array $a, array $b): int => strcmp((string) json_encode($a['values']), (string) json_encode($b['values']))
            ?: strcmp((string) json_encode(self::comparable(array_intersect_key($a['raw'], array_flip($words)))), (string) json_encode(self::comparable(array_intersect_key($b['raw'], array_flip($words))))));

        return $sources;
    }

    /**
     * @param  mixed  $value  a value of an answer
     * @return mixed the same value in the one form two stated values are compared in: the keys of every object in one order at every depth, a list in the order it was given, a whole number as a float, and words with their spaces run together into one, none at either end and no full stop closing them — so two plates that state the same thing are not told apart by how it was typed
     */
    public static function comparable(mixed $value): mixed
    {
        if (is_int($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            return rtrim(rtrim(trim((string) preg_replace('/\s+/u', ' ', $value)), '.'));
        }

        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::comparable(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $change  one change of a set state
     * @param  ?array<string, mixed>  $object  the object it names, null when it names none
     * @param  ?array<string, mixed>  $part  the part of the architecture it names, null when it names none
     * @return ?string why the change is not applied, null when the contract allows it
     */
    private static function refusal(array $change, ?array $object, ?array $part): ?string
    {
        $moves = $change['position'] !== null || $change['facing'] !== null;

        return match (true) {
            $object === null && $part === null => 'unknown',
            $part !== null && ($change['present'] !== null || $moves) => 'architecture',
            $part !== null && $change['condition'] === null => 'empty',
            $object !== null && ($object['fixed'] ?? false) && ($moves || $change['present'] !== null) => 'fixed',
            $object !== null && $change['present'] === null && ! $moves && $change['condition'] === null => 'empty',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $state  state()
     * @return list<string> the temporary items of the design the set holds for the plate: the support_kind of every object present in that state that names one
     */
    public static function support(array $state): array
    {
        return array_values(array_unique(array_map('strval', array_filter(array_column(array_filter($state['objects'], static fn (array $object): bool => (bool) $object['present']), 'support_kind'), 'is_string'))));
    }

    /**
     * @param  array<string, mixed>  $place
     * @param  array<string, mixed>  $plate
     * @param  bool  $landmarksOnly  only what the place marks as a landmark
     * @return list<string> the names of the parts of the architecture and the objects the frame of the plate shows, in the order the plate lists them; a ref the place does not have, and an object that does not stand in the place in the state of this plate, are left out
     */
    public static function shown(array $place, array $plate, bool $landmarksOnly = false): array
    {
        $parts = array_column((array) ($place['set_design']['architecture'] ?? []), null, 'id');
        $objects = array_column(array_filter(self::state($place, $plate)['objects'], static fn (array $object): bool => (bool) $object['present']), null, 'id');
        $names = [];

        foreach ((array) ($plate['visible'] ?? []) as $ref) {
            $item = $parts[$ref] ?? $objects[$ref] ?? null;
            $landmark = isset($parts[$ref]) ? (bool) $parts[$ref]['landmark'] : ($objects[$ref]['role'] ?? null) === 'landmark';

            if ($item !== null && (! $landmarksOnly || $landmark)) {
                $names[] = (string) $item['name'];
            }
        }

        return $names;
    }

    /**
     * @param  array<string, mixed>  $place  a place of a moto-locations-v4 dossier
     * @return list<string> the names of what tells one direction of the place from another: the parts of its architecture marked as a landmark, then its objects whose role is landmark
     */
    public static function landmarks(array $place): array
    {
        return array_values(array_map('strval', [
            ...array_column(array_filter((array) ($place['set_design']['architecture'] ?? []), static fn (array $part): bool => (bool) $part['landmark']), 'name'),
            ...array_column(array_filter((array) ($place['set_design']['objects'] ?? []), static fn (array $object): bool => $object['role'] === 'landmark'), 'name'),
        ]));
    }

    /**
     * @param  array<string, mixed>  $state  state()
     * @return string the surfaces of the place as the plate shows them, each part of the architecture once: its name, material, own colour, finish, wear, cleanliness, what its surface shows and what the plate changes of it
     */
    public static function surfaces(array $state): string
    {
        return implode('; ', array_map(static fn (array $part): string => implode(', ', array_filter([
            $part['name'].': '.self::plain($part['material']),
            self::plain($part['colour']),
            $part['finish'] === null ? null : "{$part['finish']} finish",
            (string) $part['wear'],
            self::CLEANLINESS[$part['cleanliness']] ?? (string) $part['cleanliness'],
            self::plain($part['surface']),
            $part['state'] === null ? null : 'for this frame '.self::plain($part['state']),
        ], 'is_string')), $state['architecture']));
    }

    /**
     * @param  array<string, mixed>  $state  state()
     * @param  bool  $support  the objects that hold the subject; otherwise every other object
     * @return string the objects the set holds for the plate, each with where it stands, which way it faces, its material, what makes it recognisable and its condition — what keeps one object the same object from one frame to another
     */
    public static function objects(array $state, bool $support): string
    {
        $present = array_filter($state['objects'], static fn (array $object): bool => (bool) $object['present'] && (($object['role'] === 'support') === $support));

        return implode('; ', array_map(static fn (array $object): string => implode(', ', array_filter([
            $object['name'].' — '.self::plain($object['position']),
            $object['facing'] === null ? null : 'facing '.self::plain($object['facing']),
            self::plain($object['material']),
            self::plain($object['identity']),
            self::plain($object['condition']),
        ], 'is_string')), $present));
    }

    /**
     * @param  array<string, mixed>  $state  state()
     * @return string what differs from the base state of the place, each change once with what it changes
     */
    public static function changes(array $state): string
    {
        return implode('; ', self::changeLines($state));
    }

    /**
     * @param  array<string, mixed>  $state  state()
     * @return list<string> one line for each applied change: the name of what changed and what the plate changes of it
     */
    public static function changeLines(array $state): array
    {
        $objects = array_column($state['objects'], null, 'id');
        $parts = array_column($state['architecture'], null, 'id');
        $lines = [];

        foreach ($state['changes'] as $change) {
            $ref = $change['ref'];
            $told = [];

            foreach ($change['fields'] as $field) {
                $told[] = match ($field) {
                    'present' => ($objects[$ref]['present'] ?? false) ? 'added' : 'not there',
                    'position' => 'moved to '.self::plain($objects[$ref]['position']),
                    'facing' => 'turned to face '.self::plain($objects[$ref]['facing']),
                    default => (string) self::plain($objects[$ref]['condition'] ?? $parts[$ref]['state']),
                };
            }

            if ($told !== []) {
                $lines[] = "{$change['name']}: ".implode(', ', $told);
            }
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $camera  the camera of a plate
     * @return string where the camera of the still frame stands, how high, with which lens and what it looks at, from the structured values alone; a value not chosen is left out
     */
    public static function camera(array $camera): string
    {
        return implode(', ', array_filter([
            self::plain($camera['position']),
            is_numeric($camera['height_m']) ? self::number($camera['height_m']).' m above the floor' : null,
            is_numeric($camera['lens_mm']) ? self::number($camera['lens_mm']).' mm lens' : null,
            'looking '.self::plain($camera['look_at']['description']),
        ], 'is_string'));
    }

    /**
     * @param  array<string, mixed>  $plan  the lighting plan of a plate
     * @return array{sources: string, hidden: string, bright: string, dark: string, exposure: string, direction: ?string, quality: ?string} the light of the plate in words: every source with its role, where it comes from, how it falls, its strength against the others and its tone (a colour temperature only when stated); where the sources stand that the frame must not show; the bright and the dark areas with what they do; how the subject zone stands against the frame; and the direction and quality of the key light, the first source when none is named key
     */
    public static function light(array $plan): array
    {
        $sources = array_values((array) ($plan['sources'] ?? []));
        $key = collect($sources)->firstWhere('role', 'key') ?? ($sources[0] ?? null);
        $areas = static fn (array $list): string => implode('; ', array_map(static fn (array $area): string => self::plain($area['area']).' — '.self::plain($area['purpose']), $list));

        return [
            'sources' => implode('; ', array_map(static fn (array $source): string => implode(', ', array_filter([
                (self::LIGHT_ROLES[$source['role']] ?? (string) $source['role']).": {$source['kind']} from ".self::plain($source['position']),
                'falling '.self::plain($source['direction']),
                (string) $source['quality'],
                "{$source['intensity']} strength",
                "{$source['tone']} tone",
                is_numeric($source['kelvin']) ? self::number($source['kelvin']).' K' : null,
            ], 'is_string')), $sources)),
            'hidden' => implode('; ', array_map(static fn (array $source): string => (string) self::plain($source['position']), array_filter($sources, static fn (array $source): bool => ! $source['in_frame']))),
            'bright' => $areas((array) ($plan['bright_areas'] ?? [])),
            'dark' => $areas((array) ($plan['dark_areas'] ?? [])),
            'exposure' => self::EXPOSURE[$plan['subject_exposure'] ?? ''] ?? '',
            'direction' => $key === null ? null : (string) $key['direction'],
            'quality' => $key === null ? null : (string) $key['quality'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  the data of a moto-locations-v4 dossier that passed its schema
     * @return array<string, mixed> the same data as the steps written for an earlier dossier read it: every plate keeps its structured fields and gains, made by code from them, the light as one description with the direction and quality of its key light and the evidence of the plan, the temporary items its set holds (support), the landmarks its frame shows and the state its set stands in (set). Nothing is added that the answer does not hold; a plate on a place the dossier does not have is left as it is
     */
    public static function normalize(array $data): array
    {
        $places = array_column((array) ($data['locations'] ?? []), null, 'id');
        $data['backgrounds'] = array_values(array_map(static function (array $plate) use ($places): array {
            $place = $places[$plate['location']] ?? null;
            $state = $place === null ? null : self::state($place, $plate);
            $light = self::light((array) $plate['lighting_plan']);

            return $plate + [
                'light' => ['description' => $light['sources'], 'direction' => $light['direction'], 'quality' => $light['quality'], 'evidence' => $plate['lighting_plan']['evidence'], 'reason' => $plate['lighting_plan']['reason']],
                'support' => $state === null ? [] : self::support($state),
                'landmarks' => $place === null ? [] : self::shown($place, $plate, true),
                'details' => [],
                'must_show' => [],
                'set' => $state,
            ];
        }, (array) ($data['backgrounds'] ?? [])));

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data  the data of a moto-locations-v4 dossier
     * @return array<string, array<string, mixed>> for each plate by id, what the video prompt step reads of its use: its time of day, weather, atmosphere and ambient sound, its light — every source with its kind, role, where it comes from, the direction it falls in, quality, strength, tone, its colour temperature as the dossier states it (null when it states none) and whether the frame shows it, the bright and dark areas and how the subject stands against the frame — and the state of its set, by name with what differs from the base state. Pinned with a shot run apart from what a still image is written from
     */
    public static function conditions(array $data): array
    {
        $conditions = [];

        foreach (self::normalize($data)['backgrounds'] as $plate) {
            $plan = (array) $plate['lighting_plan'];
            $conditions[(string) $plate['id']] = array_map(static fn (string $field): mixed => $plate[$field] ?? null, array_combine(MotoClipPrompt::CONDITION_FIELDS, MotoClipPrompt::CONDITION_FIELDS)) + [
                'light' => [
                    'sources' => array_values(array_map(static fn (array $source): array => array_intersect_key($source, array_flip(['kind', 'role', 'position', 'direction', 'quality', 'intensity', 'tone', 'kelvin', 'in_frame'])), (array) $plan['sources'])),
                    'bright_areas' => array_values((array) $plan['bright_areas']),
                    'dark_areas' => array_values((array) $plan['dark_areas']),
                    'subject_exposure' => $plan['subject_exposure'],
                ],
                'set_state' => $plate['set'] === null ? null : ['name' => $plate['set']['name'], 'changes' => self::changeLines($plate['set'])],
            ];
        }

        return $conditions;
    }

    private static function number(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    /** @return ?string the stored words without the full stop that ends them, so a value joined inside one phrase reads as part of it; null stays null */
    private static function plain(mixed $text): ?string
    {
        return is_string($text) ? (rtrim(trim($text), '.') ?: null) : null;
    }
}
