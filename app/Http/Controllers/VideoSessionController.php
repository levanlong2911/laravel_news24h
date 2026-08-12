<?php

namespace App\Http\Controllers;

use App\Enums\VideoShotStatus;
use App\Services\VideoSessionService;
use App\Video\Analysis\RenderPlanQualityReport;
use App\Video\Pipeline\PipelineAborted;
use Illuminate\Http\Request;

/**
 * Approval gate trong Laravel (ADR v1.1): draft → approved/needs_revision/rejected
 * → queued → rendered. Không có đường render nào bỏ qua duyệt.
 */
class VideoSessionController extends Controller
{
    /**
     * @param  RenderPlanQualityReport  $qualityReport  Tiêm THẲNG vào Controller, không bọc
     *                                                  qua VideoSessionService — cân nhắc rồi chọn (2026-07-30):
     *                                                  báo cáo là HÀM THUẦN của RenderPlan, không đọc/ghi DB, không thuộc
     *                                                  vòng đời session (thứ VideoSessionService chịu trách nhiệm). Bọc
     *                                                  thêm một method pass-through kèm null-check ở service chỉ là nghi
     *                                                  thức — cùng lý do đã dùng để loại việc tiêm VideoPipelineFactory
     *                                                  (§18 checkpoint). Việc duy nhất ở đây là "có plan để soi không".
     */
    public function __construct(
        private VideoSessionService $videoSessionService,
        private RenderPlanQualityReport $qualityReport,
    ) {}

    public function index()
    {
        return view('video-session.index', [
            'route' => 'video-session',
            'action' => 'admin-video-session',
            'menu' => 'menu-open',
            'active' => 'active',
            'sessions' => $this->videoSessionService->listAll(),
        ]);
    }

    public function show(string $id)
    {
        $session = $this->videoSessionService->findForShow($id);

        // Tinh MOT LAN o day roi truyen xuong, khong goi trong Blade: view lay
        // ca `warnings` lan `metrics` o hai cho khac nhau, goi trong view la 2
        // lan tinh lai cung mot thu. Deterministic nen ket qua giong nhau,
        // nhung khong co ly do de tinh hai lan.
        //
        // KHONG luu vao DB: bao cao la ham thuan cua RenderPlan, tinh lai luon
        // re — con luu thi se cu di ngay khi them/sua check, ma nguong hien tai
        // van con la phong doan (§18.19).
        //
        // `renderplan_json` CO THE null: storeFromPython() set no tu
        // `$data['renderplan'] ?? null`, nen session do Python day ve co the
        // khong co plan. null => khong render panel, KHONG nem loi.
        $plan = $session->renderplan_json;

        return view('video-session.show', [
            'route' => 'video-session',
            'action' => 'admin-video-session',
            'menu' => 'menu-open',
            'active' => 'active',
            'session' => $session,
            'quality' => is_array($plan) && $plan !== [] ? $this->qualityReport->analyze($plan) : null,
            'finalReadiness' => $this->videoSessionService->finalCompositionReadiness($session),
            'latestFinal' => $session->finals()->latest()->first(),
        ]);
    }

    // Duyệt / cần sửa / từ chối MỘT shot
    public function shotAction(Request $request, string $shotId)
    {
        $this->videoSessionService->updateShotStatus($shotId, $request->input('action'), $request->input('note', ''));

        return back();
    }

    // Approve Selected (checkbox)
    public function approveSelected(Request $request, string $id)
    {
        $this->videoSessionService->approveSelectedShots($id, (array) $request->input('shot_ids', []));

        return back();
    }

    // 🎬 Render — CHỈ shot approved mới vào queue
    public function queueApproved(string $id)
    {
        [$queued, $spawned] = $this->videoSessionService->queueApproved($id);

        if ($queued === 0) {
            return back()->with('warning', 'Khong co shot nao da duyet - chua co gi de render.');
        }

        if ($spawned) {
            return back()->with('status', sprintf(
                '%d shot vao hang doi - dang render o nen. F5 de xem tien do (moi clip xong se hien ngay).',
                $queued,
            ));
        }

        // Shot ĐÃ vào queue rồi, chỉ là chưa ai render. Chạy tay là xong —
        // không mất gì, không phải bấm lại nút.
        return back()->with('warning', sprintf(
            '%d shot vao hang doi nhung KHONG ban duoc tien trinh nen. Chay tay: %s',
            $queued,
            $this->videoSessionService->manualCommandFor(VideoSessionService::RENDER_SCRIPT, $id),
        ));
    }

    // 🎬 Ghép video hoàn chỉnh — chỉ khi mọi cảnh trong timeline đã render xong
    public function composeFinal(string $id)
    {
        [$final, $spawned, $reason] = $this->videoSessionService->startFinalComposition($id);

        if ($reason === 'not_ready') {
            return back()->with('warning', 'Chua du canh da render — khong the ghep final.');
        }

        if ($reason === 'already_composing') {
            return back()->with('warning', sprintf(
                'Da co ban ghep (id=%s) dang chay o nen — khong bat them tien trinh. F5 de xem tien do.',
                $final->id,
            ));
        }

        if ($spawned) {
            return back()->with('status', 'Dang ghep video hoan chinh o nen. F5 sau it phut de xem ket qua.');
        }

        return back()->with('warning', sprintf(
            'Da tao ban ghep (id=%s) nhung KHONG ban duoc tien trinh nen. Chay tay: %s',
            $final->id,
            $this->videoSessionService->manualCommandFor(VideoSessionService::FINAL_COMPOSE_SCRIPT, $final->session->code),
        ));
    }

    /**
     * 🔍 Thu render — chay dung duong render that, KHONG goi vendor, KHONG doi du lieu.
     *
     * Output do thang len man hinh chu khong vao log: nut nay ton tai de NGUOI DOC
     * KET QUA. Bao "da chay xong, moi mo file log" la lam hong chinh muc dich.
     */
    public function previewRender(string $id)
    {
        [$ok, $output] = $this->videoSessionService->previewRender($id);

        return back()->with($ok ? 'preflight' : 'error', $output);
    }

    /**
     * Nut "Tao Video" trong cot Actions cua tung bai viet.
     *
     * HIEN LY DO THAT len man hinh, khong dung mot cau chung chung (doi
     * 2026-07-30). Truoc day service nuot moi loi thanh `null` va o day hien
     * `__('messages.add_error')` — nguoi dung khong biet hong o dau, nen cach
     * duy nhat de mo la cam `dd()` vao giua pipeline, ma moi lan bam lai de mo
     * la them mot cu goi Claude bi tinh tien.
     *
     * `PipelineAborted` tach rieng vi no KHONG phai su co: pipeline chu dong
     * dung vi thieu du lieu, va message cua no da viet cho nguoi doc. Quan
     * trong nhat la no noi ro DA TON PHI CHUA — nguoi dung biet bam lai co mat
     * tien khong.
     */
    public function creatVideo(Request $request, string $id)
    {
        try {
            $session = $this->videoSessionService->creatVideoById($id);
        } catch (PipelineAborted $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            // Loi that (mat mang, Claude tu choi, DB hong...). Stack trace da
            // vao log o service; o day chi dua ra dong nguoi doc hieu duoc, kem
            // ten class de tra log nhanh.
            return back()->with('error', sprintf(
                'Tao video that bai (%s): %s',
                class_basename($e),
                $e->getMessage(),
            ));
        }

        // Bắn được thì Python đang chạy nền, vài giây nữa F5 là có prompt.
        // Bắn KHÔNG được thì phải nói rõ câu lệnh chạy tay — nếu chỉ hiện
        // "đang xử lý", người dùng sẽ F5 mãi mà không bao giờ có gì (§18.25).
        if ($this->videoSessionService->composeWasSpawned()) {
            return redirect()->route('video-session.show', $session->id)
                ->with('status', 'Session da tao - Composer dang sinh prompt o nen, F5 sau vai giay.');
        }

        return redirect()->route('video-session.show', $session->id)
            ->with('warning', sprintf(
                'Session da tao nhung KHONG ban duoc tien trinh nen. Chay tay: %s',
                $this->videoSessionService->manualCommandFor(VideoSessionService::COMPOSE_SCRIPT, $session->code),
            ));
    }

    // ---------- API cho Python (X-Video-Token khop video.api_token da nap) ----------
    private function checkToken(Request $r): bool
    {
        $t = config('video.api_token');

        return is_string($t)
            && $t !== ''
            && hash_equals($t, (string) $r->header('X-Video-Token'));
    }

    // POST /api/render-plans — Python đẩy session + shots (spec là INPUT, prompt là OUTPUT)
    public function apiStore(Request $r)
    {
        if (! $this->checkToken($r)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        // Validate ca NOI DUNG tung shot, khong chi cap tren cung. shot_code/
        // kind/beat duoc storeFromPython() truy cap TRUC TIEP (khong co ??
        // mac dinh) — thieu mot key la loi luc chay, sau khi vai shot da ghi
        // vao DB. Validate truoc thi hong som va hong sach.
        $data = $r->validate([
            'project' => 'required|string',
            'subject_id' => 'nullable|string',
            'code' => 'required|string',
            'renderplan' => 'nullable|array',
            'shots' => 'required|array|min:1',
            'shots.*.shot_code' => 'required|string',
            'shots.*.kind' => 'required|string',
            'shots.*.beat' => 'required|string',
            'shots.*.shot_type' => 'nullable|string',
            'shots.*.spec' => 'nullable|array',
            'shots.*.compiled_prompt' => 'nullable|string',
            'shots.*.negative_prompt' => 'nullable|string',
            'shots.*.render_plan' => 'nullable|array',
            'shots.*.preview_path' => 'nullable|string',
        ]);

        return response()->json($this->videoSessionService->storeFromPython($data));
    }

    // GET /api/video-sessions/composing — runner poll de compose prompt
    public function apiComposing(Request $r)
    {
        if (! $this->checkToken($r)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $this->videoSessionService->listComposing();
    }

    // GET /api/video-shots/queued — runner Python poll
    public function apiQueued(Request $r)
    {
        if (! $this->checkToken($r)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $this->videoSessionService->listQueuedShots();
    }

    // POST /api/video-shots/claim — atomic claim theo session, mot token cho ca batch.
    public function apiClaim(Request $r)
    {
        if (! $this->checkToken($r)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $data = $r->validate([
            'session_code' => 'required|string|max:64',
            'worker_id' => 'required|string|max:100',
            'limit' => 'sometimes|integer|min:1|max:100',
            'lease_seconds' => 'sometimes|integer|min:60|max:3600',
        ]);

        return response()->json($this->videoSessionService->claimQueuedShots(
            $data['session_code'],
            $data['worker_id'],
            (int) ($data['limit'] ?? 10),
            (int) ($data['lease_seconds'] ?? 600),
        ));
    }

    // PATCH /api/video-shots/{id}/heartbeat — chi owner con lease moi gia han duoc.
    public function apiHeartbeat(Request $r, string $shotId)
    {
        if (! $this->checkToken($r)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $data = $r->validate([
            'worker_id' => 'required|string|max:100',
            'claim_token' => 'required|uuid',
            'lease_seconds' => 'sometimes|integer|min:60|max:3600',
        ]);

        $renewed = $this->videoSessionService->heartbeatShotClaim(
            $shotId,
            $data['worker_id'],
            $data['claim_token'],
            (int) ($data['lease_seconds'] ?? 600),
        );

        return $renewed
            ? response()->json(['status' => VideoShotStatus::RENDERING->value])
            : response()->json(['error' => 'claim_not_owned_or_expired'], 409);
    }

    // POST /api/video-shots/reclaim-expired — van hanh/scheduler goi dinh ky.
    public function apiReclaimExpired(Request $r)
    {
        if (! $this->checkToken($r)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return response()->json([
            'requeued' => $this->videoSessionService->reclaimExpiredShotLeases(),
        ]);
    }

    // PATCH /api/video-shots/{id}/result — runner báo kết quả
    public function apiResult(Request $r, string $shotId)
    {
        if (! $this->checkToken($r)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }
        $data = $r->validate([
            'success' => 'required|boolean',
            'artifact_path' => 'nullable|string|max:255',
            'cost' => 'nullable|numeric|min:0',
            'idempotency_key' => 'nullable|string|max:64',
            'render' => 'nullable|array',
            'worker_id' => 'sometimes|string|max:100',
            'claim_token' => 'sometimes|uuid',
        ]);
        if (array_key_exists('worker_id', $data) !== array_key_exists('claim_token', $data)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'claim_token' => ['worker_id and claim_token must be provided together.'],
                'worker_id' => ['worker_id and claim_token must be provided together.'],
            ]);
        }
        // `render` la HO SO LUOT NAY (prompt da gui, model, anh nguon, tien that)
        // -> mot dong `video_renders` bat bien. Vang thi hanh xu y nhu truoc, nen
        // runner cu van chay duoc va trien khai lech phien ban khong hong gi.
        $render = $data['render'] ?? null;
        $shot = $this->videoSessionService->reportShotResult(
            $shotId,
            $r->boolean('success'),
            $data['artifact_path'] ?? null,
            (float) ($data['cost'] ?? 0),
            is_array($render) ? $render : null,
            $data['idempotency_key'] ?? null,
            $data['worker_id'] ?? null,
            $data['claim_token'] ?? null,
        );

        if ($shot === null) {
            return response()->json(['error' => 'claim_not_owned_or_expired'], 409);
        }

        return response()->json(['status' => $shot->status]);
    }

    // GET /api/video-finals/composing — Python kéo kế hoạch ghép final
    public function apiFinalsComposing(Request $r)
    {
        if (! $this->checkToken($r)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $data = $r->validate([
            'session_code' => 'required|string|max:64',
        ]);

        return response()->json($this->videoSessionService->buildFinalCompositionPlan($data['session_code']));
    }

    // PATCH /api/video-finals/{id}/result — Python báo kết quả FFmpeg
    public function apiFinalResult(Request $r, string $finalId)
    {
        if (! $this->checkToken($r)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $data = $r->validate([
            'success' => 'required|boolean',
            'video_path' => 'required_if:success,true|string|max:255',
            'duration_ms' => 'required_if:success,true|integer|min:0',
            'error' => 'nullable|string',
        ]);

        $final = $this->videoSessionService->recordFinalCompositionResult(
            $finalId,
            $r->boolean('success'),
            $data['video_path'] ?? null,
            $data['duration_ms'] ?? null,
            $data['error'] ?? null,
        );

        return response()->json(['status' => $final->status]);
    }
}
