<?php

namespace App\Providers;

use App\Models\PromptFramework;
use App\Observers\PromptFrameworkObserver;
use App\Services\ImageProxyService;
use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ImageProxyService::class, fn () => new ImageProxyService(new Client));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        PromptFramework::observe(PromptFrameworkObserver::class);

        if (app()->runningInConsole()) {
            return;
        }
    }
}
