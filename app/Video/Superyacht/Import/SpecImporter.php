<?php

namespace App\Video\Superyacht\Import;

use App\Models\AiJsonSchema;
use App\Models\AiPhase;
use App\Models\AiPhaseSchema;
use App\Models\Category;
use App\Video\Superyacht\Spec\ActionContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use Opis\JsonSchema\Uri;
use Opis\JsonSchema\Validator;
use RuntimeException;
use Throwable;

final class SpecImporter
{
    public const DIALECT = 'https://json-schema.org/draft/2020-12/schema';

    public const CREATED_BY = 'video:spec-import';

    private const LOCK_TIMEOUT_SECONDS = 10;

    private const LINK_KEYS = ['phase', 'role', 'slot', 'namespace', 'schema', 'version', 'pointer', 'mode', 'required', 'source'];

    /**
     * @param  list<string>  $schemaNames
     * @param  list<array<string, mixed>>  $links
     */
    public function __construct(
        private readonly string $workflowCode,
        private readonly string $categorySlug,
        private readonly string $specPath,
        private readonly string $specVersion,
        private readonly string $schemaNamespace,
        private readonly string $schemaDir,
        private readonly string $schemaBaseUri,
        private readonly array $schemaNames,
        private readonly array $links = [],
    ) {}

    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config, string $categorySlug): self
    {
        return new self(
            (string) $config['workflow_code'],
            $categorySlug,
            (string) $config['spec_path'],
            (string) $config['spec_version'],
            (string) $config['schema_namespace'],
            (string) $config['schema_dir'],
            (string) $config['schema_base_uri'],
            array_values((array) $config['schemas']),
            ActionContract::desiredLinks((array) ($config['contracts']['workflows'][$config['workflow_code']] ?? [])),
        );
    }

    /**
     * @return array{
     *     workflow_code: string,
     *     category: array{id: string, slug: string},
     *     namespace: string,
     *     spec: array{source_file: string, bytes: int, sha256: string},
     *     phases: list<array<string, mixed>>,
     *     schemas: list<array<string, mixed>>,
     *     links: list<array<string, mixed>>,
     *     pending: list<string>
     * }
     */
    public function plan(): array
    {
        $categoryIds = Category::query()->where('slug', $this->categorySlug)->pluck('id');

        if ($categoryIds->count() !== 1) {
            throw new RuntimeException("Category slug {$this->categorySlug} matches {$categoryIds->count()} rows instead of exactly one.");
        }

        $categoryId = (string) $categoryIds->first();
        $source = $this->read($this->specPath);
        $sha = hash('sha256', $source);
        $phases = $this->phaseRows($source, $sha, $categoryId);
        $schemas = $this->schemaRows();
        $links = $this->linkRows($phases, $schemas);
        $linked = array_column($links, 'schema_id');
        $pending = [];

        foreach ($schemas as $row) {
            if (! in_array($row['id'], $linked, true)) {
                $pending[] = $row['schema_key'].'@'.$row['version'];
            }
        }

        return [
            'workflow_code' => $this->workflowCode,
            'category' => ['id' => $categoryId, 'slug' => $this->categorySlug],
            'namespace' => $this->schemaNamespace,
            'spec' => ['source_file' => $this->relative($this->specPath), 'bytes' => strlen($source), 'sha256' => $sha],
            'phases' => $phases,
            'schemas' => $schemas,
            'links' => $links,
            'pending' => $pending,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array{conflicts: list<string>, reuse: array<string, string>} reuse maps a planned schema id to the stored row it equals
     */
    public function existing(array $plan): array
    {
        $conflicts = [];
        $phases = AiPhase::query()
            ->where('category_id', $plan['category']['id'])
            ->where('workflow_code', $plan['workflow_code'])
            ->count();

        if ($phases > 0) {
            $conflicts[] = "ai_phases already holds {$phases} rows of workflow {$plan['workflow_code']} for category {$plan['category']['slug']}";
        }

        $stored = AiJsonSchema::query()
            ->where('namespace', $plan['namespace'])
            ->where('version', $this->specVersion)
            ->get()
            ->keyBy('schema_key');
        $planned = array_column($plan['schemas'], null, 'id');
        $reuse = [];

        foreach ($plan['schemas'] as $row) {
            $hit = $stored->get($row['schema_key']);

            if ($hit === null) {
                continue;
            }

            $label = "{$plan['namespace']}/{$row['schema_key']}@{$row['version']}";

            if ($hit->status !== AiJsonSchema::PUBLISHED) {
                $conflicts[] = "ai_json_schemas holds {$label} with status {$hit->status}; only a published schema is reused";

                continue;
            }

            if (! hash_equals($row['schema_sha256'], (string) $hit->schema_sha256) || $hit->schema_content !== $row['schema_content']) {
                $conflicts[] = "ai_json_schemas holds {$label} with different content";

                continue;
            }

            $expected = array_map(static fn (string $id): string => $planned[$id]['schema_key'], $row['dependencies']);
            $actual = array_map(
                static fn (mixed $id): string => (string) $stored->firstWhere('id', $id)?->schema_key,
                (array) $hit->dependencies,
            );
            ksort($expected);
            ksort($actual);

            if ($expected !== $actual) {
                $conflicts[] = "ai_json_schemas holds {$label} with different dependencies";

                continue;
            }

            $reuse[$row['id']] = (string) $hit->id;
        }

        return ['conflicts' => $conflicts, 'reuse' => $reuse];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public function import(array $plan, ?string $expectedSpecSha256): array
    {
        $expected = strtolower(trim((string) $expectedSpecSha256));

        if ($expected === '') {
            throw new RuntimeException('The spec hash must be confirmed with --expected-spec-sha256 before anything is stored.');
        }

        if (! hash_equals($plan['spec']['sha256'], $expected)) {
            throw new RuntimeException("The spec hash is {$plan['spec']['sha256']}, not the confirmed {$expected}.");
        }

        $held = [];

        try {
            foreach ([
                'ai_schema:'.sha1($plan['namespace']),
                'ai_spec:'.sha1($plan['category']['id'].'|'.$plan['workflow_code']),
            ] as $lock) {
                if ((int) DB::selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$lock, self::LOCK_TIMEOUT_SECONDS])->acquired !== 1) {
                    throw new RuntimeException("Another import holds the lock {$lock}.");
                }

                $held[] = $lock;
            }

            return DB::transaction(function () use ($plan): array {
                $state = $this->existing($plan);

                if ($state['conflicts'] !== []) {
                    throw new RuntimeException('Not imported: '.implode('; ', $state['conflicts']).'.');
                }

                $reuse = $state['reuse'];
                $now = now();
                $stamps = ['created_at' => $now, 'published_at' => $now];
                $schemas = [];

                foreach ($plan['schemas'] as $row) {
                    if (! isset($reuse[$row['id']])) {
                        $dependencies = array_map(static fn (string $id): string => $reuse[$id] ?? $id, $row['dependencies']);
                        $schemas[] = ['dependencies' => json_encode((object) $dependencies, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)] + $row + $stamps;
                    }
                }

                AiPhase::query()->insert(array_map(static fn (array $row): array => $row + $stamps, $plan['phases']));

                if ($schemas !== []) {
                    AiJsonSchema::query()->insert($schemas);
                }

                if ($plan['links'] !== []) {
                    AiPhaseSchema::query()->insert(array_map(static fn (array $row): array => [
                        'schema_id' => $reuse[$row['schema_id']] ?? $row['schema_id'],
                        'created_at' => $now,
                    ] + $row, $plan['links']));
                }

                $this->verify($plan, $reuse);

                return $this->summary($plan, $reuse);
            });
        } finally {
            foreach (array_reverse($held) as $lock) {
                DB::select('SELECT RELEASE_LOCK(?) AS released', [$lock]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, string>  $reuse  planned schema id => stored schema id
     * @return array{
     *     workflow_code: string,
     *     category: string,
     *     namespace: string,
     *     spec_sha256: string,
     *     phases: int,
     *     kinds: array<string, int>,
     *     schemas: int,
     *     reused: int,
     *     dependencies: array<string, list<string>>,
     *     links: int,
     *     pending: list<string>
     * }
     */
    public function summary(array $plan, array $reuse = []): array
    {
        $byId = array_column($plan['schemas'], null, 'id');
        $dependencies = [];

        foreach ($plan['schemas'] as $row) {
            foreach ($row['dependencies'] as $uri => $id) {
                $target = $reuse[$id] ?? $id;
                $dependencies[$row['schema_key'].'@'.$row['version']][] = $uri.' -> '.$byId[$id]['schema_key'].'@'.$byId[$id]['version'].' ('.$target.')';
            }
        }

        return [
            'workflow_code' => $plan['workflow_code'],
            'category' => $plan['category']['slug'],
            'namespace' => $plan['namespace'],
            'spec_sha256' => $plan['spec']['sha256'],
            'phases' => count($plan['phases']),
            'kinds' => array_count_values(array_column($plan['phases'], 'kind')),
            'schemas' => count($plan['schemas']),
            'reused' => count($reuse),
            'dependencies' => $dependencies,
            'links' => count($plan['links']),
            'pending' => $plan['pending'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function phaseRows(string $source, string $sha, string $categoryId): array
    {
        $parts = $this->split($source);

        if (implode('', array_column($parts, 'body')) !== $source) {
            throw new RuntimeException('Splitting the spec did not rebuild it byte for byte.');
        }

        $map = $this->pipelineMap($parts);
        $headings = array_column(array_filter($parts, static fn (array $part): bool => $part['kind'] === 'phase'), 'code');
        $unmapped = array_diff($headings, array_keys($map));
        $missing = array_diff(array_keys($map), $headings);

        if ($unmapped !== [] || $missing !== []) {
            throw new RuntimeException('Pipeline map and phase headings disagree: headings without a row ['.implode(', ', $unmapped).'], rows without a heading ['.implode(', ', $missing).'].');
        }

        $file = $this->relative($this->specPath);
        $rows = [];

        foreach ($parts as $order => $part) {
            $mapped = $map[$part['code']] ?? null;
            $rows[] = [
                'id' => (string) Str::uuid(),
                'category_id' => $categoryId,
                'workflow_code' => $this->workflowCode,
                'phase_code' => $part['code'],
                'revision' => 1,
                'kind' => $part['kind'],
                'name' => $part['name'],
                'sort_order' => $order,
                'prompt_content' => $part['body'],
                'content_sha256' => hash('sha256', $part['body']),
                'needs' => $mapped['needs'] ?? null,
                'parallel_with' => $mapped['parallel_with'] ?? null,
                'stop' => $mapped['stop'] ?? null,
                'status' => AiPhase::PUBLISHED,
                'is_current' => true,
                'based_on_id' => null,
                'source_file' => $file,
                'source_section_key' => $part['section_key'],
                'change_note' => "Nạp nguyên văn từ {$file} (spec {$this->specVersion}, sha256 {$sha}).",
                'created_by' => self::CREATED_BY,
            ];
        }

        return $rows;
    }

    /** @return list<array{code: string, kind: string, name: string, section_key: ?string, body: string}> */
    private function split(string $source): array
    {
        $length = strlen($source);
        $offset = 0;
        $fenced = false;
        $starts = [];

        while ($offset < $length) {
            $newline = strpos($source, "\n", $offset);
            $next = $newline === false ? $length : $newline + 1;
            $line = rtrim(substr($source, $offset, $next - $offset), "\r\n");

            if (preg_match('/^ {0,3}(```|~~~)/', $line) === 1) {
                $fenced = ! $fenced;
            } elseif (! $fenced && preg_match('/^## (\S.*)$/', $line, $match) === 1) {
                $starts[] = [$offset, trim($match[1])];
            }

            $offset = $next;
        }

        if ($fenced) {
            throw new RuntimeException('The spec ends inside an unclosed code fence.');
        }

        if ($starts === []) {
            throw new RuntimeException('The spec has no level-two heading.');
        }

        $parts = [];

        if ($starts[0][0] > 0) {
            $parts[] = ['code' => 'preamble', 'kind' => 'preamble', 'name' => 'Preamble', 'section_key' => null, 'body' => substr($source, 0, $starts[0][0])];
        }

        foreach ($starts as $index => [$start, $heading]) {
            $end = $starts[$index + 1][0] ?? $length;
            $isPhase = preg_match('/^Phase ([0-9]{1,2}[A-Z]?) — /u', $heading, $match) === 1;
            $code = $isPhase
                ? 'phase-'.strtolower($match[1])
                : Str::slug((string) preg_replace('/\s*\([^)]*\)\s*$/u', '', $heading));

            if ($code === '' || strlen($code) > 64 || mb_strlen($heading) > 255) {
                throw new RuntimeException("The heading \"{$heading}\" does not give a usable code and name.");
            }

            $parts[] = [
                'code' => $code,
                'kind' => $isPhase ? 'phase' : 'section',
                'name' => $heading,
                'section_key' => Str::slug($heading),
                'body' => substr($source, $start, $end - $start),
            ];
        }

        $codes = array_column($parts, 'code');
        $duplicates = array_keys(array_filter(array_count_values($codes), static fn (int $count): bool => $count > 1));

        if ($duplicates !== []) {
            throw new RuntimeException('Two sections share a code: '.implode(', ', $duplicates).'.');
        }

        return $parts;
    }

    /**
     * @param  list<array{code: string, kind: string, name: string, section_key: ?string, body: string}>  $parts
     * @return array<string, array{needs: string, parallel_with: string, stop: string}>
     */
    private function pipelineMap(array $parts): array
    {
        $overview = array_values(array_filter($parts, static fn (array $part): bool => $part['code'] === 'overview'))[0]['body'] ?? null;

        if ($overview === null || preg_match('/^### Pipeline map[ \t]*\r?\n(.*?)(?=^#{2,3} |\z)/ms', $overview, $match) !== 1) {
            throw new RuntimeException('The Overview section has no "### Pipeline map" table.');
        }

        $map = [];

        foreach (preg_split('/\r?\n/', $match[1]) ?: [] as $line) {
            if (preg_match('/^\|\s*[0-9]{1,2}[A-Z]?\s*\|/', $line) !== 1) {
                continue;
            }

            $cells = array_map('trim', explode('|', trim(trim($line), '|')));

            if (count($cells) !== 5) {
                throw new RuntimeException("A Pipeline map row has ".count($cells)." cells instead of 5: {$line}");
            }

            $code = 'phase-'.strtolower($cells[0]);

            if (isset($map[$code])) {
                throw new RuntimeException("The Pipeline map lists {$cells[0]} twice.");
            }

            $map[$code] = ['needs' => $cells[2], 'parallel_with' => $cells[3], 'stop' => $cells[4]];
        }

        if ($map === []) {
            throw new RuntimeException('The Pipeline map table has no phase rows.');
        }

        return $map;
    }

    /** @return list<array<string, mixed>> */
    private function schemaRows(): array
    {
        if ($this->schemaNames === []) {
            throw new RuntimeException('No schema is configured.');
        }

        $entries = [];
        $byUri = [];

        foreach ($this->schemaNames as $name) {
            $path = rtrim($this->schemaDir, '/\\').'/'.$name.'.json';
            $content = $this->read($path);

            try {
                $decoded = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new RuntimeException("Schema {$name} is not valid JSON: {$e->getMessage()}", 0, $e);
            }

            if (! is_object($decoded)) {
                throw new RuntimeException("Schema {$name} is not a JSON object.");
            }

            $uri = $decoded->{'$id'} ?? null;
            $title = $decoded->title ?? null;

            if (! is_string($uri) || $uri === '') {
                throw new RuntimeException("Schema {$name} declares no \$id.");
            }

            if (isset($byUri[$uri])) {
                throw new RuntimeException("Schemas {$byUri[$uri]} and {$name} declare the same \$id {$uri}.");
            }

            $byUri[$uri] = $name;

            if ($uri !== $this->uri($name)) {
                throw new RuntimeException("Schema {$name} declares \$id {$uri}; the configured URI is {$this->uri($name)}.");
            }

            if (($decoded->{'$schema'} ?? null) !== self::DIALECT) {
                throw new RuntimeException("Schema {$name} does not declare the dialect ".self::DIALECT.'.');
            }

            if (! is_string($title) || trim($title) === '' || mb_strlen($title) > 255) {
                throw new RuntimeException("Schema {$name} needs a title of 1 to 255 characters to use as its name.");
            }

            $entries[$name] = ['id' => (string) Str::uuid(), 'uri' => $uri, 'title' => $title, 'path' => $path, 'content' => $content, 'decoded' => $decoded];
        }

        $rows = [];

        foreach ($entries as $name => $entry) {
            $dependencies = [];

            foreach ($this->references($entry['decoded']) as $reference) {
                if (str_starts_with($reference, '#')) {
                    continue;
                }

                $merged = Uri::merge($reference, $entry['uri']);
                $target = $merged === null ? null : explode('#', (string) $merged, 2)[0];

                if ($target === $entry['uri']) {
                    continue;
                }

                if ($target === null || ! isset($byUri[$target])) {
                    throw new RuntimeException("Schema {$name} references {$reference}, which no imported schema declares.");
                }

                $dependencies[$target] = $entries[$byUri[$target]]['id'];
            }

            ksort($dependencies);
            $rows[] = [
                'id' => $entry['id'],
                'namespace' => $this->schemaNamespace,
                'schema_key' => $name,
                'version' => $this->specVersion,
                'name' => $entry['title'],
                'schema_content' => $entry['content'],
                'schema_sha256' => hash('sha256', $entry['content']),
                'source_file' => $this->relative($entry['path']),
                'json_schema_id' => $entry['uri'],
                'json_schema_dialect' => self::DIALECT,
                'dependencies' => $dependencies,
                'status' => AiJsonSchema::PUBLISHED,
                'change_note' => "Nạp nguyên văn từ {$this->relative($entry['path'])} (spec {$this->specVersion}).",
                'created_by' => self::CREATED_BY,
            ];
        }

        $this->compile($entries);

        return $rows;
    }

    /** @param array<string, array{uri: string, content: string}> $entries */
    private function compile(array $entries): void
    {
        $validator = new Validator();

        foreach ($entries as $name => $entry) {
            if (! $validator->resolver()->registerRaw($entry['content'], $entry['uri'])) {
                throw new RuntimeException("Opis could not register schema {$name}.");
            }
        }

        foreach ($entries as $name => $entry) {
            try {
                $schema = $validator->loader()->loadSchemaById(Uri::parse($entry['uri'], true));
            } catch (Throwable $e) {
                throw new RuntimeException("Opis could not compile schema {$name}: {$e->getMessage()}", 0, $e);
            }

            if ($schema === null) {
                throw new RuntimeException("Opis could not load schema {$name}.");
            }
        }
    }

    /** @return list<string> */
    private function references(mixed $node): array
    {
        if (is_object($node)) {
            $node = get_object_vars($node);
        }

        if (! is_array($node)) {
            return [];
        }

        $found = [];

        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value)) {
                $found[] = $value;
            } else {
                array_push($found, ...$this->references($value));
            }
        }

        return $found;
    }

    /**
     * @param  list<array<string, mixed>>  $phases
     * @param  list<array<string, mixed>>  $schemas
     * @return list<array<string, mixed>>
     */
    private function linkRows(array $phases, array $schemas): array
    {
        $phaseIds = array_column($phases, 'id', 'phase_code');
        $schemaIds = [];

        foreach ($schemas as $row) {
            $schemaIds[$row['schema_key'].'@'.$row['version']] = $row['id'];
        }

        $rows = [];
        $seen = [];

        foreach ($this->links as $index => $link) {
            $label = "phase_schemas[{$index}]";
            $absent = array_diff(self::LINK_KEYS, array_keys((array) $link));

            if ($absent !== []) {
                throw new RuntimeException("{$label} is missing ".implode(', ', $absent).'.');
            }

            $schemaKey = $link['schema'].'@'.$link['version'];
            $pointer = $link['pointer'];

            if (! isset($phaseIds[$link['phase']])) {
                throw new RuntimeException("{$label} names phase {$link['phase']}, which the spec does not contain.");
            }

            if ($link['namespace'] !== $this->schemaNamespace || ! isset($schemaIds[$schemaKey])) {
                throw new RuntimeException("{$label} names schema {$link['namespace']}/{$schemaKey}, which this import does not contain.");
            }

            if (! in_array($link['role'], AiPhaseSchema::ROLES, true) || ! in_array($link['mode'], AiPhaseSchema::MODES, true)) {
                throw new RuntimeException("{$label} has an unknown role or mode.");
            }

            if (! is_string($link['slot']) || $link['slot'] === '' || strlen($link['slot']) > 64) {
                throw new RuntimeException("{$label} needs a slot of 1 to 64 characters.");
            }

            if (! AiPhaseSchema::isPointer($pointer)) {
                throw new RuntimeException("{$label} needs a confirmed JSON Pointer.");
            }

            if (! is_bool($link['required'])) {
                throw new RuntimeException("{$label} must state required as true or false.");
            }

            $key = $link['phase'].'|'.$link['role'].'|'.$link['slot'];

            if (isset($seen[$key])) {
                throw new RuntimeException("{$label} repeats phase, role and slot {$key}.");
            }

            $seen[$key] = true;
            $rows[] = [
                'id' => (string) Str::uuid(),
                'phase_id' => $phaseIds[$link['phase']],
                'schema_id' => $schemaIds[$schemaKey],
                'role' => $link['role'],
                'slot_key' => $link['slot'],
                'data_pointer' => $pointer,
                'validation_mode' => $link['mode'],
                'is_required' => $link['required'],
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, string>  $reuse
     */
    private function verify(array $plan, array $reuse): void
    {
        $stored = AiPhase::query()
            ->where('category_id', $plan['category']['id'])
            ->where('workflow_code', $plan['workflow_code'])
            ->orderBy('sort_order')
            ->get(['prompt_content', 'content_sha256']);
        $rebuilt = $stored->pluck('prompt_content')->implode('');

        if ($stored->count() !== count($plan['phases']) || ! hash_equals($plan['spec']['sha256'], hash('sha256', $rebuilt))) {
            throw new RuntimeException('The stored sections do not rebuild the spec byte for byte.');
        }

        foreach ($stored as $phase) {
            if (! hash_equals($phase->content_sha256, hash('sha256', $phase->prompt_content))) {
                throw new RuntimeException('A stored section does not match its hash.');
            }
        }

        $final = static fn (string $id): string => $reuse[$id] ?? $id;
        $schemas = AiJsonSchema::query()->whereIn('id', array_map($final, array_column($plan['schemas'], 'id')))->get()->keyBy('id');

        foreach ($plan['schemas'] as $row) {
            $schema = $schemas->get($final($row['id']));

            if ($schema === null
                || $schema->namespace !== $plan['namespace']
                || ! hash_equals($row['schema_sha256'], hash('sha256', $schema->schema_content))
                || $schema->dependencies != array_map($final, $row['dependencies'])) {
                throw new RuntimeException("Stored schema {$row['schema_key']} does not match its source.");
            }
        }

        if (AiPhaseSchema::query()->whereIn('phase_id', array_column($plan['phases'], 'id'))->count() !== count($plan['links'])) {
            throw new RuntimeException('The stored links do not match the confirmed links.');
        }
    }

    private function read(string $path): string
    {
        if (! is_file($path)) {
            throw new RuntimeException("Source file not found: {$path}");
        }

        $content = file_get_contents($path);

        if ($content === false || ! mb_check_encoding($content, 'UTF-8')) {
            throw new RuntimeException("Source file is unreadable or not UTF-8: {$path}");
        }

        return $content;
    }

    private function uri(string $name): string
    {
        return rtrim($this->schemaBaseUri, '/').'/'.$name;
    }

    private function relative(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $normal = str_replace('\\', '/', $path);

        return str_starts_with($normal, $base) ? substr($normal, strlen($base)) : $normal;
    }
}
