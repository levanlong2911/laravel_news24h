<?php

use App\Http\Controllers\Api\ApiAdvertisementController;
use App\Http\Controllers\Api\PostApiController;
use App\Http\Controllers\Api\RedditController;
use App\Video\Render\Controllers\RenderQaController;
use App\Video\Render\Controllers\RenderWorkerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware(['domain.api'])->group(function () {
    Route::get('/posts', [PostApiController::class, 'index']);
    Route::get('/posts/{slug}', [PostApiController::class, 'show']);

});

Route::prefix('/reddit')->group(function () {
    Route::get('/', [RedditController::class, 'index']);
    Route::get('/subreddit', [RedditController::class, 'subreddit']);
});

Route::prefix('ads')->group(function () {
    Route::get('/', [ApiAdvertisementController::class, 'index']);
    Route::get('{position}', [ApiAdvertisementController::class, 'byPosition']);
});

Route::withoutMiddleware([\App\Http\Middleware\DomainContext::class])
    ->prefix('internal/render-worker')
    ->middleware([
        'render.worker.auth',
        'throttle:render-worker',
    ])
    ->group(function (): void {
        Route::post('/claim', [RenderWorkerController::class, 'claim']);
        Route::post('/{render}/heartbeat', [RenderWorkerController::class, 'heartbeat']);
        Route::post('/{render}/checkpoint', [RenderWorkerController::class, 'checkpoint']);
        Route::post('/{render}/submitted', [RenderWorkerController::class, 'submitted']);
        Route::post('/{render}/complete', [RenderWorkerController::class, 'complete']);
        Route::post('/{render}/retry', [RenderWorkerController::class, 'retry']);
        Route::post('/{render}/ambiguous', [RenderWorkerController::class, 'ambiguous']);
        Route::post('/{render}/fail', [RenderWorkerController::class, 'fail']);
        Route::post('/{render}/recover-artifacts', [RenderWorkerController::class, 'recoverArtifacts']);
    });

Route::withoutMiddleware([\App\Http\Middleware\DomainContext::class])
    ->prefix('internal/render-qa')
    ->middleware([
        'render.worker.auth',
        'throttle:render-worker',
    ])
    ->group(function (): void {
        Route::post('/{qaRun}/complete', [RenderQaController::class, 'complete']);
    });
