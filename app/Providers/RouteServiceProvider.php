<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(2000)->by($request->user()?->id ?: $request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            // Deliberately bypasses the global 'api' group's DomainContext
            // middleware (tenant X-Api-Key check) -- see routes/video-api.php
            // for why. Sanctum is the only auth this group needs.
            Route::middleware(['auth:sanctum', 'throttle:api'])
                ->prefix('api/video-jobs')
                ->group(base_path('routes/video-api.php'));

            // New pipeline (Shadow Migration): media_jobs = SceneGraph-based queue.
            // Same Sanctum auth as video-jobs; separate prefix for clean ABI.
            Route::middleware(['auth:sanctum', 'throttle:api'])
                ->prefix('api/media-jobs')
                ->group(base_path('routes/media-jobs-api.php'));

            // L12 analytics ingestion (n8n/Make webhook, same Sanctum auth)
            Route::middleware(['auth:sanctum', 'throttle:api'])
                ->prefix('api/video-analytics')
                ->group(function () {
                    Route::post('/', [\App\Http\Controllers\Api\VideoAnalyticsController::class, 'store']);
                    Route::get('/top-articles', [\App\Http\Controllers\Api\VideoAnalyticsController::class, 'topArticles']);
                });

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });

        $this->configureRateLimiting();
    }

    // Rate limiting cho đăng nhập
    protected function configureRateLimiting()
    {
        RateLimiter::for('login', function (Request $request) {
            $email = (string) $request->email;
            return Limit::perMinute(5)->by($email . '|' . $request->ip());
        });
    }
}
