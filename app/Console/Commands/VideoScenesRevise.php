<?php

namespace App\Console\Commands;

use App\Services\Video\SceneWriterService;
use Illuminate\Console\Command;

class VideoScenesRevise extends Command
{
    protected $signature = 'video:scenes-revise
        {project : Video project id}
        {stage : Scene plan stage id the revision is written against}
        {patch? : Path to the revision JSON file (reason; scenes by id with work, continuity_start, continuity_end or assumptions; remove_conflicts and remove_flags as lists of indexes into data.conflicts and flags)}
        {--apply : Store the revised scene plan as a new run when every check passes; with --select, choose the stored run}
        {--expected= : With --apply, the patch hash of the approved preview}
        {--select : No patch: check the stored run again as the page does and, with --apply, choose it by rule pass when it passes with status ok}';

    protected $description = 'Preview (or with --apply store) a hand revision of a moto scene plan, checked against the current contract and sources; no model is called, and a stored revision takes the selection by rule pass like any run. With --select, choose a stored run that now passes, unchanged';

    public function __construct(private readonly SceneWriterService $writer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $expected = $this->option('expected');

        if ($this->option('select')) {
            if ($this->argument('patch') !== null) {
                $this->error('--select takes no patch: it chooses the stored run as it is.');

                return self::FAILURE;
            }

            $report = $this->writer->selectPassed((string) $this->argument('project'), (string) $this->argument('stage'), $apply);
            $this->line($apply ? 'Mode: SELECT' : 'Mode: PREVIEW (read-only, nothing written)');

            if ($report['check'] !== null) {
                $this->line('Check now: '.$report['check']['verdict'].' · status '.($report['check']['status'] ?? '—').' · '.$report['check']['version']);

                foreach ([...$report['check']['schema'], ...$report['check']['issues']] as $issue) {
                    $this->line('  issue: '.$issue);
                }
            }

            $this->line('Selected scene plan: '.($report['selection_stage_id'] ?? '—'));

            if ($report['selected']) {
                $this->info('This run is the selected scene plan.');

                return self::SUCCESS;
            }

            if ($report['reason'] === 'preview') {
                $this->info('This run passes with status ok. Run again with --select --apply to choose it.');

                return self::SUCCESS;
            }

            $this->error('Stopped: '.$report['reason']);

            return self::FAILURE;
        }

        if ($this->argument('patch') === null) {
            $this->error('Give a patch file, or --select to choose the stored run as it is.');

            return self::FAILURE;
        }

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
            $this->info('Source scene plan');
            foreach ($report['source'] as $key => $value) {
                $this->line("  {$key}: ".(is_bool($value) ? ($value ? 'yes' : 'no') : $value));
            }
            $this->line('  contract: '.($report['prompt'] ?? '—'));
            $this->newLine();
        }

        if ($report['changes'] !== []) {
            $this->info('Changes');
            foreach ($report['changes'] as $change) {
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
            if ($report['revision'] !== null) {
                $this->line("Already stored as rev {$report['revision']} ({$report['revision_stage_id']}); applying it again stores nothing.");
            }

            $this->info('Run again with --apply --expected='.$report['patch_hash'].' to store it as a new run. A revision that passes takes the selection by rule pass.');

            return self::SUCCESS;
        }

        $stored = ($report['reason'] === 'applied' ? 'Stored' : 'Already stored')." as rev {$report['revision']} ({$report['revision_stage_id']})";

        if (! $report['approved']) {
            $this->error("{$stored}, but the selection does not point at it: {$report['approval']}");

            return self::FAILURE;
        }

        $this->info("{$stored}. Approved by rule pass; the selection points at it.");

        return self::SUCCESS;
    }
}
