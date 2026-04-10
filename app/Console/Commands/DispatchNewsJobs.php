<?php

namespace App\Console\Commands;

use App\Jobs\ProcessKeywordJob;
use App\Models\Keyword;
use Illuminate\Console\Command;

class DispatchNewsJobs extends Command
{
    protected $signature   = 'news:dispatch {--keyword= : Chạy 1 keyword cụ thể (UUID)}';
    protected $description = 'Dispatch ProcessKeywordJob cho tất cả active keywords';

    public function handle(): int
    {
        set_time_limit(600); // 10 phút — tránh timeout khi sync queue chạy nhiều keywords

        $keywordId = $this->option('keyword');

        $query = Keyword::where('is_active', true)->orderBy('sort_order');

        if ($keywordId) {
            $query->where('id', $keywordId);
        }

        $keywords = $query->get();

        if ($keywords->isEmpty()) {
            $this->warn('Không có keyword nào đang active.');
            return self::FAILURE;
        }

        $this->info("Dispatching {$keywords->count()} keyword(s) vào queue 'articles'...");

        foreach ($keywords as $keyword) {
            ProcessKeywordJob::dispatch($keyword)->onQueue('articles');
            $this->line("  -> {$keyword->name} ({$keyword->search_keyword})");
        }

        $this->info('Done! Chạy: php artisan queue:work --queue=articles để xử lý.');

        return self::SUCCESS;
    }
}
