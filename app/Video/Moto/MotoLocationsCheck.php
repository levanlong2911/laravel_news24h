<?php

declare(strict_types=1);

namespace App\Video\Moto;

final class MotoLocationsCheck
{
    public const VERSION = 'moto-locations-check-v3';

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
        $format = ($context['format'] ?? null) === MotoContract::LOCATIONS_FORMAT_V2 ? MotoContract::LOCATIONS_FORMAT_V2 : MotoContract::LOCATIONS_FORMAT;

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
            $issues[] = 'project_id không khớp lượt chạy.';
        }

        if (($response['prompt_version'] ?? null) !== ($context['prompt_version'] ?? null)) {
            $issues[] = 'prompt_version là "'.($response['prompt_version'] ?? '').'"; lượt chạy này dùng '.($context['prompt_version'] ?? '').'.';
        }

        $data = (array) $response['data'];
        $beats = array_column((array) ($context['beats'] ?? []), null, 'id');
        $places = [];

        foreach ((array) $data['locations'] as $place) {
            if (isset($places[$place['id']])) {
                $issues[] = "{$place['id']} khai hai lần.";
            }

            $places[$place['id']] = $place;
        }

        [$backgroundIssues, $served] = self::backgroundIssues((array) $data['backgrounds'], $places, $beats, (array) ($context['build_states'] ?? []), MotoScriptValidation::supportsByDesign($context));
        $used = [...array_column((array) $data['backgrounds'], 'location'), ...array_filter(array_column((array) $data['unplated'], 'location'), 'is_string')];
        $held = array_merge([], ...array_map(static fn (array $conflict): array => $conflict['blocking'] ? (array) $conflict['beats'] : [], (array) $data['conflicts']));
        $covered = [...array_merge([], ...array_values($served)), ...array_column((array) $data['unplated'], 'beat'), ...$held];

        foreach (array_keys($places) as $id) {
            if (! in_array($id, $used, true)) {
                $issues[] = "{$id} không có ảnh nền hay beat nào dùng.";
            }
        }

        foreach (array_keys($beats) as $id) {
            if (! in_array($id, $covered, true)) {
                $issues[] = "{$id} không đứng trên ảnh nền nào, không có trong unplated và không có xung đột chặn nào nêu nó.";
            }
        }

        foreach ((array) $data['unplated'] as $entry) {
            if (! isset($beats[$entry['beat']])) {
                $issues[] = "unplated nhắc {$entry['beat']}, không có trong kịch bản.";
            }

            if (is_string($entry['location']) && ! isset($places[$entry['location']])) {
                $issues[] = "unplated {$entry['beat']} nhắc {$entry['location']}, không có trong locations.";
            }
        }

        foreach ((array) $data['continuity'] as $entry) {
            $reach = [];

            foreach ($entry['backgrounds'] as $id) {
                if (! isset($served[$id])) {
                    $issues[] = "continuity nhắc {$id}, không có trong backgrounds.";
                }

                $reach = [...$reach, ...($served[$id] ?? [])];
            }

            foreach ($entry['beats'] as $id) {
                if (! in_array($id, $reach, true)) {
                    $issues[] = "continuity: {$id} không phải beat của ".implode(', ', $entry['backgrounds']).'.';
                }
            }
        }

        [$conflictIssues, $conflictWarnings, $blocking] = self::conflictIssues((array) $data['conflicts'], $beats, $served, (array) ($context['build_states'] ?? []));

        if ($blocking && $status !== 'partial') {
            $issues[] = 'Còn xung đột chặn nhưng status không phải partial.';
        }

        $issues = [
            ...$issues,
            ...$backgroundIssues,
            ...$conflictIssues,
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
                $issues[] = "{$id} có {$item}, nhưng thiết kế không có thiết bị tạm này ở build state nào.";
            }

            return $issues;
        }

        foreach ($mine as $beat) {
            $state = $beats[$beat]['state'] ?? null;

            foreach (array_diff($support, MotoSupportReferences::allowed($temporaryByState, is_string($state) ? $state : null, false)) as $item) {
                $issues[] = "{$id} có {$item}, nhưng {$beat} ở ".($state ?? 'không giai đoạn')." không có thiết bị tạm này.";
            }
        }

        return $issues;
    }

    private static function backgroundIssues(array $backgrounds, array $places, array $beats, array $states, bool $supportByDesign = false): array
    {
        $issues = [];
        $served = [];

        foreach ($backgrounds as $background) {
            $id = $background['id'];

            if (isset($served[$id])) {
                $issues[] = "{$id} khai hai lần.";
            }

            if (! isset($places[$background['location']])) {
                $issues[] = "{$id} đứng trên {$background['location']}, không có trong locations.";
            }

            $mine = [];
            $seen = [];

            foreach ($background['uses'] as $use) {
                if (! isset($beats[$use['beat']])) {
                    $issues[] = "{$id} dùng cho {$use['beat']}, không có trong kịch bản.";

                    continue;
                }

                $key = $use['beat'].'|'.$use['part'];

                if (isset($seen[$key])) {
                    $issues[] = "{$id} khai hai lần cùng phần \"{$use['part']}\" của {$use['beat']}.";
                }

                $seen[$key] = true;
                $mine[] = $use['beat'];
            }

            $mine = array_values(array_unique($mine));
            $served[$id] = [...($served[$id] ?? []), ...$mine];
            if ($background['light']['evidence'] !== null) {
                $issues = [...$issues, ...self::quoteIssues("{$id} light", $background['light']['evidence'], $mine, $beats)];
            }

            foreach ($background['details'] as $detail) {
                if ($detail['evidence'] !== null) {
                    $issues = [...$issues, ...self::quoteIssues("{$id} detail \"{$detail['text']}\"", $detail['evidence'], $mine, $beats)];
                }
            }

            foreach ($background['shot_changes'] as $change) {
                foreach (array_diff($change['beats'], $mine) as $beat) {
                    $issues[] = "{$id}: shot_changes nhắc {$beat}, không phải beat của ảnh nền này.";
                }
            }

            $issues = [...$issues, ...self::supportIssues((string) $id, (array) $background['support'], $mine, $beats, $states, $supportByDesign)];

        }

        return [$issues, $served];
    }

    /**
     * @param  array{beat: string, field: string, quote: string}  $evidence
     * @param  list<string>  $mine  the beats the background serves
     * @param  array<string, array<string, mixed>>  $beats
     * @return list<string>
     */
    private static function quoteIssues(string $label, array $evidence, array $mine, array $beats): array
    {
        if (! in_array($evidence['beat'], $mine, true)) {
            return ["{$label}: trích {$evidence['beat']}, không phải beat của ảnh nền này."];
        }

        return array_key_exists($evidence['field'], (array) ($beats[$evidence['beat']] ?? []))
            ? []
            : ["{$label}: {$evidence['beat']}.{$evidence['field']} không có trong dữ liệu đã ghim."];
    }

    /**
     * @param  list<array<string, mixed>>  $conflicts
     * @param  array<string, array<string, mixed>>  $beats
     * @param  array<string, list<string>>  $served
     * @param  array<string, array<string, mixed>>  $states
     * @return array{0: list<string>, 1: list<string>, 2: bool} the issues, the warnings that show a conflict which blocks nothing, and whether a blocking conflict is open
     */
    private static function conflictIssues(array $conflicts, array $beats, array $served, array $states): array
    {
        $issues = [];
        $warnings = [];
        $blocking = false;

        foreach ($conflicts as $index => $conflict) {
            $label = 'Xung đột '.($index + 1);

            if ($conflict['beats'] === [] && $conflict['backgrounds'] === []) {
                $issues[] = "{$label} không nêu beat hay ảnh nền nào bị ảnh hưởng.";
            }

            foreach ($conflict['beats'] as $beat) {
                if (! isset($beats[$beat])) {
                    $issues[] = "{$label} nhắc {$beat}, không có trong kịch bản.";
                }
            }

            foreach ($conflict['backgrounds'] as $background) {
                if (! isset($served[$background])) {
                    $issues[] = "{$label} nhắc {$background}, không có trong backgrounds.";
                }
            }

            foreach ($conflict['evidence'] as $evidence) {
                $problem = self::evidenceProblem($evidence, $beats, $states);

                if ($problem !== null) {
                    $issues[] = "{$label}: {$problem}";
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
     * @return array{0: bool, 1: mixed} whether the path names a value of the pinned input, and that value; only the paths the schema allows are read
     */
    private static function resolve(string $path, array $beats, array $states): array
    {
        $parts = explode('.', $path);

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
            'issues' => array_values(array_unique($issues)),
            'warnings' => array_values(array_unique($warnings)),
            'judge' => self::JUDGE,
        ];
    }
}
