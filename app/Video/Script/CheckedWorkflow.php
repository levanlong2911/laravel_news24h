<?php

declare(strict_types=1);

namespace App\Video\Script;

interface CheckedWorkflow
{
    /**
     * @param  ?array<string, mixed>  $response
     * @param  array<string, mixed>  $context
     * @param  list<array{code: string, path: string, message: string}>  $schema
     * @return array{version: string, format: string, verdict: string, status: ?string, error: ?string, schema: list<string>, issues: list<string>, warnings: list<string>, judge: string}
     */
    public function check(?array $response, array $context, array $schema): array;

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function derive(array $document, array $context): array;
}
