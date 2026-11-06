<?php

namespace App\Console\Commands;

use App\Services\Video\ScriptWriterService;
use Illuminate\Console\Command;

class VideoScriptRevise extends Command
{
    protected $signature = 'video:script-revise
        {project : Video project id}
        {stage : Script stage id the revision is written against}
        {patch : Path to the revision JSON file (reason, beats by id with purpose, visual, location, on_screen, design_refs, in_use, configurations, situation, link or sfx)}
        {--apply : Store the revised script as a new run when every check passes}
        {--expected= : With --apply, the patch hash of the approved preview}';

    protected $description = 'Preview (or with --apply store) a hand revision of a moto script written from the sources the project uses now, checked against the current contract; no model is called and nothing is approved';

    public function __construct(private readonly ScriptWriterService $writer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $expected = $this->option('expected');

        if ($apply && ! is_string($expected)) {
            $this->error('--apply needs --expected=<patch hash> from the approved preview.');

            return self::FAILURE;
        }

        $path = (string) $this->argument('patch');
        $path = is_file($path) ? $path : base_path($path);

        if (! is_file($path)) {
            $this->error("Revision file not found: {$path}");

            return self::FAILURE;
        }

        try {
            $patch = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->error('Revision file is not valid JSON: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! is_array($patch)) {
            $this->error('Revision file must hold a JSON object.');

            return self::FAILURE;
        }

        $report = $this->writer->revise((string) $this->argument('project'), (string) $this->argument('stage'), $patch, $apply, $apply ? $expected : null, $apply ? (get_current_user() ?: null) : null);

        $this->line($apply ? 'Mode: APPLY' : 'Mode: PREVIEW (read-only, nothing written)');
        $this->newLine();

        if ($report['source'] !== null) {
            $this->info('Source script');
            foreach ($report['source'] as $key => $value) {
                $this->line("  {$key}: ".(is_bool($value) ? ($value ? 'yes' : 'no') : $value));
            }
            $this->line('  contract: '.($report['prompt'] ?? '—'));
            $this->newLine();
        }

        if ($report['changes'] !== []) {
            $this->info('Changes');
            $beat = null;
            foreach ($report['changes'] as $change) {
                if ($beat !== strtok($change['path'], '.')) {
                    $beat = strtok($change['path'], '.');
                    $this->line("  {$beat} purpose: ".$change['purpose']);
                    $this->line("  {$beat} visual:  ".$change['visual']);
                }
                $this->line('  '.$change['path']);
                $this->line('    before: '.$change['before']);
                $this->line('    after:  '.$change['after']);
            }
            $this->newLine();
        }

        if ($report['check'] !== null) {
            $this->line('Check: '.$report['check']['verdict'].' · status '.($report['check']['status'] ?? '—').' · '.$report['check']['version']);
            foreach ([...$report['check']['schema'], ...$report['check']['issues']] as $issue) {
                $this->line('  issue: '.$issue);
            }
            foreach ($report['check']['warnings'] ?? [] as $warning) {
                $this->line('  warning: '.$warning);
            }
            $this->line('Content hash: '.($report['content_hash'] ?? '—'));
            $this->line('Patch hash: '.($report['patch_hash'] ?? '—'));
            $this->newLine();
        }

        if (! in_array($report['reason'], ['preview', 'applied', 'already_applied'], true)) {
            $this->error('Stopped: '.$report['reason']);

            return self::FAILURE;
        }

        if ($report['reason'] === 'preview') {
            $this->info('Run again with --apply --expected='.$report['patch_hash'].' to store it as a new run.');

            return self::SUCCESS;
        }

        $this->info(($report['reason'] === 'applied' ? 'Stored' : 'Already stored')." as rev {$report['revision']} ({$report['revision_stage_id']}). Not approved — approve it on the script panel.");

        return self::SUCCESS;
    }
}
