<?php

use App\Http\Controllers\Api\ApiAdvertisementController;
use App\Http\Controllers\Api\PostApiController;
use App\Http\Controllers\Api\RedditController;
use Illuminate\Http\Request;
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
