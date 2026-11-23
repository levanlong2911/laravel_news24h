<?php

declare(strict_types=1);

namespace App\Video\Script;

use App\Video\Llm\JsonAnswer;
use App\Video\Superyacht\Spec\PhaseResolver;
use App\Video\Superyacht\Spec\ResolvedPhase;
use App\Video\Superyacht\Validation\Violation;
use RuntimeException;

final class ScriptEvaluation
{
    /**
     * @param  array<string, mixed>  $manifest  the bundle the run was sent with
     * @param  array<string, mixed>  $context  the check context pinned with the run
     * @param  array<string, mixed>  $source  the pinned source of the run
     * @return array{document: ?array<string, mixed>, parse_error: ?string, check: array<string, mixed>, derived: ?array<string, mixed>, contract: string, content_hash: ?string}
     */
    public static function of(string $raw, array $manifest, array $context, array $source, CheckedWorkflow $adapter): array
    {
        [$decoded, $parseError] = JsonAnswer::parse($raw);
        $document = $parseError === null ? json_decode((string) json_encode($decoded), true) : null;
        $document = is_array($document) && ! array_is_list($document) ? $document : null;
        $contract = self::contract($manifest);
        $schema = [];
        $providerDocument = $document;
        $preparation = null;

        if ($document !== null) {
            $bundle = PhaseResolver::fromConfig((array) config('video.superyacht'))->fromManifest($manifest);

            if ($adapter instanceof NormalizesAnswer) {
                $prepared = $adapter->normalizeAnswer($decoded, $bundle);
                $decoded = $prepared['answer'];
                $document = json_decode(json_encode($decoded, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

                if ($prepared['version'] !== null) {
                    $preparation = [
                        'version' => $prepared['version'],
                        'changes' => $prepared['changes'],
                        'provider_sha256' => hash('sha256', $raw),
                        'content_hash' => ScriptContentHash::of($contract, $document, $source),
                    ];
                }
            }

            $schema = $adapter->schemaViolations($decoded, $bundle, (string) $context['project_id']);
        }

        $check = self::checkOf($document, $context, $schema, $adapter);
        $derived = $document !== null && $check['schema'] === [] && $check['status'] !== 'fail' && $check['verdict'] !== 'UNSCORED'
            ? $adapter->derive($document, $context)
            : null;

        return [
            'document' => $document,
            'parse_error' => $document === null ? ($parseError ?? 'The answer is not a JSON object.') : null,
            'check' => $check,
            'derived' => $derived,
            'contract' => $contract,
            // Integrity is always checked against the untouched provider answer, including old runs.
            'content_hash' => $providerDocument === null ? null : ScriptContentHash::of($contract, $providerDocument, $source),
        ] + ($preparation === null ? [] : ['answer_preparation' => $preparation]);
    }

    /**
     * The check of an answer from its schema violations: a fault of the check itself never reaches
     * the rules of the phase — the answer is unscored with that cause, never failed.
     *
     * @param  ?array<string, mixed>  $document
     * @param  array<string, mixed>  $context
     * @param  list<array{code: string, path: string, message: string}>  $schema  the schema violations of the answer
     * @return array<string, mixed>
     */
    public static function checkOf(?array $document, array $context, array $schema, CheckedWorkflow $adapter): array
    {
        $fault = self::configFault($schema);
        $check = $fault === null
            ? $adapter->check($document, $context, $schema)
            : ['error' => $fault, 'config_fault' => true] + $adapter->check(null, $context, []);

        if ($adapter instanceof StructureChecked) {
            // RULE judges the structure and the run the answer belongs to; what the next step needs becomes a requirement and a judgement of the content a warning.
            $check = $adapter->sortFindings($check);
            // Which version of the structure check judged the answer, recorded beside the result.
            $check['technical_schema'] = $adapter->structurePolicy();
        }

        return $check;
    }

    /**
     * @param  list<array{code: string, path: string, message: string}>  $schema
     * @return ?string why the check could not run on the pinned schemas (StructureChecked::CONFIG_FAULT), null when it ran
     */
    private static function configFault(array $schema): ?string
    {
        foreach ($schema as $violation) {
            if (($violation['code'] ?? null) === StructureChecked::CONFIG_FAULT) {
                return (string) $violation['message'];
            }
        }

        return null;
    }

    /**
     * @return list<array{code: string, path: string, message: string}>
     */
    public static function envelopeViolations(object $decoded, ResolvedPhase $bundle, string $projectId): array
    {
        $result = $bundle->checkOutput((string) json_encode($decoded), $projectId, 'draft');

        return array_map(
            static fn (Violation $violation): array => ['code' => $violation->code, 'path' => (string) $violation->path, 'message' => $violation->message],
            $result['report']->violations,
        );
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return string the contract of the first output slot the draft stage of the action checks, or of the first output slot when the action declares no stages
     */
    public static function contract(array $manifest): string
    {
        $draft = $manifest['action']['stages']['draft'] ?? null;

        foreach ((array) ($manifest['links'] ?? []) as $link) {
            if (($link['role'] ?? null) !== 'output' || (is_array($draft) && ! in_array($link['slot'] ?? null, $draft, true))) {
                continue;
            }

            foreach ((array) ($manifest['schemas'] ?? []) as $schema) {
                if (($schema['id'] ?? null) === ($link['schema_id'] ?? null)) {
                    return "{$schema['namespace']}/{$schema['key']}@{$schema['version']}#{$schema['sha256']}";
                }
            }
        }

        throw new RuntimeException('The manifest of this run has no output schema.');
    }
}
