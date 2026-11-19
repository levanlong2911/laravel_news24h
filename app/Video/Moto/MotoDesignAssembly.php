<?php

declare(strict_types=1);

namespace App\Video\Moto;

use App\Services\Video\StageAnswerRejected;
use App\Video\Inspiration\PhaseOneEvidence;
use App\Video\Llm\JsonAnswer;
use RuntimeException;

/**
 * Assembly protocol moto-design-assembly-v1: the model writes the design it is asked to design, the code fills the values that are fixed — by the pinned DNA, the schema of the design record and the pinned model-sheet cameras — and the two are joined into one design record of the pinned MotoDesign schema.
 *
 * Nothing here writes design content: a value the model left out stays out, and the check of the record reports it.
 */
final class MotoDesignAssembly
{
    public const PROTOCOL = 'moto-design-assembly-v1';

    /** @var string the key of the answer that maps every kept feature, must-avoid item and identity cue of the DNA to the identity rules that carry it; it is read here and never part of the design record */
    public const COVERAGE = 'dna_coverage';

    /** @var string the name the check of a joined record carries in place of the reduced technical schema: the record is held to the whole pinned schema */
    public const FINAL_SCHEMA_CHECK = 'moto-design-pinned-schema-v1';

    /** @var int how many faults against the generation schema the reason of a rejected answer quotes */
    private const SHOWN_ERRORS = 8;

    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * @return ?string the assembly protocol a Phase 3 prompt declares, null when it declares none and the model writes the whole record
     *
     * @throws RuntimeException when the prompt declares a protocol this code does not run
     */
    public static function protocolOf(string $prompt): ?string
    {
        if (preg_match('/Assembly protocol: `([^`\s]+)`/u', $prompt, $found) !== 1) {
            return null;
        }

        return $found[1] === self::PROTOCOL
            ? self::PROTOCOL
            : throw new RuntimeException("Prompt khai assembly protocol {$found[1]} mà code chỉ chạy ".self::PROTOCOL.' — không gọi model.');
    }

    /**
     * @param  array<string, mixed>  $dna  the DNA the design is built from
     * @param  array<string, mixed>  $modelSheet  the pinned model-sheet presentation with its cameras
     * @param  string  $finalSchema  the pinned schema of the design record, as stored
     * @return array<string, mixed> every value the code fills, read once from the pinned sources, and the order the record keeps its keys in
     *
     * @throws RuntimeException naming the source value that is missing, so no model is called for a record that could not be assembled
     */
    public static function sources(string $projectId, string $promptVersion, array $dna, array $modelSheet, string $finalSchema): array
    {
        $schema = json_decode($finalSchema, true);
        $missing = static fn (string $what): RuntimeException => new RuntimeException("Không ghép được bản thiết kế: {$what} — không gọi model.");

        if (! is_array($schema) || ! is_array($schema['$defs'] ?? null)) {
            throw $missing('schema MotoDesign đã ghim không đọc được');
        }

        $defs = $schema['$defs'];
        $const = static function (mixed $node, string $what) use ($missing): mixed {
            return is_array($node) && array_key_exists('const', $node) ? $node['const'] : throw $missing("schema MotoDesign đã ghim không cố định {$what}");
        };
        $fixed = static function (array $items, array $keys) use ($missing): array {
            $rows = [];

            foreach ($items as $index => $item) {
                $found = [];

                foreach ([$item, ...(array) ($item['allOf'] ?? [])] as $part) {
                    foreach ($keys as $key) {
                        if (is_array($part['properties'][$key] ?? null) && array_key_exists('const', $part['properties'][$key])) {
                            $found[$key] = $part['properties'][$key]['const'];
                        }
                    }
                }

                if (count($found) !== count($keys)) {
                    throw $missing('schema MotoDesign đã ghim không cố định '.implode(', ', $keys)." của mục thứ {$index}");
                }

                $rows[] = $found;
            }

            return $rows;
        };
        $platform = $dna['brief']['platform'] ?? null;
        $rider = $dna['brief']['rider'] ?? null;

        foreach (['family', 'era', 'engine_family'] as $key) {
            if (! is_string($platform[$key] ?? null) || trim($platform[$key]) === '') {
                throw $missing("dna.brief.platform.{$key} không có giá trị");
            }
        }

        if (! is_array($platform) || ! array_key_exists('model', $platform) || ! (is_string($platform['model']) || $platform['model'] === null)) {
            throw $missing('dna.brief.platform.model phải là chuỗi hoặc null');
        }

        if (! is_int($rider['height_cm'] ?? null) || ! is_string($rider['build'] ?? null) || trim($rider['build']) === '') {
            throw $missing('dna.brief.rider.height_cm hoặc build không có giá trị');
        }

        $features = [];

        foreach ((array) ($dna['features'] ?? []) as $index => $feature) {
            if (! is_string($feature['id'] ?? null) || ! is_string($feature['name'] ?? null) || ! is_string($feature['kind'] ?? null) || ! is_bool($feature['visible_at_rest'] ?? null) || isset($features[$feature['id']])) {
                throw $missing("dna.features[{$index}] thiếu id, name, kind hoặc visible_at_rest, hoặc trùng id");
            }

            $features[$feature['id']] = ['name' => $feature['name'], 'kind' => $feature['kind'], 'visible_at_rest' => $feature['visible_at_rest']];
        }

        $departures = array_column((array) ($dna['departures'] ?? []), 'id');

        if ($features === [] || $departures === [] || count($departures) !== count(array_unique($departures)) || array_filter($departures, 'is_string') !== $departures) {
            throw $missing('dna.features hoặc dna.departures rỗng hoặc trùng id');
        }

        $roles = array_column($fixed((array) ($defs['data']['properties']['participant_roles']['prefixItems'] ?? []), ['id', 'name']), 'name', 'id');
        $stages = array_column($fixed((array) ($defs['build_states']['prefixItems'] ?? []), ['id', 'kind']), 'kind', 'id');
        $viewItems = (array) ($defs['model_sheet']['properties']['views']['prefixItems'] ?? []);
        $cameraKeys = array_keys((array) ($defs['camera']['properties'] ?? []));
        $views = [];
        $detail = [];

        foreach ($fixed($viewItems, ['id']) as $index => $row) {
            $camera = $modelSheet['cameras'][$row['id']] ?? null;

            if (! is_array($camera)) {
                throw $missing("cấu hình model sheet đã ghim không có camera {$row['id']}");
            }

            $views[$row['id']] = array_intersect_key($camera, array_flip($cameraKeys));

            foreach ((array) ($viewItems[$index]['allOf'] ?? []) as $part) {
                if (isset($part['properties']['camera'])) {
                    $detail[] = $row['id'];
                }
            }
        }

        if ($roles === [] || $stages === [] || $views === []) {
            throw $missing('schema MotoDesign đã ghim không cố định vai, giai đoạn hoặc view');
        }

        $coverage = [];

        foreach ([
            'keep' => array_map(static fn (mixed $item): mixed => is_array($item) ? ($item['feature'] ?? null) : $item, (array) ($dna['keep'] ?? [])),
            'must_avoid' => (array) ($dna['must_avoid'] ?? []),
            'identity_cue' => (array) ($dna['visual_identity']['identity_cues'] ?? []),
        ] as $group => $items) {
            foreach (array_values($items) as $index => $text) {
                if (is_string($text) && trim($text) !== '') {
                    $coverage[$group.':'.($index + 1)] = $text;
                }
            }
        }

        return [
            'protocol' => self::PROTOCOL,
            'envelope' => [
                'format' => $const($schema['properties']['format'] ?? null, 'format'),
                'phase' => $const($schema['properties']['phase'] ?? null, 'phase'),
                'prompt_version' => $promptVersion,
                'project_id' => $projectId,
            ],
            'base' => [
                'make' => $const($defs['motorcycle']['properties']['base']['properties']['make'] ?? null, 'motorcycle.base.make'),
                'family' => $platform['family'],
                'model' => $platform['model'],
                'era' => $platform['era'],
                'engine_family' => $platform['engine_family'],
            ],
            'motorcycle' => [
                'fictional_status' => $const($defs['motorcycle']['properties']['fictional_status'] ?? null, 'motorcycle.fictional_status'),
                'coordinate_system' => array_map(static fn (mixed $axis) => $const($axis, 'motorcycle.coordinate_system'), (array) ($defs['motorcycle']['properties']['coordinate_system']['properties'] ?? [])),
            ],
            'rider' => ['height_cm' => $rider['height_cm'], 'build' => $rider['build']],
            'features' => $features,
            'departures' => array_values($departures),
            'roles' => $roles,
            'stages' => $stages,
            'views' => $views,
            'detail_views' => array_values(array_unique($detail)),
            'units' => self::unitPaths($defs, (array) $defs['data'], []),
            'coverage' => $coverage,
            'order' => [
                'top' => array_keys((array) $schema['properties']),
                'motorcycle' => array_keys((array) $defs['motorcycle']['properties']),
                'base' => array_keys((array) $defs['motorcycle']['properties']['base']['properties']),
                'rider_interface' => array_keys((array) $defs['rider_interface']['properties']),
                'feature' => array_keys((array) $defs['feature']['properties']),
                'role' => array_keys((array) $defs['participant_role']['properties']),
                'design_stage' => array_keys((array) $defs['design_stage']['properties']),
                'build_state' => array_keys((array) $defs['build_state']['properties']),
                'view' => array_keys((array) $defs['view']['properties']),
                'camera' => $cameraKeys,
                'spec' => array_keys((array) $defs['spec']['properties']),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $sources  sources()
     * @return array<string, mixed> what the model is told of the assembly in its input: the protocol, the ids it writes for and the source key of every DNA item its coverage mapping must name
     */
    public static function input(array $sources): array
    {
        return [
            'protocol' => $sources['protocol'],
            'features' => array_keys($sources['features']),
            'departures' => $sources['departures'],
            'roles' => array_keys($sources['roles']),
            'stages' => array_keys($sources['stages']),
            'views' => array_keys($sources['views']),
            'coverage' => array_map(static fn (string $key, string $text): array => ['key' => $key, 'text' => $text], array_keys($sources['coverage']), array_values($sources['coverage'])),
        ];
    }

    /**
     * @param  string  $finalSchema  the pinned schema of the design record, as stored
     * @param  array<string, mixed>  $sources  sources()
     * @return array{id: string, content: string} the schema of what the model returns, derived from the pinned schema by taking out exactly the values the code fills: every other constraint is the pinned one, the ids are held to the source sets, and the coverage mapping is added
     */
    public static function generationSchema(string $finalSchema, array $sources): array
    {
        $schema = json_decode($finalSchema, false, 512, JSON_THROW_ON_ERROR);
        $defs = $schema->{'$defs'};
        $drop = static function (object $node, array $keys): void {
            foreach ($keys as $key) {
                unset($node->properties->{$key});
            }

            if (isset($node->required)) {
                $node->required = array_values(array_diff($node->required, $keys));
            }
        };
        $copy = static fn (object $node): object => json_decode((string) json_encode($node, self::JSON), false, 512, JSON_THROW_ON_ERROR);
        $schema->{'$id'} .= '/generation/'.self::PROTOCOL;
        $schema->title = 'Generation schema of '.self::PROTOCOL.': the part of the design record the model writes. '.$schema->title;
        $drop($schema, array_keys($sources['envelope']));
        $keys = array_keys($sources['coverage']);
        $schema->properties->{self::COVERAGE} = (object) [
            'description' => 'Assembly metadata, not part of the design record: one entry for every coverage key of the input, naming the identity rules of this answer that carry that DNA item.',
            'type' => 'array',
            'maxItems' => count($keys),
            'items' => $keys === [] ? false : (object) [
                'type' => 'object',
                'required' => ['source', 'rules'],
                'additionalProperties' => false,
                'properties' => (object) [
                    'source' => (object) ['enum' => $keys],
                    'rules' => (object) ['type' => 'array', 'minItems' => 1, 'uniqueItems' => true, 'items' => (object) ['$ref' => '#/$defs/rule_id']],
                ],
            ],
        ];
        $schema->required[] = self::COVERAGE;

        foreach ($schema->allOf as $branch) {
            $status = $branch->if->properties->status->const ?? null;
            $branch->then->properties->{self::COVERAGE} = (object) ($status === 'fail' ? ['maxItems' => 0] : ['minItems' => count($keys)]);
        }

        $drop($defs->motorcycle, array_keys($sources['motorcycle']));
        $drop($defs->motorcycle->properties->base, array_keys($sources['base']));
        $drop($defs->rider_interface, ['rider']);
        $drop($defs->feature, ['name', 'kind', 'visible_at_rest']);
        $defs->feature->properties->id = (object) ['enum' => array_keys($sources['features'])];
        $features = $defs->data->properties->features;
        unset($features->contains);
        $features->minItems = $features->maxItems = count($sources['features']);
        $departures = $defs->motorcycle->properties->departures;
        $departures->items->properties->departure = (object) ['enum' => $sources['departures']];
        $departures->minItems = $departures->maxItems = count($sources['departures']);
        $drop($defs->participant_role, ['name']);

        foreach ($defs->data->properties->participant_roles->prefixItems as $item) {
            if (isset($item->properties->name)) {
                unset($item->properties->name);
            }
        }

        $drop($defs->design_stage, ['kind']);
        $drop($defs->build_state, ['kind']);

        foreach ($defs->build_states->prefixItems as $item) {
            foreach ($item->allOf as $part) {
                if (isset($part->properties->kind)) {
                    unset($part->properties->kind);
                }
            }
        }

        $defs->view_fixed = $copy($defs->view);
        $drop($defs->view_fixed, ['camera']);
        $defs->view_detail = $copy($defs->view);
        $defs->view_detail->properties->camera = (object) [
            'type' => 'object',
            'required' => ['azimuth_deg', 'elevation_deg'],
            'additionalProperties' => false,
            'properties' => (object) ['azimuth_deg' => (object) ['type' => 'number'], 'elevation_deg' => (object) ['type' => 'number']],
        ];

        foreach ($defs->model_sheet->properties->views->prefixItems as $item) {
            $detail = false;

            foreach ($item->allOf as $part) {
                if (isset($part->properties->camera)) {
                    $detail = true;
                    unset($part->properties->camera);
                }
            }

            foreach ($item->allOf as $part) {
                if (($part->{'$ref'} ?? null) === '#/$defs/view') {
                    $part->{'$ref'} = $detail ? '#/$defs/view_detail' : '#/$defs/view_fixed';
                }
            }
        }

        unset($defs->view, $defs->camera);
        $defs->spec_fixed = $copy($defs->spec);
        $drop($defs->spec_fixed, ['unit']);

        foreach ($defs as $name => $def) {
            $unit = array_filter((array) ($def->allOf ?? []), static fn (mixed $part): bool => isset($part->properties->unit->const));

            if ($unit !== [] && $name !== 'spec_fixed') {
                $defs->{$name}->allOf = [(object) ['$ref' => '#/$defs/spec_fixed']];
            }
        }

        return ['id' => (string) $schema->{'$id'}, 'content' => (string) json_encode($schema, self::JSON)];
    }

    /**
     * @param  string  $raw  the answer of the model
     * @param  array{sources: array<string, mixed>, schema: string, schema_id: string, schema_sha256: string, label: string}  $assembly  what the run pinned for its assembly
     * @return array{0: string, 1: array<string, mixed>} the design record as one JSON text, joined once from the answer and the pinned values, and what the assembly pinned: the protocol, the hashes of the generation schema and of the sources, the coverage mapping and the hash of the record
     *
     * @throws StageAnswerRejected when no record is made: the answer is not a JSON object, it breaks its generation schema in any way — a value of the wrong type, a field the code owns, an item out of its place — or its features, departures, roles, stages, views or coverage are not exactly the source sets. Nothing of the answer is dropped, filtered or overwritten to make it fit
     */
    public static function assemble(string $raw, array $assembly): array
    {
        $sources = $assembly['sources'];
        $parsed = JsonAnswer::parse($raw);
        $answer = $parsed[0];

        if ($parsed[1] !== null || ! is_object($answer)) {
            throw new StageAnswerRejected('Không ghép được bản thiết kế: phản hồi của model không phải một object JSON đọc được'.($parsed[1] === null ? '' : " ({$parsed[1]})").'.');
        }

        try {
            $generation = PhaseOneEvidence::schemaErrors($answer, [['json_schema_id' => $assembly['schema_id'], 'content' => $assembly['schema'], 'label' => $assembly['label']]]);
        } catch (\Throwable $e) {
            throw new StageAnswerRejected('Không ghép được bản thiết kế: không kiểm được phản hồi của model theo generation schema ('.$e->getMessage().').', 0, $e);
        }

        $problems = [];
        $status = $answer->status ?? null;
        $data = $answer->data ?? null;

        if (! in_array($status, ['ok', 'partial', 'fail'], true)) {
            $problems[] = 'status phải là ok, partial hoặc fail';
        }

        if (! is_array($answer->flags ?? null)) {
            $problems[] = 'flags phải là một mảng';
        }

        if (! is_object($data)) {
            $problems[] = 'data phải là một object';
        }

        $coverage = [];
        $motorcycle = $interface = null;
        $features = $departures = $roles = $stages = $views = [];

        if ($problems === [] && $status !== 'fail') {
            $motorcycle = $data->motorcycle ?? null;
            $interface = $data->rider_interface ?? null;

            foreach (['data.motorcycle' => $motorcycle, 'data.motorcycle.base' => $motorcycle->base ?? null, 'data.rider_interface' => $interface, 'data.model_sheet' => $data->model_sheet ?? null] as $path => $node) {
                if (! is_object($node)) {
                    $problems[] = "{$path} phải là một object";
                }
            }

            $features = self::matched($problems, 'data.features', $data->features ?? null, 'id', array_keys($sources['features']));
            $departures = self::matched($problems, 'data.motorcycle.departures', $motorcycle->departures ?? null, 'departure', $sources['departures']);
            $roles = self::matched($problems, 'data.participant_roles', $data->participant_roles ?? null, 'id', array_keys($sources['roles']));
            $stages = self::matched($problems, 'data.build_states', $data->build_states ?? null, 'id', array_keys($sources['stages']));
            $views = self::matched($problems, 'data.model_sheet.views', $data->model_sheet->views ?? null, 'id', array_keys($sources['views']));

            foreach ($sources['detail_views'] as $id) {
                $camera = $views[$id]->camera ?? null;

                if (isset($views[$id]) && ! (is_object($camera) && self::number($camera->azimuth_deg ?? null) && self::number($camera->elevation_deg ?? null))) {
                    $problems[] = "data.model_sheet.views {$id}: camera phải có azimuth_deg và elevation_deg là số";
                }
            }

            $coverage = self::coverage($problems, $answer->{self::COVERAGE} ?? null, array_keys($sources['coverage']), $data->identity_rules ?? null);
        }

        if ($generation !== []) {
            $problems[] = 'phản hồi sai generation schema ('.count($generation).' lỗi): '.implode('; ', array_slice($generation, 0, self::SHOWN_ERRORS)).(count($generation) > self::SHOWN_ERRORS ? '; …' : '');
        }

        if ($problems !== []) {
            throw new StageAnswerRejected('Không ghép được bản thiết kế từ phản hồi của model: '.implode('; ', $problems).'.');
        }

        if ($status !== 'fail') {
            $order = $sources['order'];

            foreach ($sources['base'] as $key => $value) {
                $motorcycle->base->{$key} = $value;
            }

            $motorcycle->base = self::ordered($motorcycle->base, $order['base']);
            $motorcycle->fictional_status = $sources['motorcycle']['fictional_status'];
            $motorcycle->coordinate_system = (object) $sources['motorcycle']['coordinate_system'];
            $motorcycle->departures = array_map(static fn (string $id): object => $departures[$id], $sources['departures']);
            $interface->rider = (object) $sources['rider'];
            $data->motorcycle = self::ordered($motorcycle, $order['motorcycle']);
            $data->rider_interface = self::ordered($interface, $order['rider_interface']);
            $data->features = [];

            foreach ($sources['features'] as $id => $fixed) {
                foreach ($fixed as $key => $value) {
                    $features[$id]->{$key} = $value;
                }

                $data->features[] = self::ordered($features[$id], $order['feature']);
            }

            $data->participant_roles = [];

            foreach ($sources['roles'] as $id => $name) {
                $roles[$id]->name = $name;
                $data->participant_roles[] = self::ordered($roles[$id], $order['role']);
            }

            $data->build_states = [];

            foreach ($sources['stages'] as $id => $kind) {
                $stages[$id]->kind = $kind;
                $data->build_states[] = self::ordered($stages[$id], property_exists($stages[$id], 'checkpoints') ? $order['build_state'] : $order['design_stage']);
            }

            $data->model_sheet->views = [];

            foreach ($sources['views'] as $id => $pinned) {
                $camera = $pinned;

                if (in_array($id, $sources['detail_views'], true)) {
                    $camera = ['azimuth_deg' => $views[$id]->camera->azimuth_deg, 'elevation_deg' => $views[$id]->camera->elevation_deg] + $pinned;
                }

                $views[$id]->camera = self::ordered((object) $camera, $order['camera']);
                $data->model_sheet->views[] = self::ordered($views[$id], $order['view']);
            }

            foreach ($sources['units'] as $path => $unit) {
                $node = $data;

                foreach (explode('.', $path) as $key) {
                    $node = is_object($node) ? ($node->{$key} ?? null) : null;
                }

                if (is_object($node)) {
                    $node->unit = $unit;
                    self::reorder($data, explode('.', $path), self::ordered($node, $order['spec']));
                }
            }
        }

        unset($answer->{self::COVERAGE});

        foreach ($sources['envelope'] as $key => $value) {
            $answer->{$key} = $value;
        }

        $record = (string) json_encode(self::ordered($answer, $sources['order']['top']), self::JSON);

        return [$record, [
            'protocol' => $sources['protocol'],
            'generation_schema_sha256' => $assembly['schema_sha256'],
            'sources_sha256' => $assembly['sources_sha256'],
            'coverage' => $coverage,
            'record_sha256' => hash('sha256', $record),
        ]];
    }

    /**
     * @param  array<string, mixed>  $defs  the definitions of the pinned schema
     * @param  array<string, mixed>  $node  an object schema reached from the design data
     * @param  list<string>  $path
     * @return array<string, string> the unit the pinned schema fixes for a measure, by the dotted path of that measure under data, through plain object properties only
     */
    private static function unitPaths(array $defs, array $node, array $path): array
    {
        $units = [];
        $guard = 0;

        while (is_string($node['$ref'] ?? null) && $guard++ < 8) {
            $name = substr($node['$ref'], strlen('#/$defs/'));

            foreach ((array) ($defs[$name]['allOf'] ?? []) as $part) {
                if (is_array($part['properties']['unit'] ?? null) && array_key_exists('const', $part['properties']['unit'])) {
                    return [implode('.', $path) => (string) $part['properties']['unit']['const']];
                }
            }

            $node = (array) ($defs[$name] ?? []);
        }

        if (count($path) > 6) {
            return [];
        }

        foreach ((array) ($node['properties'] ?? []) as $key => $child) {
            if (is_array($child)) {
                $units += self::unitPaths($defs, $child, [...$path, (string) $key]);
            }
        }

        return $units;
    }

    /**
     * @param  list<string>  $problems  receives why the list is not exactly the source set: not a list of objects, an id missing, unknown or written twice
     * @param  list<string>  $wanted  the source ids
     * @return array<string, object> the items by their matching id
     */
    private static function matched(array &$problems, string $path, mixed $items, string $key, array $wanted): array
    {
        if (! is_array($items)) {
            $problems[] = "{$path} phải là một mảng";

            return [];
        }

        $found = [];
        $twice = [];

        foreach ($items as $index => $item) {
            $id = is_object($item) ? ($item->{$key} ?? null) : null;

            if (! is_string($id)) {
                $problems[] = "{$path}[{$index}] thiếu {$key}";

                continue;
            }

            if (isset($found[$id])) {
                $twice[] = $id;
            }

            $found[$id] = $item;
        }

        foreach (['thiếu' => array_diff($wanted, array_keys($found)), 'có id lạ' => array_diff(array_keys($found), $wanted), 'trùng' => array_unique($twice)] as $what => $ids) {
            if ($ids !== []) {
                $problems[] = "{$path} {$what} ".implode(', ', $ids);
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $problems  receives why the mapping is not complete: not a list, a source key missing, unknown or written twice, or a rule that the answer does not declare
     * @param  list<string>  $keys  the coverage keys of the sources
     * @return array<string, list<string>> the identity rules named for each source key
     */
    private static function coverage(array &$problems, mixed $mapping, array $keys, mixed $rules): array
    {
        $declared = [];

        foreach (is_array($rules) ? $rules : [] as $rule) {
            if (is_object($rule) && is_string($rule->id ?? null)) {
                $declared[$rule->id] = true;
            }
        }

        $found = self::matched($problems, self::COVERAGE, $mapping, 'source', $keys);
        $coverage = [];

        foreach ($found as $source => $entry) {
            $named = is_array($entry->rules ?? null) ? array_values($entry->rules) : [];

            if ($named === [] || array_filter($named, 'is_string') !== $named) {
                $problems[] = self::COVERAGE." {$source}: rules phải là danh sách id identity rule, ít nhất một";

                continue;
            }

            $unknown = array_values(array_filter($named, static fn (string $id): bool => ! isset($declared[$id])));

            if ($unknown !== []) {
                $problems[] = self::COVERAGE." {$source} nêu identity rule không có trong câu trả lời: ".implode(', ', $unknown);
            }

            $coverage[$source] = $named;
        }

        return $coverage;
    }

    /** @param list<string> $order */
    private static function ordered(object $node, array $order): object
    {
        $sorted = new \stdClass;

        foreach ($order as $key) {
            if (property_exists($node, $key)) {
                $sorted->{$key} = $node->{$key};
            }
        }

        foreach (get_object_vars($node) as $key => $value) {
            if (! property_exists($sorted, (string) $key)) {
                $sorted->{$key} = $value;
            }
        }

        return $sorted;
    }

    /** @param list<string> $path */
    private static function reorder(object $data, array $path, object $value): void
    {
        $last = array_pop($path);
        $node = $data;

        foreach ($path as $key) {
            $node = $node->{$key};
        }

        $node->{$last} = $value;
    }

    private static function number(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }
}
