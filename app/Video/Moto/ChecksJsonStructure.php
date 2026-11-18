<?php

declare(strict_types=1);

namespace App\Video\Moto;

use App\Video\Script\StructureChecked;
use App\Video\Superyacht\Spec\ResolvedPhase;

/**
 * The schema check of a moto workflow (CheckedWorkflow::schemaViolations): the answer against the
 * structure of the schemas its run pinned (MotoContract::technicalSchemas), so every phase that reads
 * an AI answer holds it to the same rule. A workflow whose answer is held to some of its pinned
 * schemas only names them in answerSchemas().
 */
trait ChecksJsonStructure
{
    /** @return list<array{code: string, path: string, message: string}> */
    public function schemaViolations(object $decoded, ResolvedPhase $bundle, string $projectId): array
    {
        $schemas = $this->answerSchemas($bundle);

        try {
            $errors = MotoContract::structureErrors($decoded, $schemas);
        } catch (\Throwable $e) {
            $fault = MotoContract::structureFault($e, $schemas);

            if ($fault === null) {
                throw $e;
            }

            // A schema the check cannot build or run is a fault of the check: the answer neither passes nor is blamed for it (ScriptEvaluation leaves it unscored).
            return [['code' => StructureChecked::CONFIG_FAULT, 'path' => '', 'message' => $fault]];
        }

        return array_map(static fn (string $error): array => ['code' => 'SCHEMA', 'path' => '', 'message' => $error], $errors);
    }

    /** The version of the check, recorded with each check result (StructureChecked). */
    public function structurePolicy(): string
    {
        return MotoContract::TECHNICAL_SCHEMA_VERSION;
    }

    /** Every moto phase sorts its findings by the one table, MotoCheckRules (StructureChecked). */
    public function sortFindings(array $check): array
    {
        return MotoCheckRules::apply($check);
    }

    /** @return list<array{json_schema_id: string, content: string, label?: string}> the pinned schemas the answer is held to, its own first */
    protected function answerSchemas(ResolvedPhase $bundle): array
    {
        return $bundle->outputSchemas();
    }
}
