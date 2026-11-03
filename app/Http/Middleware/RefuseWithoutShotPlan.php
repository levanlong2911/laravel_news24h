<?php

namespace App\Http\Middleware;

use App\Services\Video\SceneSourceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RefuseWithoutShotPlan
{
    public function __construct(private readonly SceneSourceService $scenes) {}

    public function handle(Request $request, Closure $next): Response
    {
        $projectId = $request->route('id');
        $problem = is_string($projectId) ? $this->scenes->shotPlanProblem($projectId) : null;

        if ($problem === null) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'message' => $problem], 409);
        }

        return $request->isMethod('GET')
            ? redirect()->route('video-projects.scene', $projectId)->with('error', $problem)
            : back()->with('error', $problem);
    }
}
