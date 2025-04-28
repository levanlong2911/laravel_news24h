<?php

use App\Http\Controllers\Api\ApiAdvertisementController;
use App\Http\Controllers\Api\PostApiController;
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

Route::get('/posts', [PostApiController::class, 'index']);
Route::get('/posts/{slug}', [PostApiController::class, 'show']);
Route::get('/ads', [ApiAdvertisementController::class, 'index']);
