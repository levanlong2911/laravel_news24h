<?php

namespace App\Http\Controllers\Video;

use App\Services\Video\ShotSourceService;
use App\Services\Video\ShotWriterService;
use Illuminate\Http\Request;

final class ShotController extends VideoController
{
    public function run(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $request->validate(['operation_id' => ['required', 'uuid']], ['operation_id.*' => 'Thiếu mã thao tác — tải lại trang.']);
        [$stage, $reason] = app(ShotWriterService::class)->run($id, $request->boolean('rewrite'), (string) $data['operation_id'], $this->requester());

        if ($stage === null) {
            return back()->with('error', $reason);
        }

        if ($reason === 'queued') {
            return $this->queued($request, $id, $stage, 'lập kế hoạch shot', route('video-projects.scene', $id));
        }

        if ($this->shotPlanChosen($id, $stage)) {
            return redirect()->route('video-projects.reference', $id)->with('success', self::SHOT_PLAN_APPROVED);
        }

        return redirect()->route('video-projects.scene', ['id' => $id, 'shots' => $stage->id])->with('success', match ($reason) {
            'cached' => 'Nguồn và prompt không đổi — dùng lại kế hoạch shot đã lưu, không gọi model.',
            'replayed' => 'Thao tác này đã chạy trước đó — không gọi model lần nữa.',
            default => 'Đã lưu kế hoạch shot — chưa đạt kiểm nên chưa được duyệt; xem kết quả kiểm bên dưới.',
        });
    }

    public function reset(string $id)
    {
        $this->ownedProject($id);

        [$done, $reason] = app(ShotWriterService::class)->reset($id);

        return $done ? back()->with('success', $reason === 'pending' ? self::CANCELLED : 'Đã reset lượt lập kế hoạch shot bị kẹt — bản đã duyệt giữ nguyên.') : back()->with('error', $reason);
    }

    public function approve(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $request->validate([
            'stage_id' => ['required', 'uuid'],
            'content_hash' => ['required', 'string', 'max:80'],
            'selection_version' => ['required', 'integer', 'min:0'],
            'operation_id' => ['required', 'uuid'],
        ], [
            'stage_id.*' => 'Thiếu kế hoạch shot cần duyệt — tải lại trang.',
            'content_hash.*' => 'Thiếu hash nội dung — tải lại trang.',
            'selection_version.*' => 'Thiếu phiên bản lựa chọn — tải lại trang.',
            'operation_id.*' => 'Thiếu mã thao tác — tải lại trang.',
        ]);
        [$decision, $reason] = app(ShotSourceService::class)->approve(
            $id,
            (string) $data['stage_id'],
            (string) $data['content_hash'],
            (int) $data['selection_version'],
            (string) $data['operation_id'],
            auth()->id(),
        );

        if ($decision === null) {
            return back()->with('error', $reason);
        }

        return $reason === 'replayed'
            ? redirect()->route('video-projects.scene', ['id' => $id, 'shots' => $data['stage_id']])->with('success', 'Lần duyệt này đã được ghi trước đó — không ghi thêm.')
            : redirect()->route('video-projects.reference', $id)->with('success', 'Đã duyệt kế hoạch shot thành công.');
    }
}
