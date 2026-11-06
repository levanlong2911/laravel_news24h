<?php

declare(strict_types=1);

namespace App\Video\Moto;

use App\Video\Inspiration\PhaseOneEvidence;
use App\Video\Script\CheckedWorkflow;
use App\Video\Superyacht\Spec\ResolvedPhase;

final class MotoReference implements CheckedWorkflow
{
    public const CONTRACT = 'moto/MotoReferencePrompt@1.2.1';

    public function format(): string
    {
        return MotoContract::REFERENCE_FORMAT;
    }

    public function inputsVersion(): string
    {
        return MotoContract::REFERENCE_INPUT;
    }

    public function checkVersion(): string
    {
        return MotoReferenceCheck::VERSION;
    }

    /** @return array{execution: string, model: string, effort: string, max_tokens: int, timeout_seconds: int, schema_heading: string, estimate_output_tokens_per_cell: int} */
    public function settings(): array
    {
        $config = (array) config('video.moto.reference');

        return [
            'execution' => (string) $config['execution'],
            'model' => (string) $config['model'],
            'effort' => (string) $config['effort'],
            'max_tokens' => (int) $config['max_tokens'],
            'timeout_seconds' => (int) $config['timeout_seconds'],
            'schema_heading' => (string) $config['schema_heading'],
            'estimate_output_tokens_per_cell' => (int) $config['estimate_output_tokens_per_cell'],
        ];
    }

    /**
     * @param  list<string>  $parts
     * @param  list<array<string, mixed>>  $schemas
     */
    public function systemText(array $parts, array $schemas): string
    {
        $heading = (string) config('video.moto.reference.schema_heading');

        return implode("\n\n", [
            ...$parts,
            ...array_map(static fn (array $schema): string => "## {$heading} — {$schema['label']}\n\n```json\n".MotoContract::compactSchema((string) $schema['content'])."\n```", $schemas),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $references  the cells of one master that wait for the review, in cell order
     * @return array{payload: array<string, mixed>, context: array<string, mixed>} what the model reads for each cell — its key, kind, medium, state, the work done and not yet done, parts, camera, what code already says is not present, draft lines and facts — and what the check reads back
     */
    public static function inputs(array $references, string $regime, array $palette): array
    {
        $cells = [];
        $context = [];

        foreach ($references as $reference) {
            $cells[] = [
                'cell_id' => $reference['id'],
                'key' => $reference['key'],
                'kind' => $reference['kind'],
                'medium' => $reference['medium'],
                'state' => $reference['state'],
                'checkpoint' => $reference['checkpoint']['text'] ?? null,
                'work_done' => (array) $reference['requirement']['done'],
                'work_not_yet_done' => (array) $reference['requirement']['pending'],
                'parts' => array_map(static fn (array $part): array => array_intersect_key($part, array_flip(['part', 'name', 'role', 'presentation', 'location', 'status'])), (array) $reference['parts']),
                'camera' => array_intersect_key((array) $reference['camera'], array_flip(['target', 'target_part', 'scale', 'azimuth_deg', 'elevation_deg'])) + ['view' => $reference['requirement']['view']],
                'not_present' => (array) $reference['requirement']['absent'],
                'draft_lines' => (array) $reference['draft'],
                'facts' => array_map(static fn (array $fact): array => array_intersect_key($fact, array_flip(['id', 'part_id', 'element', 'topic', 'time', 'scope', 'value', 'source_path'])), (array) $reference['facts']),
            ];
            $context[$reference['id']] = MotoReferenceCheck::cellContext($reference, $palette);
        }

        return [
            'payload' => ['input_format' => MotoContract::REFERENCE_INPUT, 'regime' => $regime, 'cells' => $cells],
            'context' => ['format' => MotoContract::REFERENCE_FORMAT, 'cells' => $context],
        ];
    }

    public function schemaViolations(object $decoded, ResolvedPhase $bundle, string $projectId): array
    {
        return array_map(
            static fn (string $error): array => ['code' => 'SCHEMA', 'path' => '', 'message' => $error],
            PhaseOneEvidence::schemaErrors($decoded, $bundle->outputSchemas()),
        );
    }

    public function check(?array $response, array $context, array $schema): array
    {
        return MotoReferenceCheck::evaluate($response, $context, $schema);
    }

    /** @return array{envelope: list<string>, cells: list<array<string, mixed>>} what makes the whole answer unusable, and every checked cell; a cell of an unusable answer is never ready */
    public function derive(array $document, array $context): array
    {
        return ['envelope' => MotoReferenceCheck::envelopeIssues($document, $context), 'cells' => MotoReferenceCheck::cells($document, $context)];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  string  $contract  ScriptEvaluation::contract() of the pinned bundle
     */
    public function contextProblem(array $context, string $contract): ?string
    {
        if (! str_starts_with($contract, self::CONTRACT.'#')) {
            return "hợp đồng đầu ra là {$contract}; code kiểm Phase 8 cho ".self::CONTRACT.'.';
        }

        return ($context['cells'] ?? []) === [] ? 'không có ô nào để kiểm.' : null;
    }
}
