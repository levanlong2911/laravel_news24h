<?php

declare(strict_types=1);

namespace App\Video\YachtDesign;

use App\Services\Video\YachtDesignService;
use App\Video\Inspiration\PhaseOneEvidence;
use App\Video\Llm\JsonAnswer;
use App\Video\ModelSheet\ModelSheetFacts;
use App\Video\Superyacht\Spec\PhaseResolver;
use App\Video\Superyacht\Support\CanonicalJson;
use RuntimeException;

final class SuperyachtDesign implements DesignWorkflow
{
    public function execution(): string
    {
        return YachtDesignService::EXECUTION;
    }

    public function checkVersion(): ?string
    {
        return null;
    }

    /** @return list<string> */
    public function markers(): array
    {
        return [YachtDesignCheck::FORMAT_V3, 'Input format: `'.YachtDesignCheck::INPUT_FORMAT_V2.'`'];
    }

    /** @return array<string, mixed> */
    public function modelSheetConfig(): array
    {
        return (array) config('video.superyacht.model_sheet');
    }

    public function schemaText(string $content): string
    {
        return trim($content);
    }

    /** @return array<string, mixed> */
    public function runSettings(): array
    {
        return [];
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
            'novelty' => $dnaPanel['novelty'],
            'inspiration' => ['source_identity' => $source['source_identity']],
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
        $text = BuildStateText::fromConfig();

        if ($error !== null || ! is_object($decoded)) {
            return [null, YachtDesignCheck::evaluate(null, $run['project_id'], $run['dna'], $run['source_identity'], [], [], $text, self::limits())];
        }

        $canonical = $decoded->data->canonical ?? null;

        if (is_object($canonical)) {
            foreach (is_array($canonical->build_states ?? null) ? $canonical->build_states : [] as $state) {
                if (is_object($state)) {
                    $state->compiled_text = $text->compile((array) json_decode((string) json_encode($state), true)) ?? '';
                }
            }

            $canonical->hash = CanonicalJson::hash($canonical, ['hash']);
        }

        $schemaIssues = [];
        $wordLimits = [];

        try {
            $bundle = PhaseResolver::fromConfig((array) config('video.superyacht'))
                ->fromManifest($manifest ?? throw new RuntimeException('lượt này không lưu manifest hợp đồng'));

            if (($decoded->format ?? null) === YachtDesignCheck::FORMAT_V3) {
                $schemaIssues = PhaseOneEvidence::schemaErrors($decoded, $bundle->outputSchemas());
            } else {
                $result = $bundle->checkOutput((string) json_encode($decoded), $run['project_id'], 'draft');

                foreach ($result['report']->violations as $violation) {
                    $schemaIssues[] = trim($violation->path.' '.$violation->message);
                }
            }

            $wordLimits = $bundle->wordLimits();
        } catch (\Throwable $e) {
            $schemaIssues[] = 'không kiểm được schema: '.$e->getMessage();
        }

        $response = json_decode((string) json_encode($decoded), true);

        return [$decoded, YachtDesignCheck::evaluate(
            is_array($response) ? $response : null,
            $run['project_id'],
            $run['dna'],
            $run['source_identity'],
            $run['forbidden'],
            $schemaIssues,
            $text,
            self::limits(),
            $wordLimits,
            is_array($response) && ($response['format'] ?? null) === YachtDesignCheck::FORMAT ? self::handoff($response) : [],
            $run['prompt_version'],
        )];
    }

    /**
     * @param  array<string, mixed>  $design  a Phase 3 answer with its canonical hash and build-state texts filled in
     * @return list<string> what keeps the model sheet from opening this design, by the model sheet's own blocking checks
     */
    public static function handoff(array $design): array
    {
        $config = (array) config('video.superyacht.model_sheet');

        try {
            $facts = new ModelSheetFacts(
                $design,
                array_map(static fn (array $view): array => ['id' => (string) $view['id'], 'view' => (string) $view['view']], array_values((array) $config['views'])),
                (array) $config['cameras'],
            );

            return ModelSheetFacts::blocking($facts->snapshot());
        } catch (\Throwable $e) {
            return ['không dựng được bảng dữ kiện model sheet: '.$e->getMessage()];
        }
    }

    /** @return array{profile_margin_m: float, near_x: float, near_z_m: float} */
    private static function limits(): array
    {
        $limits = (array) config('video.superyacht.design_check');

        return [
            'profile_margin_m' => (float) $limits['profile_margin_m'],
            'near_x' => (float) $limits['near_x'],
            'near_z_m' => (float) $limits['near_z_m'],
        ];
    }
}
