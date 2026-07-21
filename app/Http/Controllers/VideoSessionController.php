<?php
namespace App\Http\Controllers;

use App\Services\VideoSessionService;
use Illuminate\Http\Request;

/**
 * Approval gate trong Laravel (ADR v1.1): draft → approved/needs_revision/rejected
 * → queued → rendered. Không có đường render nào bỏ qua duyệt.
 */
class VideoSessionController extends Controller
{
    public function __construct(private VideoSessionService $videoSessionService)
    {
    }

    public function index() {
        return view('video-session.index', [
            'route'    => 'video-session',
            'action'   => 'admin-video-session',
            'menu'     => 'menu-open',
            'active'   => 'active',
            'sessions' => $this->videoSessionService->listAll(),
        ]);
    }

    public function show(string $id) {
        return view('video-session.show', [
            'route'   => 'video-session',
            'action'  => 'admin-video-session',
            'menu'    => 'menu-open',
            'active'  => 'active',
            'session' => $this->videoSessionService->findForShow($id),
        ]);
    }

    // Duyệt / cần sửa / từ chối MỘT shot
    public function shotAction(Request $request, string $shotId) {
        $this->videoSessionService->updateShotStatus($shotId, $request->input('action'), $request->input('note', ''));
        return back();
    }

    // Approve Selected (checkbox)
    public function approveSelected(Request $request, string $id) {
        $this->videoSessionService->approveSelectedShots($id, (array) $request->input('shot_ids', []));
        return back();
    }

    // 🎬 Render — CHỈ shot approved mới vào queue
    public function queueApproved(string $id) {
        $this->videoSessionService->queueApproved($id);
        return back();
    }

    // Nut "Tao Video" trong cot Actions cua tung bai viet
    public function creatPrompt(Request $request, string $id) {
        $session = $this->videoSessionService->createFromArticleId($id);
        if ($session) {
            return redirect()->route('video-session.show', $session->id)
                ->with('status', 'Session da tao - cho Composer sinh prompt');
        }
        return back()->with('error', __('messages.add_error'));
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
        return response()->json($this->videoSessionService->storeFromPython($data));
    }

    // GET /api/video-sessions/composing — runner poll de compose prompt
    public function apiComposing(Request $r) {
        if (!$this->checkToken($r)) return response()->json(['error' => 'unauthorized'], 401);
        return $this->videoSessionService->listComposing();
    }

    // GET /api/video-shots/queued — runner Python poll
    public function apiQueued(Request $r) {
        if (!$this->checkToken($r)) return response()->json(['error' => 'unauthorized'], 401);
        return $this->videoSessionService->listQueuedShots();
    }

    // PATCH /api/video-shots/{id}/result — runner báo kết quả
    public function apiResult(Request $r, string $shotId) {
        if (!$this->checkToken($r)) return response()->json(['error' => 'unauthorized'], 401);
        $shot = $this->videoSessionService->reportShotResult(
            $shotId,
            (bool) $r->input('success'),
            $r->input('artifact_path'),
            (float) $r->input('cost', 0)
        );
        return response()->json(['status' => $shot->status]);
    }
}
