<?php

namespace App\Console\Commands;

use App\Services\Video\VesselDesignService;
use Illuminate\Console\Command;

class VideoCorrectDesign extends Command
{
    protected $signature = 'video:correct-design
        {stageId : Vessel design stage id the correction was written against}
        {correction : Path to the correction JSON file}
        {--apply : Record the corrected design revision when every check passes}
        {--select : With --apply, select the corrected revision as the current design}';

    protected $description = 'Preview (or with --apply record) a path-checked correction of a vessel design revision';

    public function __construct(private readonly VesselDesignService $designs)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $select = (bool) $this->option('select');

        if ($select && ! $apply) {
            $this->error('--select needs --apply.');

            return self::FAILURE;
        }

        $path = (string) $this->argument('correction');
        $path = is_file($path) ? $path : base_path($path);

        if (! is_file($path)) {
            $this->error("Correction file not found: {$path}");

            return self::FAILURE;
        }

        try {
            $correction = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->error('Correction file is not valid JSON: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! is_array($correction)) {
            $this->error('Correction file must hold a JSON object.');

            return self::FAILURE;
        }

        $report = $this->designs->correctDesign(
            (string) $this->argument('stageId'), $correction, $apply, $apply ? (get_current_user() ?: null) : null,
        );

        $this->line($apply ? 'Mode: APPLY' : 'Mode: PREVIEW (read-only, nothing written)');
        $this->newLine();

        if ($report['stage'] !== null) {
            $this->info('Source design');
            foreach ($report['stage'] as $key => $value) {
                $this->line("  {$key}: {$value}");
            }
            $this->newLine();
        }

        if ($report['changes'] !== []) {
            $this->info('Changes (patch '.$report['patch_hash'].')');
            foreach ($report['changes'] as $change) {
                $this->line('  '.$change['path']);
                $this->line('    before: '.$change['before']);
                $this->line('    after:  '.$change['after']);
            }
            $this->newLine();
        }

        if ($report['missing_decisions'] !== []) {
            $this->warn('Missing design decisions (recorded as unresolved; the anchor prompt stays blocked until they are decided)');
            foreach ($report['missing_decisions'] as $decision) {
                $this->line("  {$decision['id']}: {$decision['question']}");
            }
            $this->newLine();
        }

        if ($report['validation_source'] !== null) {
            $source = $report['validation_source'];
            $this->line('Excluded-context source: '.($source['inspiration_stage_id'] ?? 'none').' ('.$source['basis'].', '.count($source['excluded']).' names)');

            if ($source['basis'] === 'created_before_source') {
                $this->warn('  The source design did not record its inspiration; this is the latest inspiration created before it, not a recorded snapshot.');
            }
        }

        if ($report['gate'] !== null) {
            $passed = count(array_filter($report['gate'], static fn (array $found): bool => $found === []));
            $this->line('Completeness gate: '.$passed.'/'.count($report['gate']));
        }

        foreach ($report['violations'] as $violation) {
            $this->line('  violation: '.$violation);
        }

        if (! $report['passed']) {
            $this->error('Stopped: '.$report['reason']);

            return self::FAILURE;
        }

        $this->info('Structural validation: passed (design checks, completeness gate and extract integrity).');
        $this->line('Open decisions: '.count($report['missing_decisions']));

        if ($report['anchor_blockers'] === []) {
            $this->info('Ready for anchor prompt or render: yes');
        } else {
            $this->warn('Ready for anchor prompt or render: no ('.implode(', ', $report['anchor_blockers']).')');
        }

        if (! $apply) {
            $this->info('Run again with --apply to record the corrected revision.');

            return self::SUCCESS;
        }

        if (! in_array($report['reason'], ['applied', 'already_applied'], true) || $report['revision_stage_id'] === null) {
            $this->error('Not recorded: '.$report['reason']);

            return self::FAILURE;
        }

        $this->info(($report['reason'] === 'applied' ? 'Recorded' : 'Already recorded').
            " revision {$report['revision']} ({$report['revision_stage_id']}). Production lock unchanged.");

        if (! $select) {
            $this->line('Not selected. Run again with --apply --select to make it the current design.');

            return self::SUCCESS;
        }

        [$selected, $reason] = $this->designs->selectCorrection($report['revision_stage_id']);

        if (! $selected) {
            $this->error('Not selected: '.$reason);

            return self::FAILURE;
        }

        $this->info("Selected as the current design ({$reason}). The approved anchor lock still points at its own design.");

        return self::SUCCESS;
    }
}
