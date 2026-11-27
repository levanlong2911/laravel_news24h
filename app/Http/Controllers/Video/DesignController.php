<?php

namespace App\Http\Controllers\Video;

use App\Video\Moto\MotoCheckRules;
use App\Services\Video\ModelSheetQaService;
use App\Services\Video\ModelSheetService;
use App\Services\Video\YachtDesignService;
use App\Services\Video\VesselDesignService;
use Illuminate\Http\Request;

final class DesignController extends VideoController
{
    public function run(Request $request, string $id)
    {
        $this->ownedProject($id);

        return $this->phaseDesign($request, $id);
    }

    private function phaseDesign(Request $request, string $id)
    {
        $data = $request->validate(['operation_id' => ['required', 'uuid']], ['operation_id.*' => 'Thiếu mã thao tác — tải lại trang.']);
        [$stage, $reason] = app(YachtDesignService::class)->run($id, $request->boolean('force'), (string) $data['operation_id'], $this->requester());

        if ($stage === null) {
            return back()->with('error', $reason);
        }

        if ($reason === 'queued') {
            return $this->queued($request, $id, $stage, 'thiết kế', route('video-projects.anchor', $id));
        }

        $message = match ($reason) {
            'cached' => 'DNA và prompt Phase 3 không đổi — dùng lại bản thiết kế đã lưu, không gọi model.',
            'replayed' => 'Thao tác này đã chạy trước đó — không gọi model lần nữa.',
            default => 'Đã lưu bản thiết kế Phase 3 (draft) — xem kết quả kiểm RULE.',
        };

        return MotoCheckRules::usable((array) ($stage->output_json['check'] ?? []))
            ? redirect()->to(route('video-projects.anchor', $id).'#modelSheetPanel')->with('success', $message.' Thiết kế đạt kiểm — render model sheet.')
            : back()->with('success', $message);
    }

    public function reset(string $id)
    {
        $this->ownedProject($id);

        [$done, $reason] = app(VesselDesignService::class)->reset($id);

        return $done
            ? back()->with('success', $reason === 'pending' ? self::CANCELLED : 'Đã reset — bấm Thiết kế tàu để chạy lại.')
            : back()->with('error', $reason);
    }

    public function sheetRender(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $this->form->validate($request, 'ModelSheetRenderForm');
        [$image, $reason] = app(ModelSheetService::class)->render($id, (string) auth()->user()?->name, [
            'model' => (string) $data['model'],
            'quality' => (string) $data['quality'],
            'attempt' => (string) $data['attempt'],
            'qa_stage_id' => (string) $data['qa_stage_id'],
            'prompt_sha256' => (string) $data['prompt_sha256'],
        ], $this->requester());

        if ($image === null) {
            return back()->with('error', $reason);
        }

        if ($reason === 'queued') {
            return redirect()->to(route('video-projects.anchor', $id).'#modelSheetPanel')->with('success', "Đã đưa render master ({$image->image_code}) vào hàng đợi — chạy nền trên server, tự cắt tám ô khi xong; đóng trang vẫn chạy tiếp, trang tự cập nhật khi xong.");
        }

        if (str_starts_with($reason, 'processing_failed|')) {
            return back()->with('error', "Đã render master ({$image->image_code}) nhưng xử lý ảnh lỗi: ".substr($reason, strlen('processing_failed|')).' — bấm "Xử lý lại từ master", không cần render lại.');
        }

        return match ($reason) {
            'rendered' => back()->with('success', "Đã render master ({$image->image_code}) và cắt tám ô — kiểm tra rồi duyệt hoặc từ chối cả sheet."),
            'already_exists' => back()->with('error', "Lượt render này đã được gửi trước đó ({$image->image_code}, trạng thái {$image->status}) — không gọi provider lần nữa. Tải lại trang để có lượt mới."),
            default => back()->with('error', "Render master chưa thành công ({$reason}): ".(string) $image->render_error),
        };
    }

    public function sheetQa(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $request->validate(['operation_id' => ['required', 'uuid']], ['operation_id.*' => 'Thiếu mã thao tác — tải lại trang.']);
        [$stage, $reason] = app(ModelSheetService::class)->qa($id, $request->boolean('retry'), (string) $data['operation_id'], $this->requester());

        if ($stage !== null && $reason === 'queued') {
            return $this->queued($request, $id, $stage, 'QA model sheet', route('video-projects.anchor', $id).'#modelSheetPanel');
        }

        return match ($reason) {
            'ready' => back()->with('success', 'QA xong — prompt đạt kiểm. Xem prompt cuối, thay đổi và findings rồi bấm Render.'),
            'not_ready' => back()->with('error', 'QA xong nhưng prompt chưa đạt kiểm — xem lỗi và findings bên dưới. Sửa ở thiết kế nếu là lỗi nguồn, hoặc bấm "QA lại".'),
            'cached' => back()->with('success', 'QA đã có cho đúng nguồn này — không gọi Sonnet lần nữa.'),
            'replayed' => back()->with('success', 'Thao tác này đã chạy trước đó — không gọi Sonnet lần nữa.'),
            default => back()->with('error', $reason),
        };
    }

    public function sheetQaReset(string $id)
    {
        $this->ownedProject($id);

        [$done, $reason] = app(ModelSheetQaService::class)->reset($id);

        return $done
            ? back()->with('success', $reason === 'pending' ? self::CANCELLED : 'Đã reset lượt QA model sheet.')
            : back()->with('error', $reason);
    }

    public function sheetApprove(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $this->form->validate($request, 'AnchorApproveForm');
        [$done, $reason] = app(ModelSheetService::class)->approve($id, (string) $data['artifact_id'], auth()->id());

        if (! $done) {
            return back()->with('error', $reason);
        }

        return back()->with('success', 'Đã duyệt model sheet — đây chưa phải DESIGN LOCK.');
    }

    public function sheetReject(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $request->validate(['image_id' => ['required', 'uuid']], ['image_id.*' => 'Thiếu master cần từ chối.']);
        [$done, $reason] = app(ModelSheetService::class)->reject($id, (string) $data['image_id']);

        return $done ? back()->with('success', 'Đã từ chối cả master — render một master mới.') : back()->with('error', $reason);
    }

    public function sheetProcess(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $request->validate(['image_id' => ['required', 'uuid']], ['image_id.*' => 'Thiếu master cần xử lý.']);
        [$done, $reason] = app(ModelSheetService::class)->process($id, (string) $data['image_id']);

        return match (true) {
            ! $done => back()->with('error', $reason),
            $reason === 'already_processed' => back()->with('success', 'Tám ô cắt đã đủ và khớp sha256 — không cần cắt lại.'),
            default => back()->with('success', 'Đã xử lý lại từ master đã lưu — không gọi model.'),
        };
    }
}
