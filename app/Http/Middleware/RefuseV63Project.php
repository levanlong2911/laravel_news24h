<?php

namespace App\Http\Middleware;

use App\Enums\PlanningStageName;
use App\Services\Video\PlanningStageStore;
use App\Video\Inspiration\ClaudeInspirationAnalyst;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RefuseV63Project
{
    public const MESSAGE = 'Chưa hỗ trợ luồng v6.3 — bước này thuộc luồng v4 và không lấy thiết kế cũ để chạy tiếp.';

    public function __construct(private readonly PlanningStageStore $stages) {}

    public function handle(Request $request, Closure $next): Response
    {
        $projectId = $request->route('id');

        if (! is_string($projectId) || ! $this->isV63($projectId)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'message' => self::MESSAGE], 409);
        }

        return $request->isMethod('GET')
            ? redirect()->route('video-projects.anchor', $projectId)->with('error', self::MESSAGE)
            : back()->with('error', self::MESSAGE);
    }

    private function isV63(string $projectId): bool
    {
        [$latest] = $this->stages->latestStageForProject($projectId, PlanningStageName::INSPIRATION, [], skipOrphans: true);

        return ClaudeInspirationAnalyst::isPhaseOneRun($latest);
    }
}
