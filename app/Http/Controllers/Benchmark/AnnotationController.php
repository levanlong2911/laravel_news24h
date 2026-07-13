<?php

namespace App\Http\Controllers\Benchmark;

use App\Http\Controllers\Controller;
use App\Models\Benchmark\BmInstructionInstance;
use App\Models\Benchmark\BmInstructionStat;
use App\Models\Benchmark\BmRender;
use App\Models\Benchmark\BmRenderScore;
use App\Models\Benchmark\BmSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AnnotationController extends Controller
{
    // ── Session list ──────────────────────────────────────────────────────────

    public function sessions(): View
    {
        $sessions = BmSession::with(['renders.score', 'renders.instructionInstances'])
            ->withCount('renders')
            ->get()
            ->map(function ($s) {
                $renders   = $s->renders;
                $total     = $renders->count();
                $annotated = $renders->filter(fn($r) => $r->annotated_at !== null)->count();
                $totalInst = $renders->sum(fn($r) => $r->instructionInstances->count());
                $doneInst  = $renders->sum(fn($r) => $r->instructionInstances->whereNotNull('observed')->count());

                return [
                    'session'    => $s,
                    'total'      => $total,
                    'annotated'  => $annotated,
                    'pct'        => $total > 0 ? round($annotated / $total * 100) : 0,
                    'inst_done'  => $doneInst,
                    'inst_total' => $totalInst,
                    'inst_pct'   => $totalInst > 0 ? round($doneInst / $totalInst * 100) : 0,
                ];
            });

        return view("benchmark.sessions", [
            "route" => "benchmark",
            "action" => "benchmark-sessions",
            "menu" => "menu-open",
            "active" => "active",
            'session' => $sessions
        ]);
    }

    // ── Render list (per session, grouped by fixture) ─────────────────────────

    public function renders(string $sessionCode): View
    {
        $session = BmSession::where('code', $sessionCode)->firstOrFail();

        $renders = BmRender::with(['fixture', 'score', 'instructionInstances'])
            ->where('session_id', $session->id)
            ->orderBy('fixture_id')
            ->orderBy('rendered_at')
            ->get();

        $byFixture = $renders->groupBy(fn($r) => $r->fixture->slug);

        return view("benchmark.renders", [
            "route" => "benchmark",
            "action" => "benchmark-renders",
            "menu" => "menu-open",
            "active" => "active",
            'session' => $session,
            'byFixture' => $byFixture
        ]);
    }

    // ── Annotation view ───────────────────────────────────────────────────────

    public function annotate(string $uuid): View
    {
        $render = BmRender::with([
            'session',
            'fixture',
            'score',
            'instructionInstances.catalog.planner',
            'plannerOutputs.planner',
            'renderPlanners.planner',
        ])->where('uuid', $uuid)->firstOrFail();

        // Group instruction instances by beat (in narrative order)
        $beatOrder = ['hook', 'escalation', 'reveal', 'payoff', 'resolution'];
        $byBeat    = collect($beatOrder)
            ->mapWithKeys(fn($b) => [
                $b => $render->instructionInstances->where('beat', $b)->values(),
            ])
            ->filter(fn($group) => $group->isNotEmpty());

        // Prev / Next render in same session (by rendered_at)
        $sibling = BmRender::where('session_id', $render->session_id)
            ->orderBy('rendered_at')
            ->pluck('uuid')
            ->values();

        $idx  = $sibling->search($uuid);
        $prev = $idx > 0 ? $sibling[$idx - 1] : null;
        $next = $idx < $sibling->count() - 1 ? $sibling[$idx + 1] : null;

        // Video URL: try to detect a local MP4 from artifact_path
        $videoUrl = $this->resolveVideoUrl($render->artifact_path);

        return view("benchmark.annotate", [
            "route" => "benchmark",
            "action" => "benchmark-annotate",
            "menu" => "menu-open",
            "active" => "active",
            'render' => $render,
            'byBeat' => $byBeat,
            'prev' => $prev,
            'next' => $next,
            'videoUrl' => $videoUrl,
        ]);
    }

    // ── Save annotation ───────────────────────────────────────────────────────

    public function save(Request $request, string $uuid): RedirectResponse
    {
        $render = BmRender::where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'instructions'                    => 'nullable|array',
            'instructions.*.id'               => 'required|integer',
            'instructions.*.observed'         => 'nullable|in:0,1',
            'instructions.*.confidence'       => 'nullable|in:high,medium,low',
            // Scores
            'scores.identity_consistency'     => 'nullable|integer|min:0|max:10',
            'scores.appearance_consistency'   => 'nullable|integer|min:0|max:10',
            'scores.geometry_consistency'     => 'nullable|integer|min:0|max:10',
            'scores.temporal_consistency'     => 'nullable|integer|min:0|max:10',
            'scores.camera_obey'              => 'nullable|integer|min:0|max:10',
            'scores.camera_continuity'        => 'nullable|integer|min:0|max:10',
            'scores.reveal_quality'           => 'nullable|integer|min:0|max:10',
            'scores.motion_realism'           => 'nullable|integer|min:0|max:10',
            'scores.physics'                  => 'nullable|integer|min:0|max:10',
            'scores.emotion'                  => 'nullable|integer|min:0|max:10',
            'scores.cinematic_feel'           => 'nullable|integer|min:0|max:10',
            'scores.eye_guidance'             => 'nullable|integer|min:0|max:10',
            'scores.overall'                  => 'nullable|integer|min:0|max:10',
        ]);

        DB::transaction(function () use ($render, $validated, $request) {
            // ── Instruction instances ─────────────────────────────────────────
            $sceneCategory = $render->fixture->scene_category;

            foreach ($validated['instructions'] ?? [] as $item) {
                $instance = BmInstructionInstance::find($item['id']);
                if (! $instance || $instance->render_id !== $render->id) {
                    continue;
                }

                $previousObserved = $instance->observed !== null
                    ? (int) $instance->observed
                    : null;

                $newObserved = isset($item['observed']) ? (int) $item['observed'] : null;

                $instance->observed   = $newObserved;
                $instance->confidence = $item['confidence'] ?? null;
                $instance->annotated_by = $request->user()?->name ?? 'annotator';
                $instance->annotated_at = now();
                $instance->save();

                // Only update materialized stats when observed is being set
                if ($newObserved !== null) {
                    BmInstructionStat::updateForAnnotation($instance, $sceneCategory, $previousObserved);
                }
            }

            // ── Scores ────────────────────────────────────────────────────────
            $scores = $validated['scores'] ?? [];
            if (! empty(array_filter($scores, fn($v) => $v !== null))) {
                $render->score()->updateOrCreate(
                    ['render_id' => $render->id],
                    array_merge($scores, ['scored_by' => $request->user()?->name ?? 'annotator'])
                );
            }

            // ── Mark annotated if all instructions and overall score done ─────
            $allDone = $render->instructionInstances()->whereNull('observed')->doesntExist();
            if ($allDone && ($scores['overall'] ?? null) !== null) {
                $render->annotated_at = now();
                $render->save();
            }
        });

        return redirect()
            ->route('benchmark.annotate', $uuid)
            ->with('success', 'Annotation saved.');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function resolveVideoUrl(?string $artifactPath): ?string
    {
        if ($artifactPath === null || $artifactPath === '') {
            return null;
        }

        // Resolve the full path and confirm it stays inside public/.
        // Rejects any artifact_path containing ../ sequences or symlinks escaping public/.
        $mp4        = rtrim($artifactPath, '/') . '/video.mp4';
        $resolved   = realpath(public_path($mp4));
        $publicRoot = realpath(public_path());

        if ($resolved === false || $publicRoot === false) {
            return null;
        }

        if (!str_starts_with($resolved, $publicRoot . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return asset($mp4);
    }
}
