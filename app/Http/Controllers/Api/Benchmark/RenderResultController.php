<?php

namespace App\Http\Controllers\Api\Benchmark;

use App\Http\Controllers\Controller;
use App\Http\Requests\Benchmark\StoreRenderResultRequest;
use App\Services\Benchmark\RenderResultService;
use Illuminate\Http\JsonResponse;

class RenderResultController extends Controller
{
    public function __construct(private readonly RenderResultService $service) {}

    public function store(StoreRenderResultRequest $request): JsonResponse
    {
        return response()->json($this->service->store($request->validated()), 201);
    }
}
