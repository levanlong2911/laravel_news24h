<?php

/*
|--------------------------------------------------------------------------
| Media Jobs API Routes (new pipeline — Shadow Migration)
|--------------------------------------------------------------------------
|
| These routes serve the Visual Planning Engine pipeline.
| media_jobs = new queue (SceneGraph-based), video_jobs = legacy (untouched).
|
| Mounted at /api/media-jobs by RouteServiceProvider.
| Auth: same Sanctum bearer token as video-jobs (ability: 'video-jobs').
|
| Python workflow:
|   POST   /api/media-jobs/claim        → claim a job (lightweight)
|   GET    /api/media-jobs/{id}/graph   → get SceneGraph (built realtime)
|   PATCH  /api/media-jobs/{id}         → report completion + outputs[]
|
*/

use App\Http\Controllers\Api\MediaJobApiController;
use Illuminate\Support\Facades\Route;

Route::post('/claim',          [MediaJobApiController::class, 'claim']);
Route::get('/{id}/graph',      [MediaJobApiController::class, 'graph']);
Route::patch('/{id}',          [MediaJobApiController::class, 'complete']);
