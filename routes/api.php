<?php

use App\Http\Controllers\Api\ApiAdvertisementController;
use App\Http\Controllers\Api\PostApiController;
use App\Http\Controllers\Api\RedditController;
use App\Http\Controllers\VideoSessionController;
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

// Video production API — Python Composer/Runner (token X-Video-Token riêng,
// bỏ DomainContext vì middleware đó đòi api_key của Domain cho mọi /api/*)
Route::withoutMiddleware([\App\Http\Middleware\DomainContext::class])
    ->middleware(['video.correlation'])
    ->group(function () {
        Route::post('/render-plans', [VideoSessionController::class, 'apiStore']);
        Route::get('/video-sessions/composing', [VideoSessionController::class, 'apiComposing']);
        Route::get('/video-shots/queued', [VideoSessionController::class, 'apiQueued']);
        Route::post('/video-shots/claim', [VideoSessionController::class, 'apiClaim']);
        Route::post('/video-shots/reclaim-expired', [VideoSessionController::class, 'apiReclaimExpired']);
        Route::patch('/video-shots/{shotId}/heartbeat', [VideoSessionController::class, 'apiHeartbeat']);
        Route::patch('/video-shots/{shotId}/result', [VideoSessionController::class, 'apiResult']);
        Route::get('/video-finals/composing', [VideoSessionController::class, 'apiFinalsComposing']);
        Route::patch('/video-finals/{finalId}/result', [VideoSessionController::class, 'apiFinalResult']);
    });
