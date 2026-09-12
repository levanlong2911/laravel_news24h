<?php

declare(strict_types=1);

namespace App\Video\Render\Controllers;

use App\Models\RenderQaRun;
use App\Video\Render\Qa\QaCheckpointService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RenderQaController
{
    public function complete(Request $request, RenderQaRun $qaRun, QaCheckpointService $service): JsonResponse
    {
        $payload = $request->validate([
            'qa_report_hash' => ['required', 'string', 'size:64'],
            'render_id' => ['required', 'uuid'],
            'request_hash' => ['required', 'string', 'size:64'],
            'artifact_hash' => ['required', 'string', 'size:64'],
            'decision' => ['required', 'string', 'in:pass,fail,review'],
            'repair_decision' => ['required', 'string', 'in:no_repair,targeted_repair,full_rerender,human_review'],
            'vision_provider' => ['required', 'string', 'max:80'],
            'vision_model' => ['required', 'string', 'max:190'],
            'report' => ['required', 'array'],
            'usage' => ['sometimes', 'array'],
        ]);

        if ($payload['render_id'] !== $qaRun->render_id) {
            abort(409, 'QA render id mismatch.');
        }

        $completed = $service->complete($qaRun, $payload);

        return response()->json([
            'ok' => true,
            'qa_run_id' => $completed->id,
            'status' => $completed->status->value,
            'decision' => $completed->decision?->value,
            'repair_decision' => $completed->repair_decision,
        ]);
    }
}
