<?php

namespace App\Services\AI\AFOS\Benchmark;

/**
 * BaselineStore — immutable snapshot storage for compiler regression gates.
 *
 * PRINCIPLES
 * ----------
 * 1. Baselines live in resources/afos/baselines/ — version-controlled.
 *    A baseline commit = an intentional, reviewed compiler output approval.
 * 2. Baselines are WRITE-ONCE: lock() refuses if the name already exists.
 *    To "update" a baseline, create a new version (v2, v3...).
 * 3. Schema version is stored in manifest. Comparing baselines with different
 *    schema versions emits a warning — fields changed, hashes are incompatible.
 *
 * DIRECTORY STRUCTURE
 * -------------------
 *   resources/afos/baselines/{name}/
 *   ├── manifest.json      — name, schema_version, locked_at, domains
 *   ├── {domain}.json      — { scenes: { "scene_title": { semantic_hash, ... } } }
 *   └── checksum.sha256    — sha256 of manifest.json (integrity check)
 *
 * USAGE
 * -----
 *   php artisan afos:benchmark --domain=all --lock-baseline=v1
 *   php artisan afos:benchmark --domain=all --compare-baseline=v1
 */
final class BaselineStore
{
    private readonly string $basePath;

    public function __construct()
    {
        $this->basePath = resource_path('afos/baselines');
    }

    /**
     * Lock current benchmark results as a named, immutable baseline.
     *
     * @param array<string, array<int, array<string, mixed>>> $domainResults  domain → results[]
     * @throws \RuntimeException if baseline already exists
     */
    public function lock(string $name, array $domainResults): void
    {
        $dir = "{$this->basePath}/{$name}";

        if (is_dir($dir)) {
            throw new \RuntimeException(
                "Baseline '{$name}' already exists and is immutable. Use a new name (e.g. v2)."
            );
        }

        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create baseline directory: {$dir}");
        }

        $manifest = [
            'baseline_name'  => $name,
            'schema_version' => SemanticHashPolicy::SCHEMA_VERSION,
            'locked_at'      => date('c'),
            'domains'        => array_keys($domainResults),
            'total_scenes'   => array_sum(array_map('count', $domainResults)),
        ];

        foreach ($domainResults as $domain => $results) {
            $scenes = [];
            foreach ($results as $result) {
                $sceneTitle = $result['scene_title'] ?? '';
                if ($sceneTitle === '') {
                    continue;
                }
                $scenes[$sceneTitle] = [
                    'semantic_hash'  => $result['semantic_hash'],
                    'goal_target'    => $result['goal_target'],
                    'entity_match'   => $result['entity_match'],
                    'snapshot'       => $result['snapshot'] ?? [],
                ];
            }
            file_put_contents(
                "{$dir}/{$domain}.json",
                json_encode(['domain' => $domain, 'scenes' => $scenes], JSON_PRETTY_PRINT)
            );
        }

        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT);
        file_put_contents("{$dir}/manifest.json", $manifestJson);
        file_put_contents("{$dir}/checksum.sha256", hash('sha256', $manifestJson));
    }

    /**
     * Compare current results against a locked baseline.
     * Returns structured diff per domain.
     *
     * @param array<string, array<int, array<string, mixed>>> $domainResults
     * @return array{baseline: string, schema_compatible: bool, diffs: array<string, array>}
     */
    public function compare(string $name, array $domainResults): array
    {
        $dir          = "{$this->basePath}/{$name}";
        $manifestPath = "{$dir}/manifest.json";

        if (!file_exists($manifestPath)) {
            throw new \RuntimeException("Baseline '{$name}' not found at {$dir}");
        }

        $manifest  = json_decode(file_get_contents($manifestPath), true);
        $policy    = new SemanticHashPolicy();
        $compatible = $policy->isCompatible($manifest['schema_version'] ?? '');
        $diffs     = [];

        foreach ($domainResults as $domain => $results) {
            $domainFile = "{$dir}/{$domain}.json";

            if (!file_exists($domainFile)) {
                $diffs[$domain] = ['status' => 'no_baseline', 'changes' => [], 'unchanged' => 0];
                continue;
            }

            $baseline  = json_decode(file_get_contents($domainFile), true);
            $scenes    = $baseline['scenes'] ?? [];
            $changes   = [];
            $unchanged = 0;

            foreach ($results as $result) {
                $scene = $result['scene_title'] ?? '';

                if (!isset($scenes[$scene])) {
                    $changes[] = ['scene' => $scene, 'type' => 'new'];
                    continue;
                }

                $baseHash = $scenes[$scene]['semantic_hash'];
                $currHash = $result['semantic_hash'];

                if ($baseHash !== $currHash) {
                    $changes[] = [
                        'scene'     => $scene,
                        'type'      => 'changed',
                        'base_hash' => substr($baseHash, 0, 16),
                        'curr_hash' => substr($currHash, 0, 16),
                    ];
                } else {
                    $unchanged++;
                }
            }

            $diffs[$domain] = [
                'status'    => empty($changes) ? 'ok' : 'changed',
                'changes'   => $changes,
                'unchanged' => $unchanged,
            ];
        }

        return [
            'baseline'          => $name,
            'baseline_schema'   => $manifest['schema_version'] ?? '?',
            'current_schema'    => SemanticHashPolicy::SCHEMA_VERSION,
            'schema_compatible' => $compatible,
            'diffs'             => $diffs,
        ];
    }

    /**
     * List all available baselines.
     * @return array<int, array{name: string, locked_at: string, schema_version: string, domains: string[]}>
     */
    public function list(): array
    {
        if (!is_dir($this->basePath)) {
            return [];
        }

        $baselines = [];

        foreach (scandir($this->basePath) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $manifestPath = "{$this->basePath}/{$entry}/manifest.json";

            if (is_dir("{$this->basePath}/{$entry}") && file_exists($manifestPath)) {
                $manifest    = json_decode(file_get_contents($manifestPath), true);
                $baselines[] = [
                    'name'           => $entry,
                    'locked_at'      => $manifest['locked_at'] ?? '?',
                    'schema_version' => $manifest['schema_version'] ?? '?',
                    'domains'        => $manifest['domains'] ?? [],
                    'total_scenes'   => $manifest['total_scenes'] ?? 0,
                ];
            }
        }

        return $baselines;
    }

    public function exists(string $name): bool
    {
        return is_dir("{$this->basePath}/{$name}");
    }
}
