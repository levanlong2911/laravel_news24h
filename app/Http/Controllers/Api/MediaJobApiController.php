<?php

namespace App\Http\Controllers\Api;

use App\DTOs\PipelineContext;
use App\DTOs\SceneDTO;
use App\Http\Controllers\Controller;
use App\Models\MediaJob;
use App\Models\PipelineRun;
use App\Models\VideoProject;
use App\Services\AI\SceneGraph\GraphAssembler;
use App\Services\AI\SceneGraph\SceneGraphCompiler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sanctum-protected API for Python MediaFactoryWorker.
 *
 * Contract (Shadow Migration pattern):
 *   media_jobs = new pipeline queue (SceneGraph-based)
 *   video_jobs = legacy pipeline (script-based), untouched
 *
 * Token must carry the 'video-jobs' ability (reused — same Sanctum token
 * works for both pipelines; add 'media-jobs' ability when you want separation).
 *
 * API design:
 *   POST /api/media-jobs/claim        → lightweight claim (no SceneGraph payload)
 *   GET  /api/media-jobs/{id}/graph   → SceneGraph built realtime
 *   PATCH /api/media-jobs/{id}        → completion report with outputs[]
 */
class MediaJobApiController extends Controller
{
    public function __construct(
        private readonly SceneGraphCompiler $compiler,
    ) {}

    private function authorizeAbility(Request $request): void
    {
        abort_unless($request->user()?->tokenCan('video-jobs'), 403, 'Token missing video-jobs ability');
    }

    // -------------------------------------------------------------------------
    // POST /api/media-jobs/claim
    // -------------------------------------------------------------------------

    /**
     * Claim the oldest pending media job.
     *
     * Returns lightweight header — no SceneGraph.
     * Python ACKs then calls GET /graph to receive the full artifact.
     *
     * Response 200: {job_id, job_type, workflow_version, graph_version}
     * Response 204: nothing to claim
     * Response 409: race — already claimed
     */
    public function claim(Request $request): JsonResponse
    {
        $this->authorizeAbility($request);

        $workerId = $request->input('worker_id', $request->ip());

        $claimed = DB::transaction(function () use ($workerId) {
            $job = MediaJob::claimable()->lockForUpdate()->first();

            if ($job === null) {
                return null;
            }

            $job->update([
                'status'     => 'claimed',
                'attempt'    => $job->attempt + 1,
                'worker_id'  => $workerId,
                'claimed_at' => now(),
            ]);

            return $job;
        });

        if ($claimed === null) {
            return response()->noContent();  // RFC 7230 §3.3 — 204 must not carry a body
        }

        return response()->json([
            'job_id'           => $claimed->id,
            'job_type'         => $claimed->job_type,
            'workflow_version' => $claimed->workflow_version,
            'graph_version'    => GraphAssembler::GRAPH_VERSION,
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/media-jobs/{id}/graph
    // -------------------------------------------------------------------------

    /**
     * Build and return the SceneGraph for a claimed job.
     *
     * SceneGraph is compiled realtime — never stored in media_jobs.
     * Source: pipeline_runs.output_json (stage=scene_shot) for this project.
     *
     * Response 200: full SceneGraph (matches contracts/v1/SceneGraph.schema.json)
     * Response 422: SceneDTO deserialization or compiler validation failure
     */
    public function graph(Request $request, string $id): JsonResponse
    {
        $this->authorizeAbility($request);

        $job = MediaJob::findOrFail($id);
        abort_unless(
            in_array($job->status, ['claimed', 'rendering'], true),
            409,
            "Job {$id} is not in a claimable state (status: {$job->status})"
        );

        $project = VideoProject::findOrFail($job->project_id);

        // Load the latest completed scene_shot pipeline run for this project
        $pipelineRun = PipelineRun::where('project_id', $project->id)
            ->where('stage', 'scene_shot')
            ->where('status', 'completed')
            ->latest('finished_at')
            ->firstOrFail();

        $rawScenes = $pipelineRun->output_json['scenes'] ?? [];
        if (empty($rawScenes)) {
            abort(422, "No scene_shot output found for project {$project->id}");
        }

        $scenes = array_map(fn (array $s) => SceneDTO::fromArray($s), $rawScenes);

        $pipeline = new PipelineContext(
            projectId:      (string) $project->id,
            workflowVersion: $job->workflow_version,
            plannerVersion:  $job->planner_version,
            compilerVersion: $job->compiler_version,
            contractVersion: $job->contract_version,
        );

        try {
            $sceneGraph = $this->compiler->compile($scenes, $project, $pipeline);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Advance status to 'rendering' so the graph endpoint can't be called twice
        $job->update(['status' => 'rendering']);

        return response()->json($sceneGraph);
    }

    // -------------------------------------------------------------------------
    // PATCH /api/media-jobs/{id}
    // -------------------------------------------------------------------------

    /**
     * Receive completion report from Python MediaFactoryWorker.
     *
     * Python reports: final status, all output artifacts, actual cost, metrics.
     * Laravel stores them and advances the VideoProject status accordingly.
     *
     * Request body:
     *   {
     *     "status":    "completed" | "failed",
     *     "outputs":   [{"type":"video","url":"...","size_bytes":...}, ...],
     *     "cost":      0.23,       // actual USD spent by Python (not estimated)
     *     "render_ms": 45000,
     *     "metrics":   {},         // free-form: ffprobe stats, quality scores, etc.
     *     "error_message": "..."   // required when status=failed
     *   }
     *
     * Response 200: {job_id, status, completed_at}
     */
    public function complete(Request $request, string $id): JsonResponse
    {
        $this->authorizeAbility($request);

        $data = $request->validate([
            'status'            => 'required|in:completed,failed,cancelled',
            'outputs'           => 'nullable|array',
            'outputs.*.type'    => 'required_with:outputs|string',
            'outputs.*.url'     => 'required_with:outputs|string',
            'outputs.*.size_bytes' => 'nullable|integer',
            'cost'              => 'nullable|numeric|min:0',
            'render_ms'         => 'nullable|integer|min:0',
            'metrics'           => 'nullable|array',
            'error_message'     => 'nullable|string',
        ]);

        $job = MediaJob::findOrFail($id);
        abort_unless($job->status === 'rendering', 409, "Job {$id} is not rendering (status: {$job->status})");

        $isTerminal = in_array($data['status'], ['completed', 'failed', 'cancelled'], true);

        $job->update(array_filter([
            'status'        => $data['status'],
            'outputs'       => $data['outputs']       ?? null,
            'cost_usd'      => $data['cost']          ?? null,
            'render_ms'     => $data['render_ms']     ?? null,
            'error_message' => $data['error_message'] ?? null,
            'completed_at'  => $isTerminal ? now() : null,
        ], fn ($v) => $v !== null));

        // Advance VideoProject status
        $projectStatus = match ($data['status']) {
            'completed' => 'complete',
            'failed'    => 'failed',
            default     => null,
        };

        if ($projectStatus !== null) {
            VideoProject::where('id', $job->project_id)
                ->update(['status' => $projectStatus]);
        }

        return response()->json([
            'job_id'       => $job->id,
            'status'       => $job->status,
            'completed_at' => $job->completed_at?->toISOString(),
        ]);
    }
}
