<?php

namespace App\Console\Commands;

use App\Services\Video\MotoDesignRevisionService;
use Illuminate\Console\Command;

class VideoDesignRevise extends Command
{
    protected $signature = 'video:design-revise
        {project : Video project id}
        {stage : Design stage id to diagnose or revise}
        {patch? : Path to the revision JSON file (reason, changes: remove_ref, replace_ref, set_text, set_surface_null); without it the run is only diagnosed}
        {--draft= : When diagnosing, write the proposed changes and the decisions left open to this JSON file}
        {--apply : Store the revised design as a new run when every check passes}
        {--expected= : With --apply, the patch hash of the approved preview}';

    protected $description = 'Diagnose a stored moto design run (every schema violation with its value, ref and text problems), or preview (with --apply store) a hand revision of its refs, its id-holding texts and the surface of BS11–BS12, checked under the contract the run pinned; no model is called';

    public function __construct(private readonly MotoDesignRevisionService $revisions)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $project = (string) $this->argument('project');
        $stage = (string) $this->argument('stage');
        $path = $this->argument('patch');

        if (! is_string($path) || $path === '') {
            return $this->diagnose($project, $stage);
        }

        $apply = (bool) $this->option('apply');
        $expected = $this->option('expected');

        if ($apply && ! is_string($expected)) {
            $this->error('--apply needs --expected=<patch hash> from the approved preview.');

            return self::FAILURE;
        }

        $file = is_file($path) ? $path : base_path($path);

        if (! is_file($file)) {
            $this->error("Revision file not found: {$file}");

            return self::FAILURE;
        }

        try {
            $patch = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->error('Revision file is not valid JSON: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! is_array($patch)) {
            $this->error('Revision file must hold a JSON object.');

            return self::FAILURE;
        }

        $report = $this->revisions->revise($project, $stage, $patch, $apply, $apply ? $expected : null, $apply ? (get_current_user() ?: null) : null);

        $this->line($apply ? 'Mode: APPLY' : 'Mode: PREVIEW (read-only, nothing written)');
        $this->newLine();
        $this->source($report['source']);

        if ($report['changes'] !== []) {
            $this->info('Changes');
            foreach ($report['changes'] as $change) {
                $this->line('  '.$change['path'].' — '.$change['reason']);
                $this->line('    before: '.$change['before']);
                $this->line('    after:  '.$change['after']);
            }
            $this->newLine();
        }

        if ($report['check'] !== null) {
            $this->line('Check: '.($report['check']['verdict'] ?? '—').' · status '.($report['check']['status'] ?? '—').' · '.($report['check']['version'] ?? '—'));
            foreach ((array) ($report['check']['issues'] ?? []) as $issue) {
                $this->line('  issue: '.$issue);
            }
            foreach ((array) ($report['check']['warnings'] ?? []) as $warning) {
                $this->line('  warning: '.$warning);
            }
            $this->line('Patch hash: '.($report['patch_hash'] ?? '—'));
            $this->newLine();
        }

        if (! in_array($report['reason'], ['preview', 'applied', 'already_applied'], true)) {
            $this->error('Stopped: '.$report['reason']);

            return self::FAILURE;
        }

        if ($report['reason'] === 'preview') {
            $this->info('Run again with --apply --expected='.$report['patch_hash'].' to store it as a new design run.');
            $this->line('Storing makes it the design the model-sheet step works from; the run it revises stays in the history.');

            return self::SUCCESS;
        }

        $this->info(($report['reason'] === 'applied' ? 'Stored' : 'Already stored')." as rev {$report['revision']} ({$report['revision_stage_id']}). It is now the design of the project; nothing was approved.");

        return self::SUCCESS;
    }

    private function diagnose(string $project, string $stage): int
    {
        $report = $this->revisions->diagnose($project, $stage);

        $this->line('Mode: DIAGNOSE (read-only, nothing written)');
        $this->newLine();
        $this->source($report['stage']);

        if ($report['check'] !== null) {
            $this->info('Stored check: '.($report['check']['verdict'] ?? '—').' · '.($report['check']['version'] ?? '—'));
            foreach ((array) ($report['check']['issues'] ?? []) as $issue) {
                $this->line('  '.$issue);
            }
            $this->newLine();
        }

        $this->info('Schema now ('.count($report['schema']).')');
        foreach ($report['schema_lines'] as $line) {
            $this->line('  '.$line);
        }
        foreach ($report['schema'] as $diagnostic) {
            foreach ($diagnostic['branches'] as $branch) {
                $this->line('    · '.$diagnostic['path'].': '.$branch, null, 'v');
            }
        }
        $this->newLine();

        $this->info('Refs of the build states ('.count($report['refs']).')');
        foreach ($report['refs'] as $problem) {
            $this->line('  ['.$problem['code'].'] '.$problem['message']);
        }
        $this->newLine();

        $this->info('Texts the model-sheet prompt takes that hold an id ('.count($report['texts']).')');
        if ($report['text_error'] !== null) {
            $this->warn('  '.$report['text_error']);
        }
        foreach ($report['texts'] as $text) {
            $this->line('  '.$text['path'].' — '.implode(', ', $text['ids']));
            $this->line('    '.$text['text']);
        }
        $this->newLine();

        $this->info('Proposed without a choice ('.count($report['proposed']).'), decisions left open ('.count($report['decisions']).')');
        foreach ([...$report['proposed'], ...$report['decisions']] as $change) {
            $this->line('  '.json_encode(array_diff_key($change, ['decide' => true]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (isset($change['decide'])) {
                $this->line('    → '.$change['decide']);
            }
        }

        $draft = $this->option('draft');

        if (is_string($draft) && $draft !== '' && $report['stage'] !== null) {
            $changes = array_map(static fn (array $change): array => array_diff_key($change, ['decide' => true]), [...$report['proposed'], ...$report['decisions']]);
            file_put_contents($draft, (string) json_encode(['reason' => '', 'changes' => $changes], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->newLine();
            $this->info("Draft written to {$draft}: fill reason, every null, then preview it with this command and the file.");
        }

        if ($report['reason'] !== null) {
            $this->newLine();
            $this->warn($report['reason']);
        }

        return $report['stage'] === null ? self::FAILURE : self::SUCCESS;
    }

    /** @param  ?array<string, mixed>  $source */
    private function source(?array $source): void
    {
        if ($source === null) {
            return;
        }

        $this->info('Design run');
        foreach ($source as $key => $value) {
            $this->line("  {$key}: ".($value ?? '—'));
        }
        $this->newLine();
    }
}
