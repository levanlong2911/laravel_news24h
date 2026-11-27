<?php

namespace App\Http\Controllers\Video;

use App\Models\VideoProject;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class FinalCompositionController extends VideoController
{
    public function index(string $id)
    {
        $this->ownedProject($id);
        if (($refused = $this->refusedClipStep()) !== null) { return $refused; }
        $project = VideoProject::query()->select(['id', 'title'])->findOrFail($id);

        // Du an dung phim theo chinh sach trong DB dung chinh man nay: cung cac o cua man, them phan rieng cua ke hoach phim.
        if ($this->usesFilmPolicyFlow($id)) {
            // `final` chon ban dung ma trang dang xem va kiem QC; khong co, hoac khong phai ban co file cua du an nay, thi trang xem ban moi nhat co file.
            $selected = request()->query('final');

            return view('video-projects.final-composition-preview', $this->chrome() + ['id' => $id, 'project' => $project] + app(\App\Services\Video\MotoFilmRenderService::class)->screen($id, is_string($selected) && $selected !== '' ? $selected : null));
        }

        return view('video-projects.final-composition-preview', $this->chrome() + [
            'id' => $id,
            'project' => $project,
            'composition' => $this->videoProjectService->finalCompositionCells($id),
        ]);
    }

    /**
     * Ghep ban final. CHAY DONG BO ngay trong request.
     *
     * Khong dung queue o buoc nay, nen thoi gian chay phai co tran: ngan sach nam o
     * `video.veo.compose_budget_seconds`, va no phai thap hon gioi han cua may chu
     * web. Mot vong polling tren trinh duyet KHONG giu cho tien trinh PHP song.
     */
    public function render(Request $request, string $id)
    {
        $this->ownedProject($id);
        if (($refused = $this->refusedClipStep()) !== null) { return $refused; }

        if ($this->usesFilmPolicyFlow($id)) {
            return $this->requestPolicyFilm($request, $id);
        }

        $data = $request->validate([
            'size' => ['required', 'string', Rule::in(\App\Video\FinalComposition\CompositionPlanBuilder::SIZES)],
            'fps' => 'required|integer|in:24,25,30',
            'crf' => 'required|integer|between:16,28',
            'crossfade' => 'nullable|boolean',
        ]);

        [$width, $height] = array_map('intval', explode('x', $data['size']));
        $fps = (int) $data['fps'];

        $result = $this->videoProjectService->renderFinalComposition($id, [
            'width' => $width,
            'height' => $height,
            'fps' => $fps,
            'crf' => (int) $data['crf'],
            // 0,5 giay quy ra KHUNG o dung fps da chon: chong lan phai roi vao bien
            // khung, va mot con so mili giay se phai lam tron o dau do.
            'crossfade_frames' => $request->boolean('crossfade') ? (int) round($fps / 2) : 0,
        ]);

        if ($result['ok']) {
            return back()->with('success', 'Ghep xong ban final.');
        }

        // Ly do tu verifier rat cu the (thieu khung, sai profile, lech tieng) — dua
        // ca ra thay vi rut gon thanh "that bai", vi do la thu noi duoc phai sua gi.
        return back()->with('error', trim(
            $result['error'] . (isset($result['reasons']) ? ': ' . implode(' | ', $result['reasons']) : ''),
        ));
    }

    /**
     * Phat/tai file final. Di qua day chu khong qua `public/`: file nam ngoai thu muc
     * cong khai, nen quyen doc no phai la quyen doc DU AN.
     */
    public function file(string $id, string $finalId)
    {
        $this->ownedProject($id);
        if (($refused = $this->refusedClipStep()) !== null) { return $refused; }

        // Phim dung theo chinh sach: gui mot ban sao vua bam lai, chi khi dung hash receipt da ghi.
        if ($this->usesFilmPolicyFlow($id)) {
            $final = app(\App\Services\Video\MotoFilmRenderService::class)->ownedFinal($id, $finalId);
            $approvals = app(\App\Services\Video\MotoFilmApprovalService::class);
            abort_if($approvals->receipt($final) === null, 404);
            $copy = $approvals->servable($final);
            abort_if($copy === null, 409, 'File final khác hash đã kiểm — không gửi.');

            return response()->file($copy['path'], ['Content-Type' => 'video/mp4', 'Accept-Ranges' => 'bytes'])->deleteFileAfterSend(true);
        }

        $final = \App\Models\VideoFinal::query()
            ->whereKey($finalId)
            ->whereHas('session', fn($scope) => $scope->where('project_id', $id))
            ->firstOrFail();

        // `realpath` CA HAI dau truoc khi so. `storage_path()` tra ve dau phan cach
        // lan (`storage\app/video-compose-final`) con `realpath` chuan hoa het ve
        // `\` — so mot ben da chuan hoa voi mot ben chua thi luon truot, va ket qua
        // la 404 cho mot file co that.
        $root = realpath((string) config('video.veo.compose_final_dir'));

        abort_if($root === false, 404);

        $root = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
        $path = realpath($root . (string) $final->video_path);

        // So tien to KEM dau phan cach: mot `video_path` doc hai khong duoc dan ra
        // ngoai thu muc ket qua.
        abort_if($path === false || ! str_starts_with($path, $root) || ! is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => 'video/mp4',
            // Trinh duyet doi seek duoc moi ve duoc khung hinh dau.
            'Accept-Ranges' => 'bytes',
        ]);
    }

    /** Trang thai mot ban final cua phim dung theo chinh sach, cho man dang cho job chay nen. Chi doc. */
    public function status(string $id, string $finalId)
    {
        $this->ownedProject($id);
        if (($refused = $this->refusedClipStep()) !== null) { return $refused; }
        abort_unless($this->usesFilmPolicyFlow($id), 404);
        $films = app(\App\Services\Video\MotoFilmRenderService::class);

        return response()->json($films->describe($films->ownedFinal($id, $finalId), null));
    }

    /** Nguoi dung duyet mot ban final da dung va da kiem; quyet dinh gan voi hash ke hoach phim, ban final va hash file. */
    public function approve(Request $request, string $id, string $finalId)
    {
        $this->ownedProject($id);
        if (($refused = $this->refusedClipStep()) !== null) { return $refused; }
        abort_unless($this->usesFilmPolicyFlow($id), 404);
        $data = $request->validate([
            'expected_plan_hash' => ['required', 'string', 'max:80'],
            'expected_sha256' => ['required', 'string', 'size:64'],
            'operation_id' => ['required', 'uuid'],
        ]);

        try {
            [, $reason] = app(\App\Services\Video\MotoFilmApprovalService::class)->approve($id, $finalId, (string) $data['expected_plan_hash'], (string) $data['expected_sha256'], (string) $data['operation_id'], (string) auth()->id());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $reason === 'replayed' ? 'Thao tác duyệt này đã được ghi trước đó.' : 'Đã duyệt bản final.');
    }

    /** Mot dau ra da luu cua ban final, tai ve duoi dang JSON; chi doc, khong ghi gi. */
    public function document(string $id, string $finalId, string $kind)
    {
        $this->ownedProject($id);
        if (($refused = $this->refusedClipStep()) !== null) { return $refused; }
        abort_unless($this->usesFilmPolicyFlow($id), 404);

        try {
            $document = app(\App\Services\Video\MotoFilmRenderService::class)->document($id, $finalId, $kind);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return response((string) json_encode($document['document'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$document['name'].'"',
        ]);
    }

    /** Do lai file cua mot ban final bang FFmpeg va luu bao cao QC moi; khong goi model nao. */
    public function qc(string $id, string $finalId)
    {
        $this->ownedProject($id);
        if (($refused = $this->refusedClipStep()) !== null) { return $refused; }
        abort_unless($this->usesFilmPolicyFlow($id), 404);

        return $this->qcAnswer(fn () => app(\App\Services\Video\MotoFilmQcService::class)->run($id, $finalId) === null
            ? throw new \RuntimeException('Dự án này không kiểm QC theo chính sách Phase 11.')
            : 'Đã kiểm QC kỹ thuật cho bản này.');
    }

    /** Nguoi review ket luan tung muc noi dung cua mot ban final. */
    public function qcReview(Request $request, string $id, string $finalId)
    {
        $this->ownedProject($id);
        if (($refused = $this->refusedClipStep()) !== null) { return $refused; }
        abort_unless($this->usesFilmPolicyFlow($id), 404);
        $data = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'verdicts' => ['required', 'array'],
            'verdicts.*' => ['required', 'string', Rule::in(\App\Video\Moto\Film\MotoFilmQcReport::VERDICTS)],
            'notes' => ['nullable', 'array'],
            'notes.*' => ['nullable', 'string', 'max:2000'],
        ]);
        $verdicts = [];

        foreach ($data['verdicts'] as $area => $verdict) {
            $verdicts[(string) $area] = ['verdict' => (string) $verdict, 'note' => $data['notes'][$area] ?? null];
        }

        return $this->qcAnswer(function () use ($id, $finalId, $verdicts, $data): string {
            app(\App\Services\Video\MotoFilmQcService::class)->review($id, $finalId, $verdicts, (string) $data['operation_id'], (string) auth()->id());

            return 'Đã ghi review nội dung.';
        });
    }

    /** Nguoi review neu mot finding cho mot ban final. */
    public function qcFinding(Request $request, string $id, string $finalId)
    {
        $this->ownedProject($id);
        if (($refused = $this->refusedClipStep()) !== null) { return $refused; }
        abort_unless($this->usesFilmPolicyFlow($id), 404);
        $data = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'severity' => ['required', 'string', 'max:20'],
            'description' => ['required', 'string', 'max:2000'],
            'suggestion' => ['nullable', 'string', 'max:2000'],
            'area' => ['nullable', 'string', 'max:60'],
            'start_s' => ['nullable', 'numeric', 'min:0'],
            'end_s' => ['nullable', 'numeric', 'min:0'],
        ]);
        $ms = static fn (mixed $seconds): ?int => $seconds === null || $seconds === '' ? null : (int) round((float) $seconds * 1000);

        return $this->qcAnswer(function () use ($id, $finalId, $data, $ms): string {
            app(\App\Services\Video\MotoFilmQcService::class)->raise($id, $finalId, [
                'severity' => (string) $data['severity'],
                'description' => (string) $data['description'],
                'suggestion' => $data['suggestion'] ?? null,
                'area' => ($data['area'] ?? '') === '' ? null : (string) $data['area'],
                'start_ms' => $ms($data['start_s'] ?? null),
                'end_ms' => $ms($data['end_s'] ?? null),
            ], (string) $data['operation_id'], (string) auth()->id());

            return 'Đã ghi finding.';
        });
    }

    /** Nguoi co quyen xu ly mot finding: da sua, da kiem lai, chap nhan kem ly do, hoac bao nham. */
    public function qcSettle(Request $request, string $id, string $finalId, string $finding)
    {
        $this->ownedProject($id);
        if (($refused = $this->refusedClipStep()) !== null) { return $refused; }
        abort_unless($this->usesFilmPolicyFlow($id), 404);
        $data = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'status' => ['required', 'string', 'max:20'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        return $this->qcAnswer(function () use ($id, $finalId, $finding, $data): string {
            app(\App\Services\Video\MotoFilmQcService::class)->settle($id, $finalId, $finding, (string) $data['status'], (string) $data['reason'], (string) $data['operation_id'], (string) auth()->id());

            return 'Đã ghi xử lý finding '.$finding.'.';
        });
    }

    /** Nguoi dung yeu cau sua mot ban final: ban do o lai lich su va khong duyet duoc nua; sua la mot ban dung moi. */
    public function requestChanges(Request $request, string $id, string $finalId)
    {
        $this->ownedProject($id);
        if (($refused = $this->refusedClipStep()) !== null) { return $refused; }
        abort_unless($this->usesFilmPolicyFlow($id), 404);
        $data = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        return $this->qcAnswer(function () use ($id, $finalId, $data): string {
            [, $how] = app(\App\Services\Video\MotoFilmQcService::class)->requestChanges($id, $finalId, (string) $data['reason'], (string) $data['operation_id'], (string) auth()->id());

            return $how === 'replayed' ? 'Yêu cầu sửa này đã được ghi trước đó.' : 'Đã ghi yêu cầu sửa. Bản này không duyệt được nữa — dựng bản mới sau khi sửa.';
        });
    }

    /** @param \Closure(): string $act what the action does; its message goes back as success, a refusal as the error of the server */
    private function qcAnswer(\Closure $act)
    {
        try {
            return back()->with('success', $act());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Du an dung phim theo chinh sach trong DB (`video.films.workflows`), tren chinh cac route Final Composition. */
    private function usesFilmPolicyFlow(string $id): bool
    {
        return app(\App\Services\Video\MotoFilmSourceService::class)->supports($id);
    }

    /** Nhan mot luot dung phim vao hang doi: kiem lai hash trang dang mo va ma thao tac, khong dung trong request. */
    private function requestPolicyFilm(Request $request, string $id)
    {
        $data = $request->validate([
            'expected_hash' => ['required', 'string', 'max:80'],
            'operation_id' => ['required', 'uuid'],
            // What the film is cut for: a preview of the clips approved so far, or the final that holds every shot. The server decides what each may do; the page only names it.
            'purpose' => ['required', 'string', Rule::in(\App\Video\Moto\Film\MotoFilmScope::PURPOSES)],
            // The hash of the edit plan the page shows for each purpose; a project that cuts with no edit plan sends none. The server holds the one of the purpose asked against the plan in force.
            'expected_edit_hash' => ['nullable', 'array'],
            'expected_edit_hash.*' => ['nullable', 'string', 'max:80'],
        ], ['purpose.*' => 'Thiếu mục đích dựng (dựng thử hoặc dựng final) — tải lại trang.']);
        $purpose = (string) $data['purpose'];
        $name = $purpose === \App\Video\Moto\Film\MotoFilmScope::PREVIEW ? 'bản dựng thử' : 'bản final';
        $editHash = $data['expected_edit_hash'][$purpose] ?? null;

        try {
            [$final, $reason] = app(\App\Services\Video\MotoFilmRenderService::class)->request($id, (string) $data['expected_hash'], (string) $data['operation_id'], (string) auth()->id(), $purpose, is_string($editHash) && $editHash !== '' ? $editHash : null);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($reason === 'composed') {
            return $final->status === \App\Services\Video\MotoFilmRenderService::FAILED
                ? back()->with('error', 'Dựng '.$name.' #'.$final->revision.' không thành công: '.\Illuminate\Support\Str::limit((string) $final->error_message, 300))
                : back()->with('success', 'Đã dựng '.$name.' #'.$final->revision.' ngay trên máy này ('.$final->status.').');
        }

        return back()->with('success', $reason === 'replayed'
            ? 'Thao tác dựng này đã được ghi trước đó ('.$name.' #'.$final->revision.', '.$final->status.').'
            : 'Đã xếp hàng dựng '.$name.' #'.$final->revision.' — job chạy nền, đóng tab không làm dừng nó; trang tự cập nhật khi có kết quả.');
    }
}
