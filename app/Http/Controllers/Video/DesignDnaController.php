<?php

namespace App\Http\Controllers\Video;

use App\Services\Video\DesignDnaService;
use Illuminate\Http\Request;

final class DesignDnaController extends VideoController
{
    public function run(Request $request, string $id)
    {
        $this->ownedProject($id);
        $data = $request->validate(['operation_id' => ['required', 'uuid']], ['operation_id.*' => 'Thiếu mã thao tác — tải lại trang.']);
        [$stage, $reason] = app(DesignDnaService::class)->run($id, $request->boolean('regenerate'), (string) $data['operation_id'], $this->requester());

        if ($stage === null) {
            return back()->with('error', $reason);
        }

        if ($reason === 'queued') {
            return $this->queued($request, $id, $stage, 'tạo DNA', route('video-projects.anchor', $id));
        }

        return back()->with('success', match ($reason) {
            'cached' => 'Prompt Phase 2 và kết quả Phase 1 không đổi — dùng lại DNA đã lưu, không gọi model.',
            'replayed' => 'Thao tác này đã chạy trước đó — không gọi model lần nữa.',
            default => 'Đã lưu Design DNA theo prompt Phase 2.',
        });
    }

    public function reset(string $id)
    {
        $this->ownedProject($id);
        [$done, $reason] = app(DesignDnaService::class)->reset($id);

        return $done
            ? back()->with('success', $reason === 'pending' ? self::CANCELLED : 'Đã reset lượt tạo DNA bị kẹt.')
            : back()->with('error', $reason);
    }
}
