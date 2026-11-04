<?php

namespace App\Jobs\Video;

use App\Video\Queue\VideoQueues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\File;

class ProbeVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $runId, public readonly string $label, public readonly int $seconds)
    {
        $this->timeout = $seconds + 60;
    }

    public static function directory(string $runId): string
    {
        return VideoQueues::stateDirectory().'/probe/'.$runId;
    }

    public function handle(): void
    {
        $started = microtime(true);
        sleep($this->seconds);
        File::ensureDirectoryExists(self::directory($this->runId));
        file_put_contents(self::directory($this->runId).'/'.$this->label.'.json', (string) json_encode([
            'label' => $this->label,
            'queue' => (string) $this->job?->getQueue(),
            'pid' => getmypid(),
            'started' => $started,
            'ended' => microtime(true),
            'memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        ]));
    }
}
