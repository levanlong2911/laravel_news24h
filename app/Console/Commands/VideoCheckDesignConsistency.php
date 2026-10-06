<?php

namespace App\Console\Commands;

use App\Services\Video\VesselDesignService;
use Illuminate\Console\Command;

class VideoCheckDesignConsistency extends Command
{
    protected $signature = 'video:check-design-consistency
        {stageId : Vessel design stage id to check}';

    protected $description = 'Read-only check of a vessel design revision: passed, failed or needs_review, with paths and reasons';

    public function __construct(private readonly VesselDesignService $designs)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $report = $this->designs->checkConsistency((string) $this->argument('stageId'));

        if ($report['stage'] !== null) {
            foreach ($report['stage'] as $key => $value) {
                $this->line("{$key}: {$value}");
            }
        }

        $this->line('geometry_model: '.($report['geometry_model'] ?? '—'));

        foreach ($report['notes'] as $note) {
            $this->warn('note: '.$note);
        }

        foreach ($report['violations'] as $violation) {
            $this->line('violation: '.$violation);
        }

        $this->newLine();
        $this->line('result: '.$report['result']);

        return $report['result'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
