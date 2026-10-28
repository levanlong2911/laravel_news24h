<?php

declare(strict_types=1);

namespace App\Video\Superyacht\Spec;

use App\Models\AiJsonSchema;
use App\Models\AiPhase;
use App\Models\Category;
use App\Video\Superyacht\Import\SpecImporter;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;
use Opis\JsonSchema\Uri;
use Opis\JsonSchema\Validator;
use RuntimeException;

final class PhaseRelease
{
    private const LOCK = 'yacht-phase-release';

    public const CREATED_BY = 'video:phase-release';

    /** @param array<string, mixed> $config video.superyacht */
    public function __construct(private readonly array $config) {}

    /** @param array<string, mixed> $config video.superyacht */
    public static function fromConfig(array $config): self
    {
        return new self($config);
    }

    /** @return array<string, mixed> */
    public function plan(string $categorySlug, string $packageDir): array
    {
        return $this->planFor($categorySlug, $this->package($packageDir));
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>
     */
    private function planFor(string $categorySlug, array $package): array
    {
        $category = Category::query()->where('slug', $categorySlug)->first() ?? throw new RuntimeException("Category {$categorySlug} does not exist.");
        $workflow = (string) ($this->config['contracts']['categories'][$categorySlug] ?? throw new RuntimeException("Category {$categorySlug} has no configured workflow."));

        if ($workflow !== $package['workflow']) {
            throw new RuntimeException("Package {$package['name']} is for workflow {$package['workflow']}; category {$categorySlug} uses {$workflow}.");
        }

        $schemas = [];
        $available = [];

        foreach ($package['schemas'] as $schema) {
            $label = "{$schema['namespace']}/{$schema['key']}@{$schema['version']}";
            $stored = AiJsonSchema::query()->where('namespace', $schema['namespace'])->where('schema_key', $schema['key'])->where('version', $schema['version'])->first();

            if ($stored !== null && ! hash_equals((string) $stored->schema_sha256, $schema['sha256'])) {
                throw new RuntimeException("{$label} is already stored with different content; publish a new version instead. Nothing written.");
            }

            if ($stored !== null && $stored->status !== AiJsonSchema::PUBLISHED) {
                throw new RuntimeException("{$label} is stored but not published. Nothing written.");
            }

            $schemas[] = ['label' => $label, 'sha256' => $schema['sha256'], 'action' => $stored === null ? 'create' : 'reuse', 'stored_id' => $stored?->id];
            $available[$label] = $stored?->id ?? '@'.$label;
        }

        $desired = [];

        foreach (ActionContract::desiredLinks((array) ($this->config['contracts']['workflows'][$workflow] ?? [])) as $row) {
            $desired[$row['phase']][] = $row;
        }

        $phases = [];

        foreach ($package['phases'] as $phase) {
            $current = AiPhase::query()->where('category_id', $category->id)->where('workflow_code', $workflow)
                ->where('phase_code', $phase['code'])->where('is_current', true)->with('schemaLinks.schema')->get();

            if ($current->count() !== 1) {
                throw new RuntimeException("Category {$categorySlug} has {$current->count()} current revisions of {$phase['code']} instead of one.");
            }

            $current = $current->first();

            if ($current->status !== AiPhase::PUBLISHED || ! hash_equals((string) $current->content_sha256, hash('sha256', (string) $current->prompt_content))) {
                throw new RuntimeException("Current {$phase['code']} revision {$current->revision} is not published or does not match its hash.");
            }

            $links = [];

            foreach ($desired[$phase['code']] ?? [] as $row) {
                $label = "{$row['namespace']}/{$row['schema']}@{$row['version']}";
                $schemaId = $available[$label] ?? AiJsonSchema::query()->where('namespace', $row['namespace'])->where('schema_key', $row['schema'])
                    ->where('version', $row['version'])->where('status', AiJsonSchema::PUBLISHED)->value('id')
                    ?? throw new RuntimeException("{$phase['code']} {$row['role']}/{$row['slot']} needs {$label}, which is neither published nor in the package.");
                $links[] = [
                    'role' => $row['role'],
                    'slot_key' => $row['slot'],
                    'schema' => $label,
                    'schema_id' => (string) $schemaId,
                    'data_pointer' => $row['pointer'],
                    'validation_mode' => $row['mode'],
                    'is_required' => $row['required'],
                ];
            }

            usort($links, static fn (array $a, array $b): int => [$a['role'], $a['slot_key']] <=> [$b['role'], $b['slot_key']]);
            $have = $current->schemaLinks->map(static fn ($link): array => [
                'role' => $link->role,
                'slot_key' => $link->slot_key,
                'schema' => $link->schema === null ? (string) $link->schema_id : "{$link->schema->namespace}/{$link->schema->schema_key}@{$link->schema->version}",
                'schema_id' => (string) $link->schema_id,
                'data_pointer' => (string) $link->data_pointer,
                'validation_mode' => $link->validation_mode,
                'is_required' => (bool) $link->is_required,
            ])->sortBy([['role', 'asc'], ['slot_key', 'asc']])->values()->all();
            $sameLinks = array_map(static fn (array $l): array => array_diff_key($l, ['schema_id' => true]), $links) == array_map(static fn (array $l): array => array_diff_key($l, ['schema_id' => true]), $have);
            $unchanged = hash_equals((string) $current->content_sha256, $phase['sha256']) && $sameLinks;

            $phases[] = [
                'code' => $phase['code'],
                'action' => $unchanged ? 'unchanged' : 'revise',
                'current_id' => (string) $current->id,
                'current_revision' => (int) $current->revision,
                'current_sha256' => (string) $current->content_sha256,
                'sha256' => $phase['sha256'],
                'note' => $phase['note'],
                'links' => array_map(static fn (array $l): string => "{$l['role']}/{$l['slot_key']} -> {$l['schema']} ".($l['data_pointer'] === '' ? '(whole record)' : $l['data_pointer'])." {$l['validation_mode']}".($l['is_required'] ? ' required' : ' optional'), $links),
            ];
        }

        $plan = [
            'package' => ['name' => $package['name'], 'sha256' => $package['sha256']],
            'category' => ['id' => (string) $category->id, 'slug' => $categorySlug],
            'workflow' => $workflow,
            'schemas' => array_map(static fn (array $s): array => array_diff_key($s, ['stored_id' => true]), $schemas),
            'phases' => $phases,
        ];
        $plan['plan_hash'] = CanonicalJson::hash($plan);

        return $plan;
    }

    /** @return array{schemas: list<string>, phases: list<array{code: string, revision: int, id: string}>, changed: bool, backup: ?string} */
    public function apply(string $categorySlug, string $packageDir, string $expectedPlan, string $by = self::CREATED_BY): array
    {
        if ((int) DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired', [self::LOCK])->acquired !== 1) {
            throw new RuntimeException('Another release is running.');
        }

        try {
            $package = $this->package($packageDir);

            return DB::transaction(function () use ($categorySlug, $package, $expectedPlan, $by): array {
                $plan = $this->planFor($categorySlug, $package);

                if (! hash_equals($plan['plan_hash'], strtolower(trim($expectedPlan)))) {
                    throw new RuntimeException("The release plan is now {$plan['plan_hash']}, not the reviewed {$expectedPlan}. Nothing written.");
                }

                $changes = in_array('create', array_column($plan['schemas'], 'action'), true) || in_array('revise', array_column($plan['phases'], 'action'), true);
                $backup = $changes ? $this->backup($plan) : null;
                $created = [];

                foreach ($package['schemas'] as $index => $schema) {
                    if ($plan['schemas'][$index]['action'] !== 'create') {
                        continue;
                    }

                    AiJsonSchema::query()->create([
                        'namespace' => $schema['namespace'],
                        'schema_key' => $schema['key'],
                        'version' => $schema['version'],
                        'name' => $schema['title'],
                        'schema_content' => $schema['content'],
                        'schema_sha256' => $schema['sha256'],
                        'source_file' => $schema['source_file'],
                        'json_schema_id' => $schema['uri'],
                        'json_schema_dialect' => SpecImporter::DIALECT,
                        'dependencies' => [],
                        'status' => AiJsonSchema::PUBLISHED,
                        'change_note' => "Release {$package['name']} (plan {$plan['plan_hash']}).",
                        'created_by' => $by,
                        'published_at' => now(),
                    ]);
                    $created[] = $plan['schemas'][$index]['label'];
                }

                $revisions = PhaseRevisions::fromConfig($this->config);
                $written = [];

                foreach ($package['phases'] as $index => $phase) {
                    $planned = $plan['phases'][$index];

                    if ($planned['action'] !== 'revise') {
                        continue;
                    }

                    $current = AiPhase::query()->findOrFail($planned['current_id']);
                    $draft = $revisions->saveDraft($current, $phase['content'], $this->links($plan['workflow'], $phase['code']), "{$phase['note']} (release {$package['name']}, plan {$plan['plan_hash']})", $by);
                    $published = $revisions->publish($draft, $current->id);
                    $written[] = ['code' => $published->phase_code, 'revision' => (int) $published->revision, 'id' => (string) $published->id];
                }

                $resolver = PhaseResolver::fromConfig($this->config);

                foreach ((array) ($this->config['contracts']['workflows'][$plan['workflow']]['actions'] ?? []) as $key => $action) {
                    if (! in_array($action['phase'] ?? null, array_column($package['phases'], 'code'), true)) {
                        continue;
                    }

                    $bundle = $resolver->forCategory($plan['category']['id'], (string) $key);

                    if (! $bundle->ready()) {
                        throw new RuntimeException("After the release, action {$key} is not ready: ".implode('; ', $bundle->mismatches()).'. Nothing written.');
                    }
                }

                return ['schemas' => $created, 'phases' => $written, 'changed' => $created !== [] || $written !== [], 'backup' => $backup];
            });
        } finally {
            DB::select('SELECT RELEASE_LOCK(?) AS released', [self::LOCK]);
        }
    }

    /** @param array<string, mixed> $plan */
    private function backup(array $plan): string
    {
        $phases = AiPhase::query()->whereIn('id', array_column($plan['phases'], 'current_id'))->with('schemaLinks')->get();
        $path = 'deployments/phase-release/'.now()->format('Ymd-His').'-'.Str::uuid().'.json';
        $content = json_encode([
            'plan' => $plan,
            'taken_at' => now()->toIso8601String(),
            'phases' => $phases->map(static fn (AiPhase $phase): array => $phase->getAttributes() + [
                'links' => $phase->schemaLinks->map(static fn ($link): array => $link->getAttributes())->all(),
            ])->values()->all(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $disk = Storage::disk('local');

        if (! $disk->put($path, $content) || ! hash_equals(hash('sha256', $content), hash('sha256', (string) $disk->get($path)))) {
            throw new RuntimeException("Could not write the backup {$path}. Nothing written.");
        }

        return $disk->path($path);
    }

    /** @return list<array{role: string, slot_key: string, schema_id: string, data_pointer: string, validation_mode: string, is_required: bool}> */
    private function links(string $workflow, string $phaseCode): array
    {
        $links = [];

        foreach (ActionContract::desiredLinks((array) ($this->config['contracts']['workflows'][$workflow] ?? [])) as $row) {
            if ($row['phase'] !== $phaseCode) {
                continue;
            }

            $links[] = [
                'role' => $row['role'],
                'slot_key' => $row['slot'],
                'schema_id' => (string) AiJsonSchema::query()->where('namespace', $row['namespace'])->where('schema_key', $row['schema'])
                    ->where('version', $row['version'])->where('status', AiJsonSchema::PUBLISHED)->valueOrFail('id'),
                'data_pointer' => $row['pointer'],
                'validation_mode' => $row['mode'],
                'is_required' => $row['required'],
            ];
        }

        return $links;
    }

    /**
     * @return array{name: string, workflow: string, sha256: string, schemas: list<array{namespace: string, key: string, version: string, title: string, uri: string, content: string, sha256: string, source_file: string}>, phases: list<array{code: string, content: string, sha256: string, note: string}>}
     */
    private function package(string $dir): array
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        $manifest = $this->json($dir.'/release.json');

        foreach (['name', 'workflow', 'schemas', 'phases'] as $key) {
            if (! array_key_exists($key, $manifest)) {
                throw new RuntimeException("release.json has no {$key}.");
            }
        }

        $hashes = ['release.json' => hash('sha256', $this->read($dir.'/release.json'))];
        $schemas = [];
        $validator = new Validator;

        foreach ((array) $manifest['schemas'] as $entry) {
            $label = ($entry['namespace'] ?? '?').'/'.($entry['key'] ?? '?').'@'.($entry['version'] ?? '?');

            foreach (['namespace', 'key', 'version', 'file'] as $key) {
                if (! is_string($entry[$key] ?? null) || $entry[$key] === '') {
                    throw new RuntimeException("Schema {$label} in release.json needs {$key}.");
                }
            }

            $content = $this->read($dir.'/'.$entry['file']);
            $decoded = $this->decode($content, $entry['file']);
            $uri = rtrim((string) $this->config['schema_base_uri'], '/').'/'.$entry['key'];

            if (($decoded->{'$id'} ?? null) !== $uri) {
                throw new RuntimeException("Schema {$label} declares \$id ".json_encode($decoded->{'$id'} ?? null)."; the configured URI is {$uri}.");
            }

            if (($decoded->{'$schema'} ?? null) !== SpecImporter::DIALECT) {
                throw new RuntimeException("Schema {$label} does not declare the dialect ".SpecImporter::DIALECT.'.');
            }

            if (! is_string($decoded->title ?? null) || trim($decoded->title) === '' || mb_strlen($decoded->title) > 255) {
                throw new RuntimeException("Schema {$label} needs a title of 1 to 255 characters.");
            }

            foreach ($this->references($decoded) as $reference) {
                if (! str_starts_with($reference, '#')) {
                    throw new RuntimeException("Schema {$label} references {$reference}; a release schema may only reference itself.");
                }
            }

            if (! $validator->resolver()->registerRaw($content, $uri)) {
                throw new RuntimeException("Schema {$label} could not be compiled.");
            }

            foreach (['valid' => true, 'invalid' => false] as $kind => $expected) {
                $files = (array) ($entry[$kind] ?? []);

                if ($expected && $files === []) {
                    throw new RuntimeException("Schema {$label} lists no valid fixture.");
                }

                foreach ($files as $file) {
                    $fixture = $this->read($dir.'/'.$file);
                    $hashes[$file] = hash('sha256', $fixture);

                    if ($validator->validate($this->decode($fixture, (string) $file), Uri::parse($uri, true))->isValid() !== $expected) {
                        throw new RuntimeException("Fixture {$file} is expected to be {$kind} against {$label}.");
                    }
                }
            }

            $hashes[$entry['file']] = hash('sha256', $content);
            $schemas[] = [
                'namespace' => $entry['namespace'],
                'key' => $entry['key'],
                'version' => $entry['version'],
                'title' => trim($decoded->title),
                'uri' => $uri,
                'content' => $content,
                'sha256' => hash('sha256', $content),
                'source_file' => 'release:'.$manifest['name'].'/'.$entry['file'],
            ];
        }

        $phases = [];

        foreach ((array) $manifest['phases'] as $entry) {
            if (! is_string($entry['code'] ?? null) || ! is_string($entry['file'] ?? null) || ! is_string($entry['note'] ?? null) || trim($entry['note']) === '') {
                throw new RuntimeException('Every phase in release.json needs code, file and note.');
            }

            $content = $this->read($dir.'/'.$entry['file']);
            $hashes[$entry['file']] = hash('sha256', $content);
            $phases[] = ['code' => $entry['code'], 'content' => $content, 'sha256' => hash('sha256', $content), 'note' => trim($entry['note'])];
        }

        if ($schemas === [] && $phases === []) {
            throw new RuntimeException('The package releases nothing.');
        }

        ksort($hashes);

        return [
            'name' => (string) $manifest['name'],
            'workflow' => (string) $manifest['workflow'],
            'sha256' => CanonicalJson::hash($hashes),
            'schemas' => $schemas,
            'phases' => $phases,
        ];
    }

    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        $decoded = json_decode($this->read($path), true);

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException("{$path} is not a JSON object.");
        }

        return $decoded;
    }

    private function decode(string $content, string $label): object
    {
        try {
            $decoded = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("{$label} is not valid JSON: {$e->getMessage()}", 0, $e);
        }

        return is_object($decoded) ? $decoded : throw new RuntimeException("{$label} is not a JSON object.");
    }

    private function read(string $path): string
    {
        if (! is_file($path) || ($content = file_get_contents($path)) === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        return $content;
    }

    /** @return list<string> */
    private function references(mixed $node): array
    {
        $found = [];

        if (is_object($node)) {
            foreach (get_object_vars($node) as $key => $value) {
                if ($key === '$ref' && is_string($value)) {
                    $found[] = $value;
                } else {
                    array_push($found, ...$this->references($value));
                }
            }
        } elseif (is_array($node)) {
            foreach ($node as $value) {
                array_push($found, ...$this->references($value));
            }
        }

        return $found;
    }
}
