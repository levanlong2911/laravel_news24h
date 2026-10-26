<?php

namespace App\Console\Commands;

use App\Video\Superyacht\Spec\ReferenceOnlyRelease;
use Illuminate\Console\Command;
use Throwable;

final class VideoReferenceOnlyRelease extends Command
{
    protected $signature = 'video:reference-only-release {--apply : Apply the reviewed plan} {--expected-plan= : Exact plan_hash from the preview}';

    protected $description = 'Preview or publish reference-only yacht prompts while retaining approved project history';

    public function handle(ReferenceOnlyRelease $release): int
    {
        try {
            if ($this->option('apply')) {
                $expected = trim((string) $this->option('expected-plan'));
                if ($expected === '') throw new \RuntimeException('Missing --expected-plan. Run the preview first.');
                $result = $release->apply($expected);
                $this->info($result['changed'] ? "Released {$result['phases']} phases; removed {$result['jobs']} pending jobs. Backup: {$result['backup']}" : 'Already up to date; no writes.');
            } else {
                $plan = $release->plan();
                foreach ($plan['changes'] as $change) $this->line(($change['retire'] ? 'Retire ' : 'Revise ').$change['phase'].' / '.$change['category_id']);
                $this->line('Schema: '.$plan['schema']['action'].' CompiledShot@'.$plan['schema']['version']);
                $this->line('Pending retired jobs: '.count($plan['jobs']));
                $this->line('plan_hash: '.$plan['plan_hash']);
                $this->info('Preview only; no writes.');
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
