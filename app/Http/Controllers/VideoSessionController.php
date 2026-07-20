<?php
namespace App\Http\Controllers;

use App\Models\VideoProject;
use App\Models\VideoSession;
use App\Models\VideoShot;
use Illuminate\Http\Request;

/**
 * Approval gate trong Laravel (ADR v1.1): draft → approved/needs_revision/rejected
 * → queued → rendered. Không có đường render nào bỏ qua duyệt.
 */
class VideoSessionController extends Controller
{
    public function index() {
        $sessions = VideoSession::with('project')->withCount('shots')->latest()->get();
        return view('video-session.index', compact('sessions'));
    }

    public function show(string $id) {
        $session = VideoSession::with(['project', 'shots'])->findOrFail($id);
        return view('video-session.show', compact('session'));
    }

    // Duyệt / cần sửa / từ chối MỘT shot
    public function shotAction(Request $request, string $shotId) {
        $shot = VideoShot::findOrFail($shotId);
        $action = $request->input('action');
        if ($action === 'approve') {
            $shot->update(['status' => 'approved', 'approved_at' => now(), 'review_note' => null]);
        } elseif ($action === 'revise') {
            $shot->update(['status' => 'needs_revision', 'review_note' => $request->input('note', '')]);
        } elseif ($action === 'reject') {
            $shot->update(['status' => 'rejected', 'review_note' => $request->input('note', '')]);
        }
        return back();
    }

    // Approve Selected (checkbox)
    public function approveSelected(Request $request, string $id) {
        VideoShot::where('session_id', $id)->whereIn('id', (array) $request->input('shot_ids', []))
            ->update(['status' => 'approved', 'approved_at' => now(), 'review_note' => null]);
        return back();
    }

    // 🎬 Render — CHỈ shot approved mới vào queue
    public function queueApproved(string $id) {
        VideoShot::where('session_id', $id)->where('status', 'approved')->update(['status' => 'queued']);
        VideoSession::where('id', $id)->update(['status' => 'rendering']);
        return back();
    }

    // Nut "Tao Video" trong cot Actions cua tung bai viet
    public function createFromArticle(\App\Models\Article $article) {
        $project = VideoProject::firstOrCreate(
            ['name' => \Illuminate\Support\Str::limit($article->title, 110, '')],
            ['subject_id' => $article->id]);
        $session = VideoSession::create([
            'project_id' => $project->id,
            'code' => 'art_' . substr($article->id, 0, 8) . '_' . now()->format('ymd_His'),
            'status' => 'composing',   // Python Composer poll trang thai nay -> do prompt vao
            'renderplan_json' => ['article_id' => $article->id],
        ]);
        return redirect()->route('video-session.show', $session->id)
            ->with('status', 'Session da tao - cho Composer sinh prompt');
    }

    // ---------- API cho Python (token: X-Video-Token = env VIDEO_API_TOKEN) ----------
    private function checkToken(Request $r): bool {
        $t = env('VIDEO_API_TOKEN');
        return $t && hash_equals($t, (string) $r->header('X-Video-Token'));
    }

    // POST /api/render-plans — Python đẩy session + shots (spec là INPUT, prompt là OUTPUT)
    public function apiStore(Request $r) {
        if (!$this->checkToken($r)) return response()->json(['error' => 'unauthorized'], 401);
        $data = $r->validate([
            'project' => 'required|string', 'subject_id' => 'nullable|string',
            'code' => 'required|string', 'renderplan' => 'nullable|array',
            'shots' => 'required|array|min:1',
        ]);
        $project = VideoProject::firstOrCreate(['name' => $data['project']], ['subject_id' => $data['subject_id'] ?? null]);
        $session = VideoSession::updateOrCreate(
            ['code' => $data['code']],
            ['project_id' => $project->id, 'renderplan_json' => $data['renderplan'] ?? null, 'status' => 'reviewing']);
        $total = 0;
        foreach ($data['shots'] as $s) {
            $total += (float) ($s['render_plan']['cost_estimate'] ?? 0);
            VideoShot::updateOrCreate(
                ['session_id' => $session->id, 'shot_code' => $s['shot_code'], 'kind' => $s['kind']],
                ['beat' => $s['beat'], 'shot_type' => $s['shot_type'] ?? 'establish',
                 'spec_json' => $s['spec'] ?? [], 'compiled_prompt' => $s['compiled_prompt'] ?? '',
                 'negative_prompt' => $s['negative_prompt'] ?? null, 'render_plan' => $s['render_plan'] ?? null,
                 'preview_path' => $s['preview_path'] ?? null,
                 'cost_estimate' => $s['render_plan']['cost_estimate'] ?? 0, 'status' => 'draft']);
        }
        $session->update(['cost_estimate_total' => $total]);
        return response()->json(['session_id' => $session->id, 'shots' => count($data['shots'])]);
    }

    // GET /api/video-shots/queued — runner Python poll
    public function apiQueued(Request $r) {
        if (!$this->checkToken($r)) return response()->json(['error' => 'unauthorized'], 401);
        return VideoShot::where('status', 'queued')->with('session:id,code')->get();
    }

    // PATCH /api/video-shots/{id}/result — runner báo kết quả
    public function apiResult(Request $r, string $shotId) {
        if (!$this->checkToken($r)) return response()->json(['error' => 'unauthorized'], 401);
        $shot = VideoShot::findOrFail($shotId);
        $ok = (bool) $r->input('success');
        $shot->update(['status' => $ok ? 'rendered' : 'failed', 'artifact_path' => $r->input('artifact_path')]);
        if ($ok) $shot->session->increment('cost_actual', (float) $r->input('cost', 0));
        return response()->json(['status' => $shot->status]);
    }
}
