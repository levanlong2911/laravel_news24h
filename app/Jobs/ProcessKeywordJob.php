<?php

namespace App\Jobs;

use App\Models\Keyword;
use App\Services\Admin\FetchKeywordNewsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessKeywordJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 120;

    public function __construct(public readonly Keyword $keyword) {}

    public function handle(FetchKeywordNewsService $service): void
    {
        $service->fetch($this->keyword);
    }

    public function failed(\Throwable $e): void
    {
        \Illuminate\Support\Facades\Log::error("[FetchNews] Job failed: {$this->keyword->name} — {$e->getMessage()}");
    }
}
