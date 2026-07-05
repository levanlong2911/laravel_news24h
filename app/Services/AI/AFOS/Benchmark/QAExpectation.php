<?php

namespace App\Services\AI\AFOS\Benchmark;

use Symfony\Component\Yaml\Yaml;

/**
 * QAExpectation — complete set of QA metrics for one scene.
 *
 * Vision QA Engine evaluates a scene by iterating $expectation->metrics:
 *
 *   foreach ($expectation->metrics as $metric) {
 *       $detected = $this->visionModel->detect($metric->id, $frame);
 *       $pass     = $this->evaluate($metric, $detected);
 *       $results[] = ['metric' => $metric->id, 'pass' => $pass, 'detected' => $detected];
 *   }
 *
 * No switch-case. No if(field == "reflection_visible"). Pure plugin loop.
 */
final class QAExpectation
{
    /** @param QAMetric[] $metrics */
    public function __construct(
        public readonly string $sceneTitle,
        public readonly array  $metrics,
    ) {}

    public static function fromYamlEntry(string $sceneTitle, array $entry): self
    {
        $metricData = $entry['qa']['metrics'] ?? [];
        $metrics    = array_map(fn(array $m) => QAMetric::fromArray($m), $metricData);
        return new self($sceneTitle, $metrics);
    }

    /**
     * Load all expectations for a domain from its expected_outputs.yaml.
     * Returns empty map if file not found.
     *
     * @return array<string, self>  keyed by scene_title
     */
    public static function forDomain(string $domain): array
    {
        $yamlPath = resource_path("afos/domains/{$domain}/expected_outputs.yaml");

        if (!file_exists($yamlPath)) {
            return [];
        }

        $data   = Yaml::parseFile($yamlPath);
        $result = [];

        foreach ($data['ground_truth'] ?? [] as $sceneTitle => $entry) {
            if (!empty($entry['qa']['metrics'])) {
                $result[$sceneTitle] = self::fromYamlEntry($sceneTitle, $entry);
            }
        }

        return $result;
    }

    public function metricCount(): int
    {
        return count($this->metrics);
    }

    /** @return array<string, mixed>[] */
    public function toArray(): array
    {
        return array_map(fn(QAMetric $m) => $m->toArray(), $this->metrics);
    }
}
