<?php

namespace App\Services\Admin;

use App\Jobs\FetchFeedJob;
use App\Models\FeedSource;
use Illuminate\Support\Facades\Log;

class FeedAggregatorService
{
    // Dispatch tất cả sources đang active + đến hạn fetch
    public function dispatchDue(): int
    {
        $sources = FeedSource::active()->due()->get();

        foreach ($sources as $source) {
            FetchFeedJob::dispatch($source)->onQueue('feeds');
        }

        Log::info('[FeedAggregator] Dispatched due sources', ['count' => $sources->count()]);

        return $sources->count();
    }

    // Dispatch tất cả sources của 1 category (bất kể hạn)
    public function dispatchByCategory(string $categoryId): int
    {
        $sources = FeedSource::active()->where('category_id', $categoryId)->get();

        foreach ($sources as $source) {
            FetchFeedJob::dispatch($source)->onQueue('feeds');
        }

        return $sources->count();
    }

    // Dispatch 1 source cụ thể ngay lập tức
    public function dispatchOne(FeedSource $source): void
    {
        FetchFeedJob::dispatch($source)->onQueue('feeds');
    }
}
