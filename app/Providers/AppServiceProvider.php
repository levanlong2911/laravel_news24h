<?php

namespace App\Providers;

use App\Models\PromptFramework;
use App\Models\VideoJob;
use App\Observers\PromptFrameworkObserver;
use App\Observers\VideoJobObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        PromptFramework::observe(PromptFrameworkObserver::class);
        VideoJob::observe(VideoJobObserver::class);

        if (app()->runningInConsole()) {
            return;
        }
    }
}
