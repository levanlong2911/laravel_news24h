<?php

namespace App\Console\Commands;

use App\Video\Superyacht\Import\SpecClone;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class VideoSpecClone extends Command
{
    protected $signature = 'video:spec-clone
        {--from= : Slug of the topic whose current phases are copied}
        {--to= : Slug of the new topic (no phases yet)}
        {--apply : Apply the reviewed plan}
        {--expected-plan= : Exact plan_hash from the preview}';

    protected $description = 'Create the phase set of a new topic by copying the current phases of an existing topic, inside the database only';

    public function handle(): int
    {
        $from = trim((string) $this->option('from'));
        $to = trim((string) $this->option('to'));
        $clone = SpecClone::fromConfig((array) config('video.superyacht'));

        try {
            if ($this->option('apply')) {
                $expected = trim((string) $this->option('expected-plan'));

                if ($expected === '') {
                    throw new RuntimeException('Missing --expected-plan. Run the preview first.');
                }

                $result = $clone->apply($from, $to, $expected);
                $this->info("Copied {$result['phases']} phases and {$result['links']} schema links from {$from} to {$to}.");

                return self::SUCCESS;
            }

            $plan = $clone->plan($from, $to);
            $this->line("Workflow {$plan['workflow']} · {$plan['from']['slug']} → {$plan['to']['slug']}");
            $this->table(['phase_code', 'source rev', 'sha256', 'links'], array_map(static fn (array $row): array => [
                $row['phase'], $row['revision'], substr((string) $row['sha256'], 0, 12), count($row['links']),
            ], $plan['phases']));

            foreach ($plan['warnings'] as $warning) {
                $this->warn($warning);
            }

            $this->line('plan_hash: '.$plan['plan_hash']);
            $this->info('Preview only; no writes.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
