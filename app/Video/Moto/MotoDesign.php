<?php

declare(strict_types=1);

namespace App\Video\Moto;

use App\Video\Inspiration\PhaseOneEvidence;
use App\Video\Llm\JsonAnswer;
use App\Video\Superyacht\Spec\PhaseResolver;
use App\Video\YachtDesign\DesignWorkflow;
use RuntimeException;

final class MotoDesign implements DesignWorkflow
{
    public function execution(): string
    {
        return MotoContract::DESIGN_EXECUTION;
    }

    public function checkVersion(): ?string
    {
        return MotoDesignCheck::VERSION;
    }

    /** @return list<string> */
    public function markers(): array
    {
        return [MotoContract::DESIGN_FORMAT, 'Input format: `'.MotoContract::DESIGN_INPUT.'`'];
    }

    /** @return array<string, mixed> */
    public function modelSheetConfig(): array
    {
        return (array) config('video.moto.model_sheet');
    }

    /** @return string the schema re-encoded without whitespace between tokens */
    public function schemaText(string $content): string
    {
        return MotoContract::compactSchema($content);
    }

    /** @return array<string, mixed> */
    public function runSettings(): array
    {
        return (array) config('video.moto.design');
    }

    /**
     * @param  array<string, mixed>  $dna
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $dnaPanel
     * @param  array<string, mixed>  $modelSheet
     * @return array<string, mixed>
     */
    public function payload(string $projectId, string $promptVersion, array $dna, array $source, array $dnaPanel, array $modelSheet): array
    {
        return [
            'project_id' => $projectId,
            'prompt_version' => $promptVersion,
            'variables' => (array) config('video.superyacht.variables'),
            'dna' => $dna,
            'inspiration' => ['source_identity' => $source['source_identity'], 'base' => $source['base'] ?? null],
            'model_sheet' => $modelSheet,
        ];
    }

    /**
     * @param  array{project_id: string, prompt_version: string, dna: array<mixed>, source_identity: array<mixed>, model_sheet: array<string, mixed>, forbidden: list<string>}  $run
     * @param  ?array<string, mixed>  $manifest
     * @return array{0: ?object, 1: array<string, mixed>}
     */
    public function evaluate(string $raw, array $run, ?array $manifest): array
    {
        [$decoded, $error] = JsonAnswer::parse($raw);

        if ($error !== null || ! is_object($decoded)) {
            return [null, MotoDesignCheck::evaluate(null, $run['project_id'], $run['prompt_version'], $run['dna'], $run['source_identity'], $run['model_sheet'], [], [])];
        }

        try {
            $schemaErrors = PhaseOneEvidence::schemaErrors($decoded, PhaseResolver::fromConfig((array) config('video.superyacht'))
                ->fromManifest($manifest ?? throw new RuntimeException('lượt này không lưu manifest hợp đồng'))
                ->outputSchemas());
        } catch (\Throwable $e) {
            $schemaErrors = ['không kiểm được schema: '.$e->getMessage()];
        }

        $response = json_decode((string) json_encode($decoded), true);

        return [$decoded, MotoDesignCheck::evaluate(
            is_array($response) ? $response : null,
            $run['project_id'],
            $run['prompt_version'],
            $run['dna'],
            $run['source_identity'],
            $run['model_sheet'],
            $run['forbidden'],
            $schemaErrors,
        )];
    }
}
