<?php

namespace App\Http\Controllers\Video;

use Illuminate\Http\Request;

final class InspirationController extends VideoController
{
    public function run(Request $request, string $id)
    {
        $this->ownedProject($id);
        $data = $request->validate(['operation_id' => ['required', 'uuid']], ['operation_id.*' => 'Thiếu mã thao tác — tải lại trang.']);
        [$stage, $reason] = $this->videoProjectService->runInspiration($id, (string) $data['operation_id'], $this->requester());

        if ($stage === null) {
            return back()->with('error', $reason);
        }

        if ($reason === 'queued') {
            return $this->queued($request, $id, $stage, 'phân tích', route('video-projects.anchor', $id));
        }

        return back()->with('success', match ($reason) {
            'cached' => 'Prompt Phase 1 và nội dung bài không đổi — dùng lại phản hồi đã lưu, không gọi Haiku.',
            'replayed' => 'Thao tác này đã chạy trước đó — không gọi model lần nữa.',
            default => 'Đã lưu phản hồi Haiku theo prompt Phase 1 — chưa kiểm nội dung.',
        });
    }

    public function reset(string $id)
    {
        $this->ownedProject($id);

        [$done, $reason] = $this->videoProjectService->resetInspiration($id);

        return $done
            ? back()->with('success', $reason === 'pending' ? self::CANCELLED : 'Đã reset — bấm Gọi Haiku để chạy lại.')
            : back()->with('error', $reason);
    }
}
