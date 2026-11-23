<?php

declare(strict_types=1);

namespace App\Video\Moto;

final class MotoLocationsCheck
{
    public const VERSION = 'moto-locations-check-v7';

    /** @var string the numbers a place and its subjects state agree */
    public const FITS = 'fits';

    /** @var string the numbers a place and its subjects state contradict each other */
    public const CONTRADICTS = 'contradicts';

    /** @var string a number the fit needs is not stated, so nothing is concluded */
    public const UNVERIFIED = 'unverified';

    /** @var float metres two stated numbers may differ by before they contradict each other */
    private const TOLERANCE_M = 0.005;

    public const PASS = 'PASS';

    public const FAIL = 'FAIL';

    public const UNSCORED = 'UNSCORED';

    public const JUDGE = 'not_run';

    /**
     * @param  ?array<string, mixed>  $response
     * @param  array<string, mixed>  $context  the check context pinned with the run
     * @param  list<array{code: string, path: string, message: string}>  $schema
     * @return array{version: string, format: string, verdict: string, status: ?string, error: ?string, schema: list<string>, issues: list<string>, warnings: list<string>, judge: string}
     */
    public static function evaluate(?array $response, array $context, array $schema): array
    {
        $format = in_array($context['format'] ?? null, [MotoContract::LOCATIONS_FORMAT_V2, MotoContract::LOCATIONS_FORMAT_V3, MotoContract::LOCATIONS_FORMAT_V4], true) ? (string) $context['format'] : MotoContract::LOCATIONS_FORMAT;

        return ['format' => $format] + self::scored($response, $context, $schema, $format);
    }

    /**
     * @param  ?array<string, mixed>  $response
     * @param  array<string, mixed>  $context
     * @param  list<array{code: string, path: string, message: string}>  $schema
     * @param  string  $format  the answer format the run pinned
     * @return array{version: string, format: string, verdict: string, status: ?string, error: ?string, schema: list<string>, issues: list<string>, warnings: list<string>, judge: string}
     */
    private static function scored(?array $response, array $context, array $schema, string $format): array
    {
        if ($response === null) {
            return self::result(self::UNSCORED, null, 'Phản hồi không đọc được JSON.');
        }

        if (($response['format'] ?? null) !== $format) {
            return self::result(self::UNSCORED, null, 'Phản hồi không theo định dạng '.$format.'.');
        }

        $status = is_string($response['status'] ?? null) ? $response['status'] : null;
        $schemaLines = array_map(static fn (array $v): string => trim($v['path'].' '.$v['message']), $schema);

        if ($schemaLines !== []) {
            return self::result(self::FAIL, $status, null, $schemaLines);
        }

        if ($status === 'fail') {
            return self::result(self::FAIL, $status, 'Model trả status fail: '.implode('; ', (array) ($response['flags'] ?? [])));
        }

        $issues = [];

        if (($response['project_id'] ?? null) !== ($context['project_id'] ?? null)) {
            $issues[] = Finding::blocking('MotoLocationsCheck.scored.1', 'project_id không khớp lượt chạy.');
        }

        if (($response['prompt_version'] ?? null) !== ($context['prompt_version'] ?? null)) {
            $issues[] = Finding::blocking('MotoLocationsCheck.scored.2', 'prompt_version là "'.($response['prompt_version'] ?? '').'"; lượt chạy này dùng '.($context['prompt_version'] ?? '').'.');
        }

        $data = (array) $response['data'];
        $beats = array_column((array) ($context['beats'] ?? []), null, 'id');
        $places = [];
        [$setDesign, $dossier, $stated, $data] = [$format === MotoContract::LOCATIONS_FORMAT_V4, $format !== MotoContract::LOCATIONS_FORMAT && $format !== MotoContract::LOCATIONS_FORMAT_V2, $data, $format === MotoContract::LOCATIONS_FORMAT_V4 ? MotoSetDesign::normalize($data) : $data];

        foreach ((array) $data['locations'] as $place) {
            if (isset($places[$place['id']])) {
                $issues[] = Finding::requirement('MotoLocationsCheck.scored.3', "{$place['id']} khai hai lần.");
            }

            $places[$place['id']] = $place;
        }

        [$backgroundIssues, $served] = self::backgroundIssues((array) $data['backgrounds'], $places, $beats, (array) ($context['build_states'] ?? []), MotoScriptValidation::supportsByDesign($context), $dossier);
        $used = [...array_column((array) $data['backgrounds'], 'location'), ...array_filter(array_column((array) $data['unplated'], 'location'), 'is_string')];
        $held = array_merge([], ...array_map(static fn (array $conflict): array => $conflict['blocking'] ? (array) $conflict['beats'] : [], (array) $data['conflicts']));
        $covered = [...array_merge([], ...array_values($served)), ...array_column((array) $data['unplated'], 'beat'), ...$held];

        foreach (array_keys($places) as $id) {
            if (! in_array($id, $used, true)) {
                $issues[] = Finding::note('MotoLocationsCheck.scored.4', "{$id} không có ảnh nền hay beat nào dùng.");
            }
        }

        foreach (array_keys($beats) as $id) {
            if (! in_array($id, $covered, true)) {
                $issues[] = Finding::requirement('MotoLocationsCheck.scored.5', "{$id} không đứng trên ảnh nền nào, không có trong unplated và không có xung đột chặn nào nêu nó.");
            }
        }

        foreach ((array) $data['unplated'] as $entry) {
            if (! isset($beats[$entry['beat']])) {
                $issues[] = Finding::requirement('MotoLocationsCheck.scored.6', "unplated nhắc {$entry['beat']}, không có trong kịch bản.");
            }

            if (is_string($entry['location']) && ! isset($places[$entry['location']])) {
                $issues[] = Finding::requirement('MotoLocationsCheck.scored.7', "unplated {$entry['beat']} nhắc {$entry['location']}, không có trong locations.");
            }
        }

        foreach ((array) $data['continuity'] as $entry) {
            $reach = [];

            foreach ($entry['backgrounds'] as $id) {
                if (! isset($served[$id])) {
                    $issues[] = Finding::requirement('MotoLocationsCheck.scored.8', "continuity nhắc {$id}, không có trong backgrounds.");
                }

                $reach = [...$reach, ...($served[$id] ?? [])];
            }

            foreach ($entry['beats'] as $id) {
                if (! in_array($id, $reach, true)) {
                    $issues[] = Finding::requirement('MotoLocationsCheck.scored.9', "continuity: {$id} không phải beat của ".implode(', ', $entry['backgrounds']).'.');
                }
            }
        }

        [$conflictIssues, $conflictWarnings, $blocking] = self::conflictIssues((array) $data['conflicts'], $beats, $served, (array) ($context['build_states'] ?? []), $dossier ? $context : null);

        if ($blocking && $status !== 'partial') {
            $issues[] = Finding::requirement('MotoLocationsCheck.scored.10', 'Còn xung đột chặn nhưng status không phải partial.');
        }

        $issues = [
            ...$issues,
            ...$backgroundIssues,
            ...$conflictIssues,
            ...($dossier ? [...self::dossierIssues($stated, $places, $beats, $served, $context), ...($setDesign ? self::setDesignIssues($stated, $places, $beats, $served, $context) : [])] : []),
        ];
        $warnings = $conflictWarnings;

        return self::result($issues === [] ? self::PASS : self::FAIL, $status, null, [], $issues, $warnings);
    }

    /**
     * @param  list<array<string, mixed>>  $backgrounds
     * @param  array<string, array<string, mixed>>  $places
     * @param  array<string, array<string, mixed>>  $beats
     * @param  array<string, array<string, mixed>>  $states
     * @return array{0: list<string>, 1: array<string, list<string>>} the issues and the beats each background serves
     */
    /**
     * @param  list<string>  $support  the temporary items the plate holds
     * @param  list<string>  $mine  the beats the plate serves
     * @param  array<string, array<string, mixed>>  $beats  the beats of the context by id
     * @param  array<string, array<string, mixed>>  $states  the build states of the context by id
     * @param  bool  $supportByDesign  the run pinned moto-validation-v2: any temporary item the design lists; otherwise the items of the state of every beat the plate serves
     * @return list<string>
     */
    public static function supportIssues(string $id, array $support, array $mine, array $beats, array $states, bool $supportByDesign): array
    {
        $issues = [];
        $temporaryByState = array_map(static fn (mixed $state): array => (array) (is_array($state) ? ($state['temporary'] ?? []) : []), $states);

        if ($supportByDesign) {
            // A plate may hold any temporary item the design lists; where it belongs is the prompt's and the reviewer's call.
            foreach (array_diff($support, MotoSupportReferences::designTemporary($temporaryByState)) as $item) {
                $issues[] = Finding::requirement('MotoLocationsCheck.supportIssues.1', "{$id} có {$item}, nhưng thiết kế không có thiết bị tạm này ở build state nào.");
            }

            return $issues;
        }

        foreach ($mine as $beat) {
            $state = $beats[$beat]['state'] ?? null;

            foreach (array_diff($support, MotoSupportReferences::allowed($temporaryByState, is_string($state) ? $state : null, false)) as $item) {
                $issues[] = Finding::requirement('MotoLocationsCheck.supportIssues.2', "{$id} có {$item}, nhưng {$beat} ở ".($state ?? 'không giai đoạn')." không có thiết bị tạm này.");
            }
        }

        return $issues;
    }

    private static function backgroundIssues(array $backgrounds, array $places, array $beats, array $states, bool $supportByDesign = false, bool $dossier = false): array
    {
        $issues = [];
        $served = [];

        foreach ($backgrounds as $background) {
            $id = $background['id'];

            if (isset($served[$id])) {
                $issues[] = Finding::requirement('MotoLocationsCheck.backgroundIssues.1', "{$id} khai hai lần.");
            }

            if (! isset($places[$background['location']])) {
                $issues[] = Finding::requirement('MotoLocationsCheck.backgroundIssues.2', "{$id} đứng trên {$background['location']}, không có trong locations.");
            }

            $mine = [];
            $seen = [];

            foreach ($background['uses'] as $use) {
                if (! isset($beats[$use['beat']])) {
                    $issues[] = Finding::requirement('MotoLocationsCheck.backgroundIssues.3', "{$id} dùng cho {$use['beat']}, không có trong kịch bản.");

                    continue;
                }

                $key = $use['beat'].'|'.$use['part'];

                if (isset($seen[$key])) {
                    $issues[] = Finding::requirement('MotoLocationsCheck.backgroundIssues.4', "{$id} khai hai lần cùng phần \"{$use['part']}\" của {$use['beat']}.");
                }

                $seen[$key] = true;
                $mine[] = $use['beat'];
            }

            $mine = array_values(array_unique($mine));
            $served[$id] = [...($served[$id] ?? []), ...$mine];
            if ($background['light']['evidence'] !== null) {
                $issues = [...$issues, ...self::quoteIssues("{$id} light", $background['light']['evidence'], $mine, $beats, $dossier)];
            }

            foreach ($background['details'] as $detail) {
                if ($detail['evidence'] !== null) {
                    $issues = [...$issues, ...self::quoteIssues("{$id} detail \"{$detail['text']}\"", $detail['evidence'], $mine, $beats, $dossier)];
                }
            }

            foreach ($background['shot_changes'] as $change) {
                foreach (array_diff($change['beats'], $mine) as $beat) {
                    $issues[] = Finding::requirement('MotoLocationsCheck.backgroundIssues.5', "{$id}: shot_changes nhắc {$beat}, không phải beat của ảnh nền này.");
                }
            }

            if ($dossier) {
                foreach ($mine as $beat) {
                    foreach (array_diff((array) $background['support'], (array) ($beats[$beat]['temporary'] ?? [])) as $item) {
                        $issues[] = Finding::requirement('MotoLocationsCheck.supportIssues.3', "{$id} có {$item}, nhưng các checkpoint của {$beat} không cho phép thiết bị tạm này.");
                    }
                }

                continue;
            }

            $issues = [...$issues, ...self::supportIssues((string) $id, (array) $background['support'], $mine, $beats, $states, $supportByDesign)];

        }

        return [$issues, $served];
    }

    /**
     * @param  array{beat: string, field: string, quote: string}  $evidence
     * @param  list<string>  $mine  the beats the background serves
     * @param  array<string, array<string, mixed>>  $beats
     * @param  bool  $exact  a location dossier: the quoted words must stand character for character in the field they name; an earlier answer is held only to the beat and the field, as before
     * @return list<string>
     */
    private static function quoteIssues(string $label, array $evidence, array $mine, array $beats, bool $exact = false): array
    {
        if (! in_array($evidence['beat'], $mine, true)) {
            return [Finding::requirement('MotoLocationsCheck.quoteIssues.beat', "{$label}: trích {$evidence['beat']}, không phải beat của ảnh nền này.")];
        }

        if (! array_key_exists($evidence['field'], (array) ($beats[$evidence['beat']] ?? []))) {
            return [Finding::requirement('MotoLocationsCheck.quoteIssues.field', "{$label}: {$evidence['beat']}.{$evidence['field']} không có trong dữ liệu đã ghim.")];
        }

        $source = $beats[$evidence['beat']][$evidence['field']];

        return $exact && ! (is_string($source) && str_contains($source, (string) $evidence['quote']))
            ? [Finding::requirement('MotoLocationsCheck.quoteIssues.text', "{$label}: câu trích không có nguyên văn trong {$evidence['beat']}.{$evidence['field']}.")]
            : [];
    }

    /**
     * @param  list<array<string, mixed>>  $conflicts
     * @param  array<string, array<string, mixed>>  $beats
     * @param  array<string, list<string>>  $served
     * @param  array<string, array<string, mixed>>  $states
     * @param  ?array<string, mixed>  $dossier  the context of a location dossier: its evidence is held to the pinned input — the path must name a value, a quote must stand in it word for word and a value must equal it; null for an earlier answer, whose evidence is only noted as before
     * @return array{0: list<string>, 1: list<string>, 2: bool} the issues, the warnings that show a conflict which blocks nothing, and whether a blocking conflict is open
     */
    private static function conflictIssues(array $conflicts, array $beats, array $served, array $states, ?array $dossier = null): array
    {
        $issues = [];
        $warnings = [];
        $blocking = false;

        foreach ($conflicts as $index => $conflict) {
            $label = 'Xung đột '.($index + 1);

            if ($conflict['beats'] === [] && $conflict['backgrounds'] === []) {
                $issues[] = Finding::note('MotoLocationsCheck.conflictIssues.1', "{$label} không nêu beat hay ảnh nền nào bị ảnh hưởng.");
            }

            foreach ($conflict['beats'] as $beat) {
                if (! isset($beats[$beat])) {
                    $issues[] = Finding::note('MotoLocationsCheck.conflictIssues.2', "{$label} nhắc {$beat}, không có trong kịch bản.");
                }
            }

            foreach ($conflict['backgrounds'] as $background) {
                if (! isset($served[$background])) {
                    $issues[] = Finding::note('MotoLocationsCheck.conflictIssues.3', "{$label} nhắc {$background}, không có trong backgrounds.");
                }
            }

            foreach ($conflict['evidence'] as $evidence) {
                if ($dossier !== null) {
                    [$found, $value] = self::resolve($evidence['path'], $beats, $states, $dossier);
                    $problem = match (true) {
                        ! $found => "{$evidence['path']} không có trong dữ liệu đã ghim.",
                        array_key_exists('quote', $evidence) && ! (is_string($value) && str_contains($value, (string) $evidence['quote'])) => "câu trích không có nguyên văn trong {$evidence['path']}.",
                        array_key_exists('value', $evidence) && $evidence['value'] != $value => "giá trị khai cho {$evidence['path']} khác dữ liệu đã ghim.",
                        default => null,
                    };

                    if ($problem !== null) {
                        $issues[] = Finding::requirement('MotoLocationsCheck.conflictIssues.5', "{$label}: {$problem}");
                    }

                    continue;
                }

                $problem = self::evidenceProblem($evidence, $beats, $states);

                if ($problem !== null) {
                    $issues[] = Finding::note('MotoLocationsCheck.conflictIssues.4', "{$label}: {$problem}");
                }
            }

            if ($conflict['blocking']) {
                $blocking = true;
            } else {
                $warnings[] = "{$label} ({$conflict['kind']}, không chặn): {$conflict['note']}";
            }
        }

        return [$issues, $warnings, $blocking];
    }

    /**
     * @param  array{path: string, quote?: string, value?: mixed}  $evidence
     * @param  array<string, array<string, mixed>>  $beats
     * @param  array<string, array<string, mixed>>  $states
     * @return ?string why the path of the evidence names nothing in the pinned input; what the evidence quotes or gives as value is content and is not read
     */
    private static function evidenceProblem(array $evidence, array $beats, array $states): ?string
    {
        return self::resolve($evidence['path'], $beats, $states)[0] ? null : "{$evidence['path']} không có trong dữ liệu đã ghim.";
    }

    /**
     * @param  array<string, array<string, mixed>>  $beats
     * @param  array<string, array<string, mixed>>  $states
     * @param  array<string, mixed>  $context  the context of a location dossier, which also pins the size of the motorcycle and the flags of the script; empty for an earlier answer
     * @return array{0: bool, 1: mixed} whether the path names a value of the pinned input, and that value; only the paths the schema allows are read
     */
    private static function resolve(string $path, array $beats, array $states, array $context = []): array
    {
        $parts = explode('.', $path);

        if ($parts[0] === 'motorcycle') {
            $size = (array) ($context['motorcycle']['size'] ?? []);

            return ($parts[1] ?? null) === 'size' && array_key_exists($parts[2] ?? '', $size) ? [true, $size[$parts[2]]] : [false, null];
        }

        if ($parts[0] === 'source_flags') {
            $flags = array_values((array) ($context['source_flags'] ?? []));

            return ctype_digit($parts[1] ?? '') && array_key_exists((int) $parts[1], $flags) ? [true, $flags[(int) $parts[1]]] : [false, null];
        }

        if ($parts[0] === 'build_states') {
            $state = $states[$parts[1]] ?? null;

            return is_array($state) && array_key_exists($parts[2], $state) ? [true, $state[$parts[2]]] : [false, null];
        }

        $beat = $beats[$parts[1]] ?? null;
        $field = $parts[2] ?? null;

        if (! is_array($beat) || $field === null) {
            return [false, null];
        }

        $pick = static function (array $list, string $key, string $id): ?array {
            foreach ($list as $item) {
                if (($item[$key] ?? null) === $id) {
                    return $item;
                }
            }

            return null;
        };
        $entry = match ($field) {
            'events' => ($event = $pick((array) ($beat['events'] ?? []), 'id', $parts[3] ?? '')) !== null && array_key_exists($parts[4] ?? '', $event) ? ['value' => $event[$parts[4]]] : null,
            'situation' => is_array($beat['situation']) && array_key_exists($parts[3], $beat['situation']) ? ['value' => $beat['situation'][$parts[3]]] : null,
            'subjects' => ($subject = $pick((array) $beat['subjects'], 'ref', $parts[3])) !== null && array_key_exists($parts[4], $subject['size']) ? ['value' => $subject['size'][$parts[4]]] : null,
            'operations' => ($operation = $pick((array) $beat['operations'], 'source', "{$parts[3]}.use_sequence")) !== null ? ['value' => $operation['steps']] : null,
            'conditions' => ($condition = $pick((array) $beat['conditions'], 'source', "{$parts[3]}.time_light")) !== null ? ['value' => $condition['text']] : null,
            'limits' => ($limit = $pick((array) $beat['limits'], 'source', $parts[3])) !== null && array_key_exists($parts[4], $limit) ? ['value' => $limit[$parts[4]]] : null,
            default => count($parts) === 3 && array_key_exists($field, $beat) ? ['value' => $beat[$field]] : null,
        };

        return $entry === null ? [false, null] : [true, $entry['value']];
    }

    /**
     * @param  array<string, mixed>  $data  the data of a location dossier
     * @param  array<string, array<string, mixed>>  $places  its places by id
     * @param  array<string, array<string, mixed>>  $beats  the beats of the context by id
     * @param  array<string, list<string>>  $served  the beats each background serves
     * @param  array<string, mixed>  $context
     * @return list<Finding> what breaks the links of a dossier — a zone or a route declared twice, a footprint in a place with no coordinate system, a route that opens into a zone the place does not have, a beat a place lists that the script does not have, a place declared real with no source in the input, a connection declared twice, between places the dossier does not have, of a place with itself or quoting words its beat does not hold, a plate whose subject zone is no subject zone of its place or whose landmark is not one of its place, a measurement whose value does not agree with its basis, a footprint whose numbers are no rectangle on the floor — then, as notes, a place whose listed beats differ from the beats its plates serve and every finding of usability()
     */
    private static function dossierIssues(array $data, array $places, array $beats, array $served, array $context): array
    {
        $issues = [];
        $using = [];

        foreach ((array) $data['backgrounds'] as $background) {
            $using[$background['location']] = [...($using[$background['location']] ?? []), ...($served[$background['id']] ?? [])];
        }

        foreach ((array) $data['unplated'] as $entry) {
            if (is_string($entry['location'])) {
                $using[$entry['location']][] = $entry['beat'];
            }
        }

        foreach ($places as $id => $place) {
            $zones = [];

            foreach ((array) $place['zones'] as $zone) {
                if (isset($zones[$zone['id']])) {
                    $issues[] = Finding::requirement('MotoLocationsCheck.dossierIssues.1', "{$id}: vùng {$zone['id']} khai hai lần.");
                }

                $zones[$zone['id']] = true;

                if ($zone['footprint'] !== null && $place['coordinate_system'] === null) {
                    $issues[] = Finding::requirement('MotoLocationsCheck.dossierIssues.2', "{$id} {$zone['id']} có footprint nhưng địa điểm không khai coordinate_system.");
                }

                if ($zone['footprint'] !== null && self::footprint($zone) === null) {
                    $issues[] = Finding::requirement('MotoLocationsCheck.dossierIssues.15', "{$id} {$zone['id']}: footprint cần x_m và y_m từ 0 trở lên, size_x_m và size_y_m lớn hơn 0.");
                }
            }

            foreach ((array) $place['dimensions'] as $side => $dimension) {
                if (($problem = self::dimensionProblem((array) $dimension)) !== null) {
                    $issues[] = Finding::requirement('MotoLocationsCheck.dossierIssues.14', "{$id}.dimensions.{$side}: {$problem}");
                }
            }

            $routes = [];

            foreach ((array) $place['access_routes'] as $route) {
                if (isset($routes[$route['id']])) {
                    $issues[] = Finding::requirement('MotoLocationsCheck.dossierIssues.3', "{$id}: lối {$route['id']} khai hai lần.");
                }

                $routes[$route['id']] = true;

                if ($route['zone'] !== null && ! isset($zones[$route['zone']])) {
                    $issues[] = Finding::requirement('MotoLocationsCheck.dossierIssues.4', "{$id} {$route['id']} mở vào {$route['zone']}, không có trong zones của địa điểm.");
                }

                if (($problem = self::dimensionProblem((array) $route['clear_width_m'])) !== null) {
                    $issues[] = Finding::requirement('MotoLocationsCheck.dossierIssues.14', "{$id} {$route['id']}.clear_width_m: {$problem}");
                }
            }

            foreach ((array) $place['source_beats'] as $beat) {
                if (! isset($beats[$beat])) {
                    $issues[] = Finding::requirement('MotoLocationsCheck.dossierIssues.5', "{$id}.source_beats nhắc {$beat}, không có trong kịch bản.");
                }
            }

            $listed = array_values(array_unique(array_map('strval', (array) $place['source_beats'])));
            $used = array_values(array_unique(array_map('strval', $using[$id] ?? [])));
            sort($listed);
            sort($used);

            if ($listed !== $used) {
                $issues[] = Finding::note('MotoLocationsCheck.dossierIssues.6', "{$id}.source_beats ghi ".(implode(', ', $listed) ?: '—').'; ảnh nền và unplated của địa điểm này dùng '.(implode(', ', $used) ?: '—').'.');
            }

            if ($place['provenance']['kind'] === 'real_place') {
                $issues[] = Finding::requirement('MotoLocationsCheck.dossierIssues.7', "{$id} khai là địa điểm thật, nhưng đầu vào không cung cấp nguồn địa điểm thật nào.");
            }
        }

        $connections = [];

        foreach ((array) $data['connections'] as $connection) {
            $label = (string) $connection['id'];

            if (isset($connections[$label])) {
                $issues[] = Finding::requirement('MotoLocationsCheck.dossierIssues.8', "{$label} khai hai lần.");
            }

            $connections[$label] = true;

            foreach (['from', 'to'] as $end) {
                if (! isset($places[$connection[$end]])) {
                    $issues[] = Finding::requirement('MotoLocationsCheck.dossierIssues.9', "{$label}.{$end} {$connection[$end]} không có trong locations.");
                }
            }

            if ($connection['from'] === $connection['to']) {
                $issues[] = Finding::requirement('MotoLocationsCheck.dossierIssues.10', "{$label} nối {$connection['from']} với chính nó.");
            }

            if ($connection['evidence'] !== null) {
                $issues = [...$issues, ...self::quoteIssues("{$label} evidence", $connection['evidence'], array_keys($beats), $beats, true)];
            }
        }

        foreach ((array) $data['backgrounds'] as $background) {
            $place = $places[$background['location']] ?? null;

            if ($place === null) {
                continue;
            }

            $kinds = array_column((array) $place['zones'], 'kind', 'id');

            if ($background['subject_zone'] !== null && ! isset($kinds[$background['subject_zone']])) {
                $issues[] = Finding::requirement('MotoLocationsCheck.dossierIssues.11', "{$background['id']}.subject_zone {$background['subject_zone']} không có trong zones của {$place['id']}.");
            } elseif ($background['subject_zone'] !== null && $kinds[$background['subject_zone']] !== 'subject') {
                $issues[] = Finding::requirement('MotoLocationsCheck.dossierIssues.12', "{$background['id']}.subject_zone {$background['subject_zone']} là vùng {$kinds[$background['subject_zone']]}, không phải vùng đặt chủ thể.");
            }

            foreach (($context['format'] ?? null) === MotoContract::LOCATIONS_FORMAT_V4 ? [] : array_diff((array) $background['landmarks'], (array) $place['fixed_landmarks']) as $landmark) {
                $issues[] = Finding::requirement('MotoLocationsCheck.dossierIssues.13', "{$background['id']}.landmarks: \"{$landmark}\" không có nguyên văn trong fixed_landmarks của {$place['id']}.");
            }
        }

        foreach (self::usability($data, $context) as $finding) {
            $issues[] = match (true) {
                $finding['verdict'] === self::CONTRADICTS => Finding::note('MotoLocationsCheck.usability.1', $finding['message']),
                $finding['blocking'] => Finding::note('MotoLocationsCheck.usability.2', $finding['message']),
                default => Finding::note('MotoLocationsCheck.usability.3', $finding['message']),
            };
        }

        return $issues;
    }

    /** @var list<string> the conditions of a use two plates a continuity entry holds together may differ in only when the entry lists them under may_change, stated as an enumeration: a difference holds the dossier back */
    private const CONDITIONS = ['ground'];

    /** @var list<string> the conditions of a use stated in words: code cannot tell whether two wordings say one thing, so a difference the entry does not allow is told to the reviewer and holds nothing back */
    private const WORDED_CONDITIONS = ['time_of_day', 'weather', 'atmosphere'];

    /**
     * @param  array<string, mixed>  $one  the camera of a plate
     * @param  array<string, mixed>  $other  the camera of another plate of the same place
     * @return array{0: list<string>, 1: list<string>, 2: list<string>, 3: list<array{0: string, 1: mixed, 2: mixed}>} what keeps the two from being one frame among the values the camera states by number or id, each with both values; what neither proves because a value is left unstated; with both values, what one of the two states and the other does not; and the words that differ where the camera has no number or id to compare — its position when neither gives a point, the description of what it looks at when neither gives a ref — each as the field and both wordings. The height, the lens, the point and the ref must be stated by both and equal to prove one frame: a value one or both leave out is unproven, since words are never read as proof. Words are compared as comparable() reads them, and code never says whether two wordings say one thing. Two nulls are never read as proof of one frame, yet they are the same data: only a value one plate states and the other leaves out is listed third
     */
    private static function cameraDifference(array $one, array $other): array
    {
        $differ = [];
        $unstated = [];
        $partial = [];
        $worded = [];
        $told = static fn (string $field, mixed $a, mixed $b): string => "{$field} (".json_encode($a, JSON_UNESCAPED_UNICODE).' và '.json_encode($b, JSON_UNESCAPED_UNICODE).')';

        foreach (['height_m', 'lens_mm'] as $field) {
            if ($one[$field] === null || $other[$field] === null) {
                $unstated[] = $field;

                if ($one[$field] !== $other[$field]) {
                    $partial[] = $told($field, $one[$field], $other[$field]);
                }
            } elseif ($one[$field] != $other[$field]) {
                $differ[] = $told($field, $one[$field], $other[$field]);
            }
        }

        if ($one['zone'] !== $other['zone']) {
            $differ[] = $told('zone', $one['zone'], $other['zone']);
        }

        foreach ([['point', $one['point'], $other['point'], 'position', $one['position'], $other['position']], ['look_at', $one['look_at']['ref'], $other['look_at']['ref'], 'look_at.description', $one['look_at']['description'], $other['look_at']['description']]] as [$field, $a, $b, $words, $wordsA, $wordsB]) {
            if (($a === null) !== ($b === null)) {
                $unstated[] = $field;
                $partial[] = $told($field, $a, $b);
            } elseif ($a !== null && MotoSetDesign::comparable($a) != MotoSetDesign::comparable($b)) {
                $differ[] = $told($field, $a, $b);
            } elseif ($a === null) {
                $unstated[] = $field;

                if (MotoSetDesign::comparable($wordsA) !== MotoSetDesign::comparable($wordsB)) {
                    $worded[] = [$words, $wordsA, $wordsB];
                }
            }
        }

        return [$differ, $unstated, $partial, $worded];
    }

    /**
     * @param  array<string, mixed>  $data  the data of a set design dossier (moto-locations-v4) as the answer states it
     * @param  array<string, array<string, mixed>>  $places  its places by id
     * @param  array<string, array<string, mixed>>  $beats  the beats of the context by id
     * @param  array<string, list<string>>  $served  the beats each background serves
     * @param  array<string, mixed>  $context
     * @return list<Finding> what breaks the links and the numbers of a set design, each a condition the next step needs. In a place: a part of the architecture or an object declared twice, an object in a zone the place does not have, with a footprint in a place that states no coordinate system or one that is no rectangle on the floor, with a height that does not agree with its basis, or naming a temporary item while it is no support or the design allows no such item. In a plate: a camera in a zone that is no camera zone of its place, with a height, lens or point that is no valid number or a point in a place with no coordinate system, or looking at something the place does not have, or a point outside the footprint of the camera zone it stands in; a visible ref the place does not have, or an object it shows that does not stand in the place in the state of the plate; a light source declared twice, one the frame shows with no fixture of the place present in it, or a colour temperature that is no number above zero; a change of the set that names nothing of the place, moves, turns, adds or removes a fixed object or a part of the architecture, changes nothing, names a beat the plate does not serve or changes one thing twice. In a continuity entry: plates of two places held to one layout or one camera, a layout, a camera or a condition stated by structure that differs where the entry does not allow it (words that differ are only noted, pairIssues()), a camera value two plates of one frame leave unstated — two nulls prove nothing — and a camera both kept and allowed to change
     */
    private static function setDesignIssues(array $data, array $places, array $beats, array $served, array $context): array
    {
        $issues = [];
        $need = static function (string $code, string $message) use (&$issues): void {
            $issues[] = Finding::requirement("MotoLocationsCheck.setDesignIssues.{$code}", $message);
        };
        $allowed = array_map('strval', (array) ($context['temporary_equipment'] ?? []));
        $known = [];

        foreach ($places as $id => $place) {
            $zones = array_column((array) $place['zones'], 'kind', 'id');
            $seen = [];

            foreach ((array) $place['set_design']['architecture'] as $part) {
                if (isset($seen[$part['id']])) {
                    $need('1', "{$id}: thành phần kiến trúc {$part['id']} khai hai lần.");
                }

                $seen[$part['id']] = true;
            }

            foreach ((array) $place['set_design']['objects'] as $object) {
                $label = "{$id} {$object['id']}";

                if (isset($seen[$object['id']])) {
                    $need('2', "{$id}: vật thể {$object['id']} khai hai lần.");
                }

                $seen[$object['id']] = true;

                if ($object['zone'] !== null && ! isset($zones[$object['zone']])) {
                    $need('3', "{$label} đặt ở {$object['zone']}, không có trong zones của địa điểm.");
                }

                if ($object['footprint'] !== null && $place['coordinate_system'] === null) {
                    $need('4', "{$label} có footprint nhưng địa điểm không khai coordinate_system.");
                }

                if ($object['footprint'] !== null && self::footprint($object) === null) {
                    $need('5', "{$label}: footprint cần x_m và y_m từ 0 trở lên, size_x_m và size_y_m lớn hơn 0.");
                }

                if (($problem = self::dimensionProblem((array) $object['height_m'])) !== null) {
                    $need('6', "{$label}.height_m: {$problem}");
                }

                if ($object['support_kind'] !== null && $object['role'] !== 'support') {
                    $need('7', "{$label} khai support_kind {$object['support_kind']} nhưng role là {$object['role']}, không phải support.");
                }

                if ($object['support_kind'] !== null && ! in_array($object['support_kind'], $allowed, true)) {
                    $need('8', "{$label}.support_kind {$object['support_kind']} không có trong temporary_equipment của đầu vào.");
                }
            }

            $known[$id] = ['zones' => $zones, 'set' => $seen];
        }

        $plates = array_column((array) $data['backgrounds'], null, 'id');
        $states = [];

        foreach ($plates as $id => $plate) {
            $place = $places[$plate['location']] ?? null;

            if ($place === null) {
                continue;
            }

            $zones = $known[$place['id']]['zones'];
            $set = $known[$place['id']]['set'];
            $camera = (array) $plate['camera'];
            $state = MotoSetDesign::state($place, $plate);
            $states[$id] = $state;
            $present = array_column(array_filter($state['objects'], static fn (array $object): bool => (bool) $object['present']), 'id');

            if ($camera['zone'] !== null && ($zones[$camera['zone']] ?? null) !== 'camera') {
                $need('9', "{$id}.camera.zone {$camera['zone']} ".(isset($zones[$camera['zone']]) ? "là vùng {$zones[$camera['zone']]}, không phải vùng camera" : 'không có trong zones')." của {$place['id']}.");
            }

            foreach (['height_m' => 'chiều cao', 'lens_mm' => 'tiêu cự'] as $field => $word) {
                if ($camera[$field] !== null && self::positive($camera[$field]) === null) {
                    $need('10', "{$id}.camera.{$field}: {$word} phải là số lớn hơn 0 hoặc null khi chưa chọn, đang là ".json_encode($camera[$field]).'.');
                }
            }

            if ($camera['point'] !== null && ($place['coordinate_system'] === null || ! is_numeric($camera['point']['x_m'] ?? null) || ! is_numeric($camera['point']['y_m'] ?? null) || $camera['point']['x_m'] < 0 || $camera['point']['y_m'] < 0)) {
                $need('10', "{$id}.camera.point ".($place['coordinate_system'] === null ? 'được khai nhưng địa điểm không có coordinate_system' : 'cần x_m và y_m từ 0 trở lên').'.');
            }

            if ($camera['look_at']['ref'] !== null && ! isset($zones[$camera['look_at']['ref']]) && ! isset($set[$camera['look_at']['ref']])) {
                $need('11', "{$id}.camera.look_at.ref {$camera['look_at']['ref']} không có trong {$place['id']}.");
            }

            foreach (array_diff((array) $plate['visible'], array_keys($set)) as $ref) {
                $need('12', "{$id}.visible nhắc {$ref}, không có trong set_design của {$place['id']}.");
            }

            foreach (array_diff(array_intersect((array) $plate['visible'], array_column($state['objects'], 'id')), $present) as $ref) {
                $need('28', "{$id}.visible nhắc vật thể {$ref}, nhưng nó không hiện diện ở trạng thái của ảnh nền này — khung không thể thấy thứ set_state nói là không có.");
            }

            $standing = $camera['zone'] === null || $place['coordinate_system'] === null ? null : self::footprint((array) (array_column((array) $place['zones'], null, 'id')[$camera['zone']] ?? []));
            $point = (array) ($camera['point'] ?? []);

            if ($standing !== null && is_numeric($point['x_m'] ?? null) && is_numeric($point['y_m'] ?? null)
                && ($point['x_m'] + self::TOLERANCE_M < $standing['x_m'] || $point['x_m'] > $standing['x_m'] + $standing['size_x_m'] + self::TOLERANCE_M
                    || $point['y_m'] + self::TOLERANCE_M < $standing['y_m'] || $point['y_m'] > $standing['y_m'] + $standing['size_y_m'] + self::TOLERANCE_M)) {
                $need('29', "{$id}.camera.point (x {$point['x_m']}, y {$point['y_m']}) nằm ngoài footprint của vùng camera {$camera['zone']} mà camera khai đứng trong.");
            }

            $lights = [];

            foreach ((array) $plate['lighting_plan']['sources'] as $source) {
                if (isset($lights[$source['id']])) {
                    $need('13', "{$id}: nguồn sáng {$source['id']} khai hai lần.");
                }

                $lights[$source['id']] = true;
                $fixture = $source['fixture'];

                if ($source['in_frame'] && ($fixture === null || ! isset($set[$fixture]) || (str_starts_with((string) $fixture, 'OB') && ! in_array($fixture, $present, true)))) {
                    $need('14', "{$id} {$source['id']} khai in_frame nhưng ".match (true) {
                        $fixture === null => 'không nêu fixture — nguồn nhìn thấy trong khung phải là một thành phần của địa điểm',
                        ! isset($set[$fixture]) => "fixture {$fixture} không có trong set_design của {$place['id']}",
                        default => "vật thể {$fixture} không hiện diện ở trạng thái của ảnh nền này",
                    }.'.');
                } elseif (! $source['in_frame'] && $fixture !== null && ! isset($set[$fixture])) {
                    $need('14', "{$id} {$source['id']}: fixture {$fixture} không có trong set_design của {$place['id']}.");
                }

                if ($source['kelvin'] !== null && self::positive($source['kelvin']) === null) {
                    $need('15', "{$id} {$source['id']}.kelvin phải là số lớn hơn 0 hoặc null khi nguồn không nêu, đang là ".json_encode($source['kelvin']).'.');
                }
            }

            foreach ($state['refused'] as $refused) {
                match ($refused['why']) {
                    'unknown' => $need('16', "{$id}.set_state đổi {$refused['ref']}, không có trong set_design của {$place['id']}."),
                    'fixed' => $need('17', "{$id}.set_state di chuyển, xoay, thêm hoặc bỏ {$refused['ref']} — vật thể cố định của {$place['id']} không đổi vị trí hay sự hiện diện."),
                    'architecture' => $need('18', "{$id}.set_state di chuyển hoặc bỏ {$refused['ref']} — thành phần kiến trúc chỉ đổi được tình trạng."),
                    default => $issues[] = Finding::note('MotoLocationsCheck.setDesignIssues.19', "{$id}.set_state nêu {$refused['ref']} nhưng không đổi gì so với trạng thái chuẩn."),
                };
            }

            $changed = [];

            foreach ((array) $plate['set_state']['changes'] as $change) {
                foreach (array_diff((array) $change['beats'], $served[$id] ?? []) as $beat) {
                    $need('20', "{$id}.set_state đổi {$change['ref']} cho {$beat}, không phải beat của ảnh nền này.");
                }

                if (isset($changed[$change['ref']])) {
                    $need('21', "{$id}.set_state đổi {$change['ref']} hai lần.");
                }

                $changed[$change['ref']] = true;
            }
        }

        foreach ((array) $data['continuity'] as $index => $entry) {
            $label = 'continuity '.($index + 1).' ('.implode(', ', (array) $entry['backgrounds']).')';
            $held = array_values(array_filter(array_map(static fn (string $id): ?array => $plates[$id] ?? null, array_map('strval', (array) $entry['backgrounds']))));
            $free = array_map('strval', (array) $entry['may_change']);

            if ($entry['evidence'] !== null) {
                $issues = [...$issues, ...self::quoteIssues("{$label} evidence", $entry['evidence'], array_map('strval', (array) $entry['beats']), $beats, true)];
            }

            if ($entry['same_camera'] && in_array('camera', $free, true)) {
                $need('26', "{$label} vừa khai same_camera vừa cho phép đổi camera.");
            }

            if (count($held) < 2) {
                continue;
            }

            if (($entry['same_layout'] || $entry['same_camera']) && count(array_unique(array_column($held, 'location'))) > 1) {
                $need('22', "{$label} khai cùng bố trí hoặc cùng camera cho ảnh nền của các địa điểm khác nhau (".implode(', ', array_unique(array_column($held, 'location'))).').');

                continue;
            }

            foreach ($held as $nth => $first) {
                foreach (array_slice($held, $nth + 1) as $other) {
                    $issues = [...$issues, ...self::pairIssues($label, $entry, $free, $first, $other, $states)];
                }
            }
        }

        return $issues;
    }

    /**
     * @param  string  $label  how the continuity entry is named in a message
     * @param  array<string, mixed>  $entry  the continuity entry
     * @param  list<string>  $free  what the entry lets differ (may_change)
     * @param  array<string, mixed>  $first  a plate the entry holds
     * @param  array<string, mixed>  $other  another plate it holds
     * @param  array<string, array<string, mixed>>  $states  MotoSetDesign::state() of each plate by id
     * @return list<Finding> what two plates of one entry differ in although the entry does not let it differ. Every pair of the entry is compared, so the order the plates are listed in changes nothing. A value the answer states by structure — an enumeration, a number, a flag, an id, or a change of the set the plate declares — holds the dossier back when it differs; words that differ are told to the reviewer as a note with both wordings and their paths, since code cannot tell whether two wordings say one thing, and hold nothing back. Ids of a set are unique inside one place only: for plates of two places only the conditions of the use are compared — ground, and the words of time of day, weather and atmosphere. In one place: where every object stands, which way it faces and whether it is there (an object that stands in neither plate is in the same state in both) — always when the entry keeps one layout, otherwise when objects is not listed; the condition of every object that stands in both plates and of every part of the architecture, when objects is not listed; the camera, held to one frame when the entry says so, otherwise to the same stated data when camera is not listed — a value one plate states and the other leaves out is a difference of the data, a value neither states is not; and the light: of two plates of one frame every value of the lighting plan, of two frames only what belongs to the place (MotoSetDesign::look), with their words compared by MotoSetDesign::wording() once the values agree. Words are compared as MotoSetDesign::comparable() reads them
     */
    private static function pairIssues(string $label, array $entry, array $free, array $first, array $other, array $states): array
    {
        $issues = [];
        $need = static function (string $code, string $message) use (&$issues): void {
            $issues[] = Finding::requirement("MotoLocationsCheck.setDesignIssues.{$code}", $message);
        };
        $pair = "{$first['id']} và {$other['id']}";
        $differs = [];
        $worded = [];
        $word = static function (string $allow, string $path, mixed $mine, mixed $theirs) use (&$worded, $first, $other): void {
            $worded[] = ['allow' => $allow, 'text' => "backgrounds.{$first['id']}.{$path} (".json_encode($mine, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).') và backgrounds.'.$other['id'].'.'.$path.' ('.json_encode($theirs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).')'];
        };
        $onePlace = $first['location'] === $other['location'];
        $oneFrame = false;

        if ($onePlace) {
            $layout = static fn (array $plate): array => array_column(array_map(static fn (array $object): array => ['id' => $object['id'], 'at' => $object['present'] ? [true, MotoSetDesign::comparable($object['position']), MotoSetDesign::comparable($object['facing'])] : [false]], $states[$plate['id']]['objects'] ?? []), 'at', 'id');
            $moved = array_keys(array_filter($layout($first), static fn (array $at, string $object): bool => ($layout($other)[$object] ?? null) !== $at, ARRAY_FILTER_USE_BOTH));

            if ($moved !== [] && $entry['same_layout']) {
                $need('23', "{$label} khai cùng bố trí nhưng {$pair} khác nhau ở ".implode(', ', $moved).' — bỏ same_layout và thêm objects vào may_change, hoặc bỏ thay đổi đó.');
            } elseif ($moved !== [] && ! in_array('objects', $free, true)) {
                $differs[] = 'objects ('.implode(', ', $moved).')';
            }

            if (! in_array('objects', $free, true)) {
                $condition = static fn (array $plate): array => array_map(MotoSetDesign::comparable(...), array_column(array_filter($states[$plate['id']]['objects'] ?? [], static fn (array $object): bool => (bool) $object['present']), 'condition', 'id')
                    + array_column($states[$plate['id']]['architecture'] ?? [], 'state', 'id'));
                $kept = array_intersect_key($condition($first), $condition($other));
                $changed = array_keys(array_filter($kept, static fn (mixed $state, string $ref): bool => $condition($other)[$ref] !== $state, ARRAY_FILTER_USE_BOTH));

                if ($changed !== []) {
                    $differs[] = 'objects.condition ('.implode(', ', $changed).')';
                }
            }

            [$differ, $unstated, $partial, $cameraWords] = self::cameraDifference((array) $first['camera'], (array) $other['camera']);
            $oneFrame = $entry['same_camera'] || ($differ === [] && $partial === [] && $cameraWords === []);

            if ($entry['same_camera'] && $differ !== []) {
                $need('24', "{$label} khai cùng camera nhưng {$pair} khác nhau ở ".implode(', ', $differ).'.');
            }

            if ($entry['same_camera'] && $unstated !== []) {
                $need('25', "{$label} khai cùng camera nhưng {$pair} chưa nêu ".implode(', ', $unstated).' — chưa đủ dữ liệu xác minh cùng camera: hai giá trị bỏ trống không chứng minh hai khung trùng nhau, câu chữ cũng không.');
            }

            if (! $entry['same_camera'] && ! in_array('camera', $free, true) && [...$differ, ...$partial] !== []) {
                $differs[] = 'camera ('.implode(', ', [...$differ, ...$partial]).')';
            }

            if ($entry['same_camera'] || ! in_array('camera', $free, true)) {
                foreach ($cameraWords as [$field, $mine, $theirs]) {
                    $word('camera', "camera.{$field}", $mine, $theirs);
                }
            }
        }

        $differs = [...$differs, ...array_values(array_filter(self::CONDITIONS, static fn (string $field): bool => ! in_array($field, $free, true) && MotoSetDesign::comparable($first[$field]) !== MotoSetDesign::comparable($other[$field])))];

        foreach (self::WORDED_CONDITIONS as $field) {
            if (! in_array($field, $free, true) && MotoSetDesign::comparable($first[$field]) !== MotoSetDesign::comparable($other[$field])) {
                $word($field, $field, $first[$field], $other[$field]);
            }
        }

        if ($onePlace && ! in_array('light', $free, true)) {
            if (MotoSetDesign::look((array) $first['lighting_plan'], $oneFrame) != MotoSetDesign::look((array) $other['lighting_plan'], $oneFrame)) {
                $differs[] = 'light';
            } else {
                foreach (MotoSetDesign::wording((array) $first['lighting_plan'], (array) $other['lighting_plan'], $oneFrame) as [$path, $mine, $otherPath, $theirs]) {
                    $worded[] = ['allow' => 'light', 'text' => "backgrounds.{$first['id']}.lighting_plan.{$path} (".json_encode($mine, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).") và backgrounds.{$other['id']}.lighting_plan.{$otherPath} (".json_encode($theirs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).')'];
                }
            }
        }

        if ($differs !== []) {
            $need('27', "{$label}: {$pair} khác nhau ở ".implode(', ', $differs).' nhưng may_change không cho phép — khai thay đổi đó là được phép hoặc giữ nguyên.');
        }

        if ($worded !== []) {
            $issues[] = Finding::note('MotoLocationsCheck.setDesignIssues.30', "{$label}: {$pair} viết khác nhau ở ".implode('; ', array_column($worded, 'text')).' — may_change không có '.implode(', ', array_values(array_unique(array_column($worded, 'allow')))).'. Code không xác minh được hai cách viết có nhất quán không: người duyệt xem lại; viết y như nhau nếu không đổi, hoặc khai thay đổi đó là được phép.');
        }

        return $issues;
    }

    /** @var list<string> the subjects a build state itself puts in the place — the motorcycle as built and the physical model: standing in the subject zone is what the beat shows of them. A part a beat names may be on the motorcycle, loose or drawn, which the input does not say */
    private const PLACED = ['motorcycle', 'model'];

    /** @var string the situation of a beat that shows the motorcycle and its parts as a representation — a drawing, a screen, a model — never as the things themselves (MotoScript situation.mode) */
    private const REPRESENTATION = 'representation';

    /**
     * @param  array<string, mixed>  $data  the data of a location dossier
     * @param  array<string, mixed>  $context  the check context pinned with the run: its beats with their subjects and the size of the motorcycle
     * @return list<array{verdict: string, blocking: bool, kind: string, location: string, backgrounds: list<string>, beats: list<string>, message: string, note: string, evidence: list<array{path: string, value: mixed}>}> every contradiction and every unverified fit of fit(), with the plates and beats it reaches, the message shown to the reviewer, the note handed to the next step and the numbers it rests on
     */
    public static function usability(array $data, array $context): array
    {
        return self::fit($data, $context)['findings'];
    }

    /**
     * Whether the numbers of a location dossier let its places hold their subjects, read from the numbers alone. For every
     * plate whose beats show a subject, each comparison the layout calls for ends one of three ways and none is passed over
     * in silence: it was made and holds (told under verified), it was made and fails (a contradiction), or a number it needs
     * is not stated (unverified). The comparisons: the plate names a subject zone with a footprint and an axis; that zone
     * lies inside the place along its length and its width; it is clear of every blocked zone; every subject of the beats,
     * each on its own, fits it along the axis and stands under the ceiling; and every route the dossier gives the
     * motorcycle is as wide as the motorcycle. A plate fits only when no comparison failed or went unverified. It never
     * simulates a movement: enough floor proves no turn. Only valid numbers are read — a footprint that is no rectangle
     * on the floor, a footprint in a place that states no coordinate system to read it in, and a measurement that does
     * not agree with its basis count as not stated.
     *
     * A number nothing states never passes. It holds the plates it reaches when an action the pinned script requires
     * cannot be shown possible without it, whoever owns the number: in a place that bounds where a subject stands
     * (walls, a roof or a numbered plan of its own) the subject zone, its footprint and axis, and the length and width of
     * the motorcycle or the model (PLACED); under walls or a roof the length and width of the place; in a room its height
     * and the height of the motorcycle or the model; and, where a beat shows the motorcycle, the clear width of a route
     * the dossier gives it and the width of the motorcycle. It is told and holds nothing where nothing shows the number
     * is needed: open ground with no plan, a place only partly roofed, a route at a place no beat shows the motorcycle
     * in, a blocked zone with no footprint, and the size of a part a beat names.
     *
     * A part named by a beat the script shows as a representation is a drawing or a model of itself, so its real size is
     * compared with nothing; which parts were left out so is told under skipped, never passed over.
     *
     * @param  array<string, mixed>  $data  the data of a location dossier
     * @param  array<string, mixed>  $context  the check context pinned with the run: its beats with their situation and their subjects, and the size of the motorcycle
     * @return array{findings: list<array<string, mixed>>, verified: array<string, list<string>>, skipped: array<string, list<string>>} the findings usability() returns, and for each plate by id what was compared and holds and what was not compared because the script shows it only as a representation, in the words shown to the reviewer
     */
    public static function fit(array $data, array $context): array
    {
        $findings = [];
        $verified = [];
        $skipped = [];
        $beats = array_column((array) ($context['beats'] ?? []), null, 'id');
        $vehicleWidth = self::positive($context['motorcycle']['size']['width_m'] ?? null);
        $furnished = ($context['format'] ?? null) === MotoContract::LOCATIONS_FORMAT_V4;
        $plates = [];

        foreach ((array) ($data['backgrounds'] ?? []) as $background) {
            $plates[$background['location']][] = $background;
        }

        foreach ((array) ($data['locations'] ?? []) as $place) {
            $id = (string) $place['id'];
            $mine = $plates[$id] ?? [];
            $zones = array_column((array) $place['zones'], null, 'id');
            $over = static fn (array $hit): array => [array_values(array_map('strval', array_column($hit, 'id'))), array_values(array_unique(array_merge([], ...array_map(static fn (array $plate): array => array_column((array) $plate['uses'], 'beat'), $hit))))];
            $reach = static fn (string $zone): array => $over(array_values(array_filter($mine, static fn (array $plate): bool => $plate['subject_zone'] === $zone)) ?: $mine);
            $add = static function (string $verdict, bool $blocking, array $reach, string $message, string $note, array $evidence) use (&$findings, $id): void {
                $findings[] = [
                    'verdict' => $verdict,
                    'blocking' => $blocking,
                    'kind' => $verdict === self::CONTRADICTS ? 'source_conflict' : 'missing_information',
                    'location' => $id,
                    'backgrounds' => $reach[0],
                    'beats' => $reach[1],
                    'message' => $message,
                    'note' => $note,
                    'evidence' => $evidence,
                ];
            };
            $size = array_map(static fn (array $dimension): ?float => self::measure($dimension), (array) $place['dimensions']);
            $print = static fn (array $zone): ?array => $place['coordinate_system'] === null ? null : self::footprint($zone);

            foreach ($zones as $zoneId => $zone) {
                $footprint = $print($zone);

                if ($footprint === null) {
                    continue;
                }

                foreach ([['x_m', 'size_x_m', 'length_m'], ['y_m', 'size_y_m', 'width_m']] as [$from, $extent, $side]) {
                    if (($size[$side] ?? null) !== null && $footprint[$from] + $footprint[$extent] > $size[$side] + self::TOLERANCE_M) {
                        $add(self::CONTRADICTS, true, $reach((string) $zoneId),
                            "{$id} {$zoneId}: vùng chiếm tới ".($footprint[$from] + $footprint[$extent])." m theo {$from}, vượt {$side} {$size[$side]} m của địa điểm.",
                            "Zone {$zoneId} of {$id} reaches ".($footprint[$from] + $footprint[$extent])." m along {$from}, beyond the {$size[$side]} m the place states as its {$side}.",
                            [['path' => "locations.{$id}.zones.{$zoneId}.footprint", 'value' => $footprint], ['path' => "locations.{$id}.dimensions.{$side}", 'value' => $size[$side]]]);
                    }
                }

                if ($zone['kind'] !== 'subject') {
                    continue;
                }

                foreach ($zones as $otherId => $other) {
                    $blocked = $print($other);

                    if ($other['kind'] !== 'blocked' || $blocked === null) {
                        continue;
                    }

                    if (self::overlap($footprint, $blocked)) {
                        $add(self::CONTRADICTS, true, $reach((string) $zoneId),
                            "{$id}: vùng đặt chủ thể {$zoneId} chồng lên vùng không dùng được {$otherId}.",
                            "Subject zone {$zoneId} of {$id} overlaps blocked zone {$otherId}.",
                            [['path' => "locations.{$id}.zones.{$zoneId}.footprint", 'value' => $footprint], ['path' => "locations.{$id}.zones.{$otherId}.footprint", 'value' => $blocked]]);
                    }
                }
            }

            $read = array_combine(array_map('strval', array_column($mine, 'id')), array_map(static fn (array $plate): array => self::subjects($plate, $beats), $mine));
            $shown = array_map(static fn (array $pair): array => $pair[0], $read);

            foreach ($read as $key => [, $drawn]) {
                if ($drawn !== []) {
                    $skipped[$key][] = count($drawn).' bộ phận chỉ xuất hiện ở beat tái hiện (representation), là bản vẽ hoặc mô hình chứ không phải vật đặt trong địa điểm: không so kích thước thật ('.implode(', ', $drawn).')';
                }
            }
            $riders = array_values(array_filter($mine, static fn (array $plate): bool => in_array('motorcycle', array_column($shown[(string) $plate['id']], 'ref'), true)));
            $ridden = $over($riders);
            $rides = $riders !== [];
            $pass = static function (string $line, array $hit) use (&$verified): void {
                foreach ($hit as $plate) {
                    $verified[(string) $plate['id']][] = $line;
                }
            };

            foreach ((array) $place['access_routes'] as $route) {
                if (! in_array('vehicle', (array) $route['used_by'], true)) {
                    continue;
                }

                $clear = self::measure((array) $route['clear_width_m']);
                $width = "locations.{$id}.access_routes.{$route['id']}.clear_width_m";

                if ($clear === null || $vehicleWidth === null) {
                    $add(self::UNVERIFIED, $rides, $ridden,
                        "{$id} {$route['id']}: xe dùng lối này nhưng ".($clear === null ? 'chưa khai bề rộng thông thuỷ' : 'thiết kế chưa cho bề rộng xe').' — chưa xác minh xe đi lọt'.($rides ? '.' : '; không beat nào ở đây có xe nên không giữ ảnh nền nào.'),
                        "Route {$route['id']} of {$id} is used by the motorcycle but ".($clear === null ? 'states no clear width' : 'the design gives no width of the motorcycle').': whether the motorcycle passes is not verified.',
                        [['path' => $width, 'value' => $clear], ['path' => 'motorcycle.size.width_m', 'value' => $vehicleWidth]]);
                } elseif ($clear + self::TOLERANCE_M < $vehicleWidth) {
                    $add(self::CONTRADICTS, $rides, $ridden,
                        "{$id} {$route['id']}: lối rộng {$clear} m, hẹp hơn xe rộng {$vehicleWidth} m.",
                        "Route {$route['id']} of {$id} is {$clear} m wide, narrower than the motorcycle at {$vehicleWidth} m.",
                        [['path' => $width, 'value' => $clear], ['path' => 'motorcycle.size.width_m', 'value' => $vehicleWidth]]);
                } else {
                    $pass("lối {$route['id']} rộng {$clear} m đủ cho xe rộng {$vehicleWidth} m", $riders);
                }
            }

            $bounded = $place['setting'] !== 'exterior' || $place['coordinate_system'] !== null;
            $walled = $place['setting'] !== 'exterior';
            $roofed = $place['setting'] === 'interior';
            $height = $size['height_m'] ?? null;
            $unbound = '; địa điểm ngoài trời không có sơ đồ số nên không giữ ảnh nền.';
            $named = '; bộ phận chỉ được nêu tên, đầu vào không nói nó đứng trong vùng, nên không giữ ảnh nền.';

            foreach ($mine as $plate) {
                $subjects = $shown[(string) $plate['id']];
                $own = $over([$plate]);
                $zone = $plate['subject_zone'] === null ? null : ($zones[$plate['subject_zone']] ?? null);
                $zone = $zone !== null && $zone['kind'] === 'subject' ? $zone : null;
                $footprint = $zone === null ? null : $print($zone);

                if ($furnished) {
                    $objectFit = self::objectFit($place, $plate, $zones, $zone, $footprint, $size, $print, $bounded);

                    foreach ($objectFit['findings'] as [$verdict, $blocking, $message, $note, $evidence]) {
                        $add($verdict, $blocking, $own, "{$plate['id']}: {$message}", $note, $evidence);
                    }

                    if ($objectFit['verified'] !== null) {
                        $pass($objectFit['verified'], [$plate]);
                    }
                }

                if ($subjects === []) {
                    continue;
                }

                $at = static fn (array $subject): string => "beats.{$subject['beat']}.subjects.{$subject['ref']}";
                $names = implode(', ', array_values(array_unique(array_column($subjects, 'ref'))));
                $axis = $zone['subject_axis'] ?? null;

                if ($zone === null) {
                    $add(self::UNVERIFIED, $bounded, $own,
                        "{$plate['id']}: ảnh nền ".($plate['subject_zone'] === null ? 'không khai subject_zone' : "khai subject_zone {$plate['subject_zone']} không phải vùng đặt chủ thể của {$id}")." trong khi beat của nó có chủ thể ({$names}) — chưa xác minh chỗ đặt".($bounded ? '.' : $unbound),
                        "Plate {$plate['id']} of {$id} names no subject zone of its place while its beats show {$names}: where they stand and whether they fit is not verified.",
                        [['path' => "backgrounds.{$plate['id']}.subject_zone", 'value' => $plate['subject_zone']]]);
                } elseif ($footprint === null || $axis === null) {
                    $add(self::UNVERIFIED, $bounded, $own,
                        "{$plate['id']}: vùng {$zone['id']} của {$id} ".($footprint === null ? 'chưa có footprint dùng được' : 'chưa khai subject_axis')." — chưa xác minh đặt vừa {$names}".($bounded ? '.' : $unbound),
                        "Subject zone {$zone['id']} of {$id} states no ".($footprint === null ? 'footprint' : 'subject axis').": whether {$names} fit there is not verified.",
                        [['path' => "locations.{$id}.zones.{$zone['id']}.".($footprint === null ? 'footprint' : 'subject_axis'), 'value' => null]]);
                } else {
                    foreach ([['x_m', 'size_x_m', 'length_m', 'chiều dài'], ['y_m', 'size_y_m', 'width_m', 'chiều rộng']] as [$from, $extent, $side, $label]) {
                        if (($size[$side] ?? null) === null) {
                            if ($walled) {
                                $add(self::UNVERIFIED, true, $own,
                                    "{$plate['id']}: {$id} chưa khai {$label} — chưa xác minh vùng {$zone['id']} nằm trong địa điểm.",
                                    "{$id} states no {$side}: whether subject zone {$zone['id']} lies inside the place is not verified.",
                                    [['path' => "locations.{$id}.dimensions.{$side}", 'value' => null], ['path' => "locations.{$id}.zones.{$zone['id']}.footprint", 'value' => $footprint]]);
                            }
                        } elseif ($footprint[$from] + $footprint[$extent] <= $size[$side] + self::TOLERANCE_M) {
                            $pass("vùng {$zone['id']} nằm trong {$label} {$size[$side]} m của địa điểm", [$plate]);
                        }
                    }

                    foreach ($zones as $otherId => $other) {
                        if ($other['kind'] !== 'blocked') {
                            continue;
                        }

                        $blocked = $print($other);

                        if ($blocked === null) {
                            $add(self::UNVERIFIED, false, $own,
                                "{$plate['id']}: vùng không dùng được {$otherId} của {$id} chưa có footprint — chưa xác minh nó không chồng vùng chủ thể {$zone['id']}; vị trí của nó chỉ tả bằng chữ nên không giữ ảnh nền.",
                                "Blocked zone {$otherId} of {$id} states no footprint: whether it stays clear of subject zone {$zone['id']} is not verified.",
                                [['path' => "locations.{$id}.zones.{$otherId}.footprint", 'value' => null]]);
                        } elseif (! self::overlap($footprint, $blocked)) {
                            $pass("vùng {$zone['id']} không chồng vùng không dùng được {$otherId}", [$plate]);
                        }
                    }

                    [$along, $across] = $axis === 'x' ? [$footprint['size_x_m'], $footprint['size_y_m']] : [$footprint['size_y_m'], $footprint['size_x_m']];
                    $fitting = [];

                    foreach ($subjects as $subject) {
                        if ($subject['length_m'] === null || $subject['width_m'] === null) {
                            $placed = in_array($subject['ref'], self::PLACED, true);
                            $add(self::UNVERIFIED, $placed && $bounded, $own,
                                "{$plate['id']}: thiết kế chưa cho chiều dài và chiều rộng của {$subject['ref']} ở {$subject['beat']} — chưa xác minh đặt vừa vùng {$zone['id']} của {$id}".match (true) {
                                    ! $placed => $named,
                                    ! $bounded => $unbound,
                                    default => '.',
                                },
                                "The design gives no length and width for {$subject['ref']} at {$subject['beat']}: whether it fits subject zone {$zone['id']} of {$id} is not verified.",
                                [['path' => $at($subject).'.length_m', 'value' => $subject['length_m']], ['path' => $at($subject).'.width_m', 'value' => $subject['width_m']]]);
                        } elseif ($along + self::TOLERANCE_M < $subject['length_m'] || $across + self::TOLERANCE_M < $subject['width_m']) {
                            $add(self::CONTRADICTS, true, $own,
                                "{$plate['id']}: vùng {$zone['id']} của {$id} dài {$along} m, rộng {$across} m theo trục {$axis}, không chứa vừa {$subject['ref']} dài {$subject['length_m']} m, rộng {$subject['width_m']} m ở {$subject['beat']}.",
                                "Subject zone {$zone['id']} of {$id} is {$along} m by {$across} m along axis {$axis} and cannot hold {$subject['ref']} at {$subject['length_m']} m by {$subject['width_m']} m.",
                                [['path' => "locations.{$id}.zones.{$zone['id']}.footprint", 'value' => $footprint], ['path' => $at($subject).'.length_m', 'value' => $subject['length_m']], ['path' => $at($subject).'.width_m', 'value' => $subject['width_m']]]);
                        } else {
                            $fitting[] = $subject;
                        }
                    }

                    if ($fitting !== []) {
                        $pass(count($fitting)." chủ thể vừa vùng {$zone['id']} dài {$along} m, rộng {$across} m theo trục {$axis} (lớn nhất dài ".max(array_column($fitting, 'length_m')).' m, rộng '.max(array_column($fitting, 'width_m')).' m)', [$plate]);
                    }
                }

                if ($height === null) {
                    if ($walled) {
                        $add(self::UNVERIFIED, $roofed, $own,
                            "{$plate['id']}: {$id} chưa khai chiều cao — chưa xác minh {$names} đứng lọt dưới trần".($roofed ? '.' : '; địa điểm chỉ có mái một phần nên không giữ ảnh nền.'),
                            "{$id} states no height: whether {$names} stand under its ceiling is not verified.",
                            [['path' => "locations.{$id}.dimensions.height_m", 'value' => null]]);
                    }

                    continue;
                }

                $under = [];

                foreach ($subjects as $subject) {
                    if ($subject['height_m'] === null) {
                        $placed = in_array($subject['ref'], self::PLACED, true);
                        $add(self::UNVERIFIED, $placed && $roofed, $own,
                            "{$plate['id']}: thiết kế chưa cho chiều cao của {$subject['ref']} ở {$subject['beat']} — chưa xác minh đứng lọt dưới trần {$height} m của {$id}".match (true) {
                                ! $placed => $named,
                                ! $roofed => '; địa điểm không phải phòng kín nên không giữ ảnh nền.',
                                default => '.',
                            },
                            "The design gives no height for {$subject['ref']} at {$subject['beat']}: whether it stands under the {$height} m ceiling of {$id} is not verified.",
                            [['path' => $at($subject).'.height_m', 'value' => null], ['path' => "locations.{$id}.dimensions.height_m", 'value' => $height]]);
                    } elseif ($height + self::TOLERANCE_M < $subject['height_m']) {
                        $add(self::CONTRADICTS, true, $own,
                            "{$plate['id']}: {$id} cao {$height} m, thấp hơn {$subject['ref']} cao {$subject['height_m']} m ở {$subject['beat']}.",
                            "{$id} is {$height} m high, lower than {$subject['ref']} at {$subject['height_m']} m.",
                            [['path' => "locations.{$id}.dimensions.height_m", 'value' => $height], ['path' => $at($subject).'.height_m', 'value' => $subject['height_m']]]);
                    } else {
                        $under[] = $subject;
                    }
                }

                if ($under !== []) {
                    $pass(count($under)." chủ thể đứng lọt dưới trần {$height} m (cao nhất ".max(array_column($under, 'height_m')).' m)', [$plate]);
                }
            }
        }

        return ['findings' => $findings, 'verified' => $verified, 'skipped' => $skipped];
    }

    /** @var list<string> the kinds of zone whose floor an action needs clear: where people work, where the motorcycle or people pass and where a camera stands or travels */
    private const CLEARANCE = ['work', 'route', 'camera'];

    /**
     * The objects of a set design held to the floor of one plate, in the state the set stands in for it. An object whose numbers
     * place it is compared: it must lie inside the length and width of the place, it must not stand in the subject zone unless it
     * is a support of the subject, and it must not stand in a zone where people work, pass or film unless that zone is its own.
     * An object a plate moves by words keeps its size but has no numbers to place it: it is never read as clear. It is told as
     * unverified, and it holds the plate when its base numbers put it in that floor — the move is what must clear it, and nothing
     * shows it does — in a place that bounds where a subject stands.
     *
     * @param  array<string, mixed>  $place  a place of a moto-locations-v4 dossier
     * @param  array<string, mixed>  $plate  a plate on it
     * @param  array<string, array<string, mixed>>  $zones  the zones of the place by id
     * @param  ?array<string, mixed>  $zone  the subject zone of the plate, null when it names none
     * @param  ?array<string, mixed>  $footprint  the valid footprint of that zone, null when it has none
     * @param  array<string, ?float>  $size  the valid length, width and height of the place
     * @param  \Closure(array<string, mixed>): ?array<string, mixed>  $print  the valid footprint of a zone or an object in a place that states a coordinate system
     * @param  bool  $bounded  the place bounds where a subject stands
     * @return array{findings: list<array{0: string, 1: bool, 2: string, 3: string, 4: list<array{path: string, value: mixed}>}>, verified: ?string} every contradiction and every unverified object, each with its verdict, whether it holds the plate, the message, the note and the numbers it rests on; and the line told under verified when objects were compared and held, null when none was
     */
    private static function objectFit(array $place, array $plate, array $zones, ?array $zone, ?array $footprint, array $size, \Closure $print, bool $bounded): array
    {
        $id = (string) $place['id'];
        $findings = [];
        $clear = [];
        $held = [];
        $needed = array_filter(array_map(static fn (array $other): ?array => in_array($other['kind'], self::CLEARANCE, true) ? $print($other) : null, $zones));
        $path = static fn (array $object): string => "locations.{$id}.set_design.objects.{$object['id']}";
        $inTheWay = static function (array $object, array $floor) use ($footprint, $needed): array {
            $hits = [];

            if ($footprint !== null && $object['role'] !== 'support' && self::overlap($footprint, $floor)) {
                $hits[] = 'subject';
            }

            foreach ($needed as $other => $space) {
                if ((string) $other !== (string) ($object['zone'] ?? '') && self::overlap($space, $floor)) {
                    $hits[] = (string) $other;
                }
            }

            return $hits;
        };

        foreach (MotoSetDesign::state($place, $plate)['objects'] as $object) {
            if (! $object['present']) {
                continue;
            }

            $base = $print(['footprint' => $object['base_footprint']]);

            if ($object['unplaced'] && $base !== null) {
                $was = $inTheWay($object, $base);
                $holds = $was !== [] && $bounded;
                $findings[] = [self::UNVERIFIED, $holds,
                    "vật thể {$object['id']} ({$object['name']}) được dời bằng chữ, không còn số đo định vị — chưa xác minh nó không cản sàn cần trống".($was === [] ? '; ở trạng thái chuẩn nó cũng không cản nên không giữ ảnh nền.' : ($holds ? ' (ở trạng thái chuẩn nó chiếm '.implode(', ', array_map(static fn (string $hit): string => $hit === 'subject' ? 'vùng chủ thể '.($zone['id'] ?? '') : "vùng {$hit}", $was)).').' : '; địa điểm ngoài trời không có sơ đồ số nên không giữ ảnh nền.')),
                    "Object {$object['id']} of {$id} is moved by words and no number places it now: whether it keeps the floor clear is not verified.",
                    [['path' => "{$path($object)}.footprint", 'value' => $object['base_footprint']], ['path' => "backgrounds.{$plate['id']}.set_state", 'value' => $object['position']]]];

                continue;
            }

            $floor = $print($object);

            if ($floor === null) {
                continue;
            }

            $beyond = array_values(array_filter([['x_m', 'size_x_m', 'length_m'], ['y_m', 'size_y_m', 'width_m']], static fn (array $side): bool => ($size[$side[2]] ?? null) !== null && $floor[$side[0]] + $floor[$side[1]] > $size[$side[2]] + self::TOLERANCE_M));
            $hits = $inTheWay($object, $floor);

            if ($beyond !== []) {
                $findings[] = [self::CONTRADICTS, true,
                    "vật thể {$object['id']} ({$object['name']}) của {$id} vượt ".implode(', ', array_column($beyond, 2)).' của địa điểm.',
                    "Object {$object['id']} of {$id} reaches beyond the ".implode(', ', array_column($beyond, 2)).' the place states.',
                    [['path' => "{$path($object)}.footprint", 'value' => $floor]]];
            } elseif ($hits !== []) {
                foreach ($hits as $hit) {
                    $findings[] = $hit === 'subject'
                        ? [self::CONTRADICTS, true,
                            "vật thể {$object['id']} ({$object['name']}) chiếm vùng chủ thể {$zone['id']} của {$id} — chỉ vật đỡ chủ thể mới đứng trong vùng này.",
                            "Object {$object['id']} of {$id} stands in subject zone {$zone['id']} and is no support of the subject.",
                            [['path' => "{$path($object)}.footprint", 'value' => $floor], ['path' => "locations.{$id}.zones.{$zone['id']}.footprint", 'value' => $footprint]]]
                        : [self::CONTRADICTS, true,
                            "vật thể {$object['id']} ({$object['name']}) chiếm vùng {$hit} ({$zones[$hit]['kind']}) của {$id}, không phải vùng của nó — lối đi, vùng thao tác và vùng camera phải trống.",
                            "Object {$object['id']} of {$id} stands in {$zones[$hit]['kind']} zone {$hit}, which is not its own zone.",
                            [['path' => "{$path($object)}.footprint", 'value' => $floor], ['path' => "locations.{$id}.zones.{$hit}.footprint", 'value' => $needed[$hit]]]];
                }
            } else {
                $clear[] = $object['id'];
                $held = [...$held, ...($footprint !== null && $object['role'] === 'support' && self::overlap($footprint, $floor) ? ["vật đỡ {$object['id']} đứng trong vùng chủ thể"] : [])];
            }
        }

        return [
            'findings' => $findings,
            'verified' => $clear === [] ? null : implode('; ', [count($clear).' vật thể có số đo nằm trong địa điểm và không cản sàn cần trống'.($zone === null ? '' : " (vùng chủ thể {$zone['id']}, lối đi, vùng thao tác, vùng camera)"), ...$held]),
        ];
    }

    /**
     * @param  array{x_m: float, y_m: float, size_x_m: float, size_y_m: float}  $one  a footprint
     * @param  array{x_m: float, y_m: float, size_x_m: float, size_y_m: float}  $other  another footprint of the same place
     * @return bool whether the two rectangles share floor beyond the tolerance of two stated numbers
     */
    private static function overlap(array $one, array $other): bool
    {
        $x = min($one['x_m'] + $one['size_x_m'], $other['x_m'] + $other['size_x_m']) - max($one['x_m'], $other['x_m']);
        $y = min($one['y_m'] + $one['size_y_m'], $other['y_m'] + $other['size_y_m']) - max($one['y_m'], $other['y_m']);

        return $x > self::TOLERANCE_M && $y > self::TOLERANCE_M;
    }

    /**
     * @param  array<string, mixed>  $plate  a plate of a location dossier
     * @param  array<string, array<string, mixed>>  $beats  the beats of the pinned context by id
     * @return array{0: list<array{ref: string, beat: string, length_m: ?float, width_m: ?float, height_m: ?float}>, 1: list<string>} every subject the beats of the plate show as the thing itself, once for each size it is shown at, with the first beat that shows it so (a size the design does not give is null); and the parts those beats show only as a representation. In a beat whose situation the script gives as REPRESENTATION a named part is a drawing, a screen or a model of itself: its real size is the size of nothing that stands in the place, so it is not compared. The motorcycle and the physical model are always the thing itself
     */
    private static function subjects(array $plate, array $beats): array
    {
        $subjects = [];
        $drawn = [];

        foreach (array_unique(array_map('strval', array_column((array) $plate['uses'], 'beat'))) as $beat) {
            $represented = ($beats[$beat]['situation']['mode'] ?? null) === self::REPRESENTATION;

            foreach ((array) ($beats[$beat]['subjects'] ?? []) as $subject) {
                if ($represented && ! in_array($subject['ref'], self::PLACED, true)) {
                    $drawn[(string) $subject['ref']] = true;

                    continue;
                }

                $size = array_map(static fn (string $side): ?float => self::positive($subject['size'][$side] ?? null), ['length_m' => 'length_m', 'width_m' => 'width_m', 'height_m' => 'height_m']);
                $subjects[$subject['ref'].'|'.json_encode($size)] ??= ['ref' => (string) $subject['ref'], 'beat' => $beat] + $size;
            }
        }

        return [array_values($subjects), array_values(array_diff(array_map('strval', array_keys($drawn)), array_column($subjects, 'ref')))];
    }

    /** @return ?float the number when it is one above zero, null for anything else */
    private static function positive(mixed $value): ?float
    {
        return is_numeric($value) && $value > 0 ? (float) $value : null;
    }

    /**
     * @param  array<string, mixed>  $dimension  a measurement of a dossier: its value and its basis
     * @return ?float the metres it states, null when its basis is unknown or its value is no number above zero
     */
    private static function measure(array $dimension): ?float
    {
        return ($dimension['basis'] ?? null) === 'unknown' ? null : self::positive($dimension['value'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $dimension  a measurement of a dossier
     * @return ?string why its value does not agree with its basis — a value under basis unknown, or no number above zero under any other basis; null when they agree
     */
    private static function dimensionProblem(array $dimension): ?string
    {
        $basis = (string) ($dimension['basis'] ?? '');
        $value = $dimension['value'] ?? null;

        return match (true) {
            $basis === 'unknown' && $value !== null => 'basis là unknown nhưng value là '.json_encode($value).' — số chưa biết thì value phải là null.',
            $basis !== 'unknown' && self::positive($value) === null => "basis là {$basis} thì value phải là số lớn hơn 0, đang là ".json_encode($value).'.',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $zone  a zone of a dossier
     * @return ?array{x_m: float, y_m: float, size_x_m: float, size_y_m: float, basis: mixed} its footprint when it is a rectangle on the floor — a corner at or above zero on both axes and an extent above zero along each; null when the zone states none or its numbers are no such rectangle
     */
    private static function footprint(array $zone): ?array
    {
        $footprint = $zone['footprint'] ?? null;

        if (! is_array($footprint)) {
            return null;
        }

        foreach (['x_m', 'y_m'] as $corner) {
            if (! is_numeric($footprint[$corner] ?? null) || $footprint[$corner] < 0) {
                return null;
            }
        }

        if (self::positive($footprint['size_x_m'] ?? null) === null || self::positive($footprint['size_y_m'] ?? null) === null) {
            return null;
        }

        return ['x_m' => (float) $footprint['x_m'], 'y_m' => (float) $footprint['y_m'], 'size_x_m' => (float) $footprint['size_x_m'], 'size_y_m' => (float) $footprint['size_y_m'], 'basis' => $footprint['basis'] ?? null];
    }

    /**
     * @param  list<string>  $schema
     * @param  list<string>  $issues
     * @param  list<string>  $warnings
     * @return array{version: string, format: string, verdict: string, status: ?string, error: ?string, schema: list<string>, issues: list<string>, warnings: list<string>, judge: string}
     */
    private static function result(string $verdict, ?string $status, ?string $error, array $schema = [], array $issues = [], array $warnings = []): array
    {
        return [
            'version' => self::VERSION,
            'format' => MotoContract::LOCATIONS_FORMAT,
            'verdict' => $verdict,
            'status' => $status,
            'error' => $error,
            'schema' => $schema,
            ...Finding::fields($issues, $warnings),
            'judge' => self::JUDGE,
        ];
    }
}
