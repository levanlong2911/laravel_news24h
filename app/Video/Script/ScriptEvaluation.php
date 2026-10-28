<?php

declare(strict_types=1);

namespace App\Video\Script;

use App\Video\Llm\JsonAnswer;
use App\Video\Superyacht\Spec\PhaseResolver;
use RuntimeException;

final class ScriptEvaluation
{
    /**
     * @param  array<string, mixed>  $manifest  the bundle the run was sent with
     * @param  array<string, mixed>  $context  the check context pinned with the run
     * @param  array<string, mixed>  $source  the pinned source of the run
     * @return array{document: ?array<string, mixed>, parse_error: ?string, check: array<string, mixed>, derived: ?array<string, mixed>, contract: string, content_hash: ?string}
     */
    public static function of(string $raw, array $manifest, array $context, array $source, ScriptWorkflow $adapter): array
    {
        [$decoded, $parseError] = JsonAnswer::parse($raw);
        $document = $parseError === null ? json_decode((string) json_encode($decoded), true) : null;
        $document = is_array($document) && ! array_is_list($document) ? $document : null;
        $contract = self::contract($manifest);
        $schema = [];

        if ($document !== null) {
            $result = PhaseResolver::fromConfig((array) config('video.superyacht'))
                ->fromManifest($manifest)
                ->checkOutput((string) json_encode($decoded), (string) $context['project_id'], 'draft');

            foreach ($result['report']->violations as $violation) {
                $schema[] = ['code' => $violation->code, 'path' => (string) $violation->path, 'message' => $violation->message];
            }
        }

        $check = $adapter->check($document, $context, $schema);
        $derived = $document !== null && $check['schema'] === [] && $check['status'] !== 'fail' && $check['verdict'] !== 'UNSCORED'
            ? $adapter->derive($document)
            : null;

        return [
            'document' => $document,
            'parse_error' => $document === null ? ($parseError ?? 'The answer is not a JSON object.') : null,
            'check' => $check,
            'derived' => $derived,
            'contract' => $contract,
            'content_hash' => $document === null ? null : ScriptContentHash::of($contract, $document, $source),
        ];
    }

    /** @param array<string, mixed> $manifest */
    public static function contract(array $manifest): string
    {
        foreach ((array) ($manifest['links'] ?? []) as $link) {
            if (($link['role'] ?? null) !== 'output') {
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
