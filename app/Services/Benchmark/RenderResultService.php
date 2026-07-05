<?php

namespace App\Services\Benchmark;

use App\Models\Benchmark\BmFixture;
use App\Models\Benchmark\BmInstruction;
use App\Models\Benchmark\BmInstructionInstance;
use App\Models\Benchmark\BmPlanner;
use App\Models\Benchmark\BmPlannerOutput;
use App\Models\Benchmark\BmRender;
use App\Models\Benchmark\BmRenderPlanner;
use App\Models\Benchmark\BmRenderScore;
use App\Models\Benchmark\BmSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class RenderResultService
{
    /**
     * Persist a completed render result.
     *
     * Idempotent — safe to retry on network timeout / queue retry.
     */
    public function store(array $data): array
    {
        $existing = BmRender::where('uuid', $data['render_uuid'])->first();
        if ($existing) {
            return $this->buildResponse($existing, alreadyExisted: true);
        }

        return DB::transaction(function () use ($data) {
            $session = BmSession::where('code', $data['session_code'])->firstOrFail();
            $fixture = BmFixture::where('slug', $data['fixture_slug'])->firstOrFail();

            // Preload all lookup maps in two queries
            $plannerMap        = BmPlanner::pluck('id', 'name');          // name → id
            $catalogRows       = BmInstruction::select(['id', 'code', 'planner_id'])->get();
            $catalogMap        = $catalogRows->pluck('id', 'code');        // code → catalog id
            $catalogPlannerMap = $catalogRows->pluck('planner_id', 'code'); // code → planner id

            $render = BmRender::create([
                'uuid'             => $data['render_uuid'],
                'session_id'       => $session->id,
                'fixture_id'       => $fixture->id,
                'model'            => $data['model'],
                'resolution'       => $data['resolution'] ?? '1080p',
                'duration_seconds' => $data['duration_seconds'],
                'fps'              => $data['fps'] ?? 24,
                'seed'             => $data['seed'] ?? null,
                'char_count'       => $data['char_count'],
                'prompt_version'   => $data['prompt_version'],
                'artifact_path'    => $data['artifact_path'],
                'git_commit'       => $session->git_commit, // render-level provenance
                'rendered_at'      => $data['rendered_at'] ?? now(),
            ]);

            BmRenderScore::create(['render_id' => $render->id]);

            // Snapshot only planners actually referenced in this render
            $usedPlannerIds = $this->collectUsedPlannerIds($data, $plannerMap, $catalogPlannerMap);
            $this->snapshotPlannerFingerprints($render->id, $usedPlannerIds);

            $this->insertPlannerOutputs($render->id, $data['planner_outputs'] ?? [], $plannerMap);
            $instCount = $this->insertInstructionInstances($render->id, $data['instructions'] ?? [], $catalogMap);

            return $this->buildResponse($render, alreadyExisted: false, instCount: $instCount);
        });
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function collectUsedPlannerIds(array $data, Collection $plannerMap, Collection $catalogPlannerMap): array
    {
        $fromOutputs = collect($data['planner_outputs'] ?? [])
            ->pluck('planner_name')
            ->map(fn($n) => $plannerMap[$n] ?? null);

        $fromInstructions = collect($data['instructions'] ?? [])
            ->pluck('catalog_code')
            ->map(fn($c) => $catalogPlannerMap[$c] ?? null);

        return $fromOutputs->merge($fromInstructions)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function snapshotPlannerFingerprints(int $renderId, array $usedPlannerIds): void
    {
        if (empty($usedPlannerIds)) {
            return;
        }

        $planners = BmPlanner::whereIn('id', $usedPlannerIds)
            ->get(['id', 'fingerprint', 'version']);

        $rows = $planners->map(fn($p) => [
            'render_id'       => $renderId,
            'planner_id'      => $p->id,
            'fingerprint'     => $p->fingerprint,
            'planner_version' => $p->version,
        ])->all();

        BmRenderPlanner::insert($rows);
    }

    private function insertPlannerOutputs(int $renderId, array $outputs, Collection $plannerMap): void
    {
        $rows = [];
        foreach ($outputs as $po) {
            $plannerId = $plannerMap[$po['planner_name']] ?? null;
            if ($plannerId === null) {
                continue;
            }
            $rows[] = [
                'render_id'  => $renderId,
                'planner_id' => $plannerId,
                'beat'       => $po['beat'],
                'raw_text'   => $po['raw_text'],
                'created_at' => now(),
            ];
        }
        if ($rows) {
            BmPlannerOutput::insert($rows);
        }
    }

    private function insertInstructionInstances(int $renderId, array $instructions, Collection $catalogMap): int
    {
        $rows = [];
        foreach ($instructions as $inst) {
            $catalogId = $catalogMap[$inst['catalog_code']] ?? null;
            if ($catalogId === null) {
                continue;
            }
            $charLen = mb_strlen($inst['variant_text']);
            $rows[]  = [
                'render_id'            => $renderId,
                'catalog_id'           => $catalogId,
                'beat'                 => $inst['beat'],
                'variant_text'         => $inst['variant_text'],
                'char_length'          => $charLen,
                'estimated_token_cost' => BmInstructionInstance::makeTokenCost($inst['variant_text']),
                'created_at'           => now(),
            ];
        }
        if ($rows) {
            BmInstructionInstance::insert($rows);
        }
        return count($rows);
    }

    private function buildResponse(BmRender $render, bool $alreadyExisted, int $instCount = 0): array
    {
        return [
            'render_uuid'       => $render->uuid,
            'render_id'         => $render->id,
            'already_existed'   => $alreadyExisted,
            'instruction_count' => $alreadyExisted
                ? $render->instructionInstances()->count()
                : $instCount,
            'annotation_url'    => url("/benchmark/annotate/{$render->uuid}"),
        ];
    }
}
