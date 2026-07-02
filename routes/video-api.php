<?php

/*
|--------------------------------------------------------------------------
| Video Pipeline API Routes
|--------------------------------------------------------------------------
|
| Registered separately from routes/api.php (see RouteServiceProvider) so
| these routes do NOT inherit the global 'api' middleware group's
| DomainContext middleware -- that middleware requires a tenant X-Api-Key
| (see app/Http/Middleware/DomainContext.php) for the multi-tenant /posts
| /reddit /ads endpoints, which is unrelated to and incompatible with the
| Sanctum bearer-token auth the Python video renderer uses. Mounted at
| /api/video-jobs with only ['auth:sanctum', 'throttle:api'].
|
*/

use App\Http\Controllers\Api\VideoJobApiController;
use Illuminate\Support\Facades\Route;

Route::get('/', [VideoJobApiController::class, 'index']);
Route::get('/{id}', [VideoJobApiController::class, 'show']);
Route::post('/{id}/claim', [VideoJobApiController::class, 'claim']);
Route::post('/{id}/status', [VideoJobApiController::class, 'updateStatus']);
Route::post('/{id}/assets', [VideoJobApiController::class, 'storeAssets']);
