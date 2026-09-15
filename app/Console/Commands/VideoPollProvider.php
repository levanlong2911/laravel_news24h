<?php

namespace App\Console\Commands;

use App\Video\Render\Video\VideoRenderExecutionService;
use Illuminate\Console\Command;

class VideoPollProvider extends Command
{
    protected $signature = 'video:poll-provider {render : ID cua video render dang chay}';

    protected $description = 'Hoi provider MOT lan ve mot video render dang chay';

    public function handle(VideoRenderExecutionService $execution): int
    {
        [$ok, $reason] = $execution->poll((string) $this->argument('render'));

        $ok ? $this->info($reason) : $this->warn($reason);

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
