<?php

namespace App\Providers;

use App\Models\PromptFramework;
use App\Observers\PromptFrameworkObserver;
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

        if (app()->runningInConsole()) {
            return;
        }
    }
}
