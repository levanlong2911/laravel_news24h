<?php

namespace App\Console\Commands;

use App\Services\Video\MotoClipRenderService;
use Illuminate\Console\Command;
use RuntimeException;

class VideoMotoClipAbandon extends Command
{
    protected $signature = 'video:moto-clip-abandon {render : ID of the attempt} {note : What the check on the provider side found}';

    protected $description = 'Close a clip attempt of the shot flow whose outcome is unknown and has no known job, after a person checked the provider. A shot stays blocked until then, so that no click pays twice.';

    public function handle(MotoClipRenderService $renders): int
    {
        try {
            $render = $renders->abandon((string) $this->argument('render'), (string) $this->argument('note'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Đã đóng lượt '.$render->id.'.');

        return self::SUCCESS;
    }
}
