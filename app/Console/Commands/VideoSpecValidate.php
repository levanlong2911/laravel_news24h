<?php

namespace App\Console\Commands;

use App\Video\Superyacht\Validation\DocumentValidator;
use App\Video\Superyacht\Validation\Invariants\ArrayInvariantContext;
use Illuminate\Console\Command;

class VideoSpecValidate extends Command
{
    protected $signature = 'video:spec-validate
        {schema : CanonicalDesign | BuildState | Constraint | ShotSpec | CompiledShot | LockRecord | ReviewDecision | RenderRequest}
        {file : JSON document to check}
        {--context= : Check invariants against a context JSON file (see resources/ai/superyacht/schemas/fixtures/context.json)}';

    protected $description = 'Kiem mot tai lieu JSON cua pipeline v6.3 theo JSON Schema va rang buoc cheo truong';

    public function handle(DocumentValidator $validator): int
    {
        $file = (string) $this->argument('file');

        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $json = (string) file_get_contents($file);
        $contextFile = $this->option('context');
        $ctx = is_string($contextFile)
            ? new ArrayInvariantContext(json_decode((string) file_get_contents($contextFile), true, 512, JSON_THROW_ON_ERROR))
            : null;

        $schema = (string) $this->argument('schema');
        $report = $ctx === null ? $validator->schemas()->validate($schema, $json) : $validator->validate($schema, $json, $ctx);

        if ($report->passes()) {
            $this->info($ctx === null ? 'Schema OK (rang buoc chua kiem: truyen --context).' : 'Schema va rang buoc OK.');

            return self::SUCCESS;
        }

        foreach ($report->messages() as $message) {
            $this->line("  <fg=red>x</> {$message}");
        }

        $this->error(count($report->violations).' loi: '.implode(', ', $report->codes()));

        return self::FAILURE;
    }
}
