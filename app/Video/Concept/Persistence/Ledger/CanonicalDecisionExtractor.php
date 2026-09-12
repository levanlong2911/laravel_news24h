<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Ledger;

use App\Video\Concept\Canonical\CanonicalDesignSpec;

final class CanonicalDecisionExtractor
{
    /**
     * @return list<array{
     *     target_path:string,
     *     decision_type:string,
     *     value:mixed,
     *     provenance_origin:?string,
     *     source_aspects:list<string>,
     *     invariant_ids:list<string>,
     *     relationship_ids:list<string>
     * }>
     */
    public function extract(CanonicalDesignSpec $spec): array
    {
        $data = $spec->toArray();
        $provenance = $this->provenanceIndex($data['provenance']);
        $invariants = $this->invariantIndex($data['invariants']);
        $relationships = $this->relationshipIndex($data['relationships']);
        $result = [];

        $this->flatten($data['identity'], 'identity', 'identity', $provenance, $invariants, $relationships, $result);
        $this->flatten($data['dimensions'], 'dimensions', 'dimension', $provenance, $invariants, $relationships, $result);
        $this->flatten($data['permanent_geometry'], 'permanent_geometry', 'geometry', $provenance, $invariants, $relationships, $result);
        $this->flatten($data['form_relationships'], 'form_relationships', 'form_relationship', $provenance, $invariants, $relationships, $result);
        $this->flatten($data['finished_materials'], 'finished_materials', 'material', $provenance, $invariants, $relationships, $result);

        foreach ($data['relationships'] as $relationship) {
            $result[] = $this->makeDecision(
                targetPath: 'relationships.'.(string) $relationship['id'],
                decisionType: 'relationship',
                value: $relationship,
                provenance: $provenance,
                invariants: $invariants,
                relationships: $relationships,
            );
        }

        foreach ($data['exclusions'] as $exclusion) {
            $result[] = $this->makeDecision(
                targetPath: 'exclusions.'.(string) $exclusion['id'],
                decisionType: 'exclusion',
                value: $exclusion,
                provenance: $provenance,
                invariants: $invariants,
                relationships: $relationships,
            );
        }

        foreach ($data['invariants'] as $invariant) {
            $result[] = $this->makeDecision(
                targetPath: 'invariants.'.(string) $invariant['id'],
                decisionType: 'invariant',
                value: $invariant,
                provenance: $provenance,
                invariants: $invariants,
                relationships: $relationships,
            );
        }

        usort(
            $result,
            static fn (array $a, array $b): int => strcmp($a['target_path'], $b['target_path'])
        );

        return array_values($result);
    }

    private function flatten(
        mixed $value,
        string $path,
        string $type,
        array $provenance,
        array $invariants,
        array $relationships,
        array &$output,
    ): void {
        if (is_array($value) && ! array_is_list($value) && $value !== []) {
            foreach ($value as $key => $child) {
                $this->flatten(
                    $child,
                    $path.'.'.$key,
                    $type,
                    $provenance,
                    $invariants,
                    $relationships,
                    $output,
                );
            }

            return;
        }

        $output[] = $this->makeDecision(
            targetPath: $path,
            decisionType: $type,
            value: $value,
            provenance: $provenance,
            invariants: $invariants,
            relationships: $relationships,
        );
    }

    private function makeDecision(
        string $targetPath,
        string $decisionType,
        mixed $value,
        array $provenance,
        array $invariants,
        array $relationships,
    ): array {
        $source = $provenance[$targetPath] ?? null;

        return [
            'target_path' => $targetPath,
            'decision_type' => $decisionType,
            'value' => $value,
            'provenance_origin' => $source['origin'] ?? null,
            'source_aspects' => $source['source_aspects'] ?? [],
            'invariant_ids' => $invariants[$targetPath] ?? [],
            'relationship_ids' => $relationships[$targetPath] ?? [],
        ];
    }

    private function provenanceIndex(array $entries): array
    {
        $index = [];

        foreach ($entries as $entry) {
            $index[(string) $entry['target_path']] = [
                'origin' => $entry['origin'] ?? null,
                'source_aspects' => $entry['source_aspects'] ?? [],
            ];
        }

        return $index;
    }

    private function invariantIndex(array $entries): array
    {
        $index = [];

        foreach ($entries as $entry) {
            $path = (string) ($entry['source_path'] ?? '');

            if ($path === '') {
                continue;
            }

            $index[$path] ??= [];
            $index[$path][] = (string) $entry['id'];
        }

        return $index;
    }

    private function relationshipIndex(array $entries): array
    {
        $index = [];

        foreach ($entries as $entry) {
            $id = (string) ($entry['id'] ?? '');

            foreach (['subject_path', 'source_path', 'target_path', 'reference_path', 'container_path', 'contained_path'] as $key) {
                if (! isset($entry[$key]) || ! is_string($entry[$key]) || $entry[$key] === '') {
                    continue;
                }

                $index[$entry[$key]] ??= [];
                $index[$entry[$key]][] = $id;
            }

            foreach (['members', 'items'] as $key) {
                if (! isset($entry[$key]) || ! is_array($entry[$key])) {
                    continue;
                }

                foreach ($entry[$key] as $path) {
                    if (! is_string($path) || $path === '') {
                        continue;
                    }

                    $index[$path] ??= [];
                    $index[$path][] = $id;
                }
            }
        }

        return $index;
    }
}
