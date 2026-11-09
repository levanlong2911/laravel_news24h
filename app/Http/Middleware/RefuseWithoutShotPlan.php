<?php

namespace App\Http\Middleware;

use App\Services\Video\SceneSourceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RefuseWithoutShotPlan
{
    public function __construct(private readonly SceneSourceService $scenes) {}

    /** @param  ?string  $scope  'clips' for a step the approved shot plan opens */
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $projectId = $request->route('id');
        $problem = is_string($projectId) ? $this->scenes->shotPlanProblem($projectId, $scope) : null;

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
