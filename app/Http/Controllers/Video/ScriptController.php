<?php

namespace App\Http\Controllers\Video;

use App\Services\Video\ScriptSourceService;
use App\Services\Video\ScriptWriterService;
use Illuminate\Http\Request;

final class ScriptController extends VideoController
{
    public function run(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $request->validate(['operation_id' => ['required', 'uuid']], ['operation_id.*' => 'Thiếu mã thao tác — tải lại trang.']);
        [$stage, $reason] = app(ScriptWriterService::class)->run($id, $request->boolean('rewrite'), (string) $data['operation_id'], $this->requester());

        if ($stage === null) {
            return back()->with('error', $reason);
        }

        if ($reason === 'queued') {
            return $this->queued($request, $id, $stage, 'viết kịch bản', route('video-projects.anchor', $id).'#scriptPanel');
        }

        $panel = redirect()->to(route('video-projects.anchor', ['id' => $id, 'script' => $stage->id]).'#scriptPanel');

        return match ($reason) {
            'cached' => $panel->with('success', 'Nguồn, prompt và brief không đổi — dùng lại bản kịch bản đã lưu, không gọi model.'),
            'replayed' => $panel->with('success', 'Thao tác này đã chạy trước đó — không gọi model lần nữa.'),
            default => $panel->with('success', 'Đã lưu bản kịch bản — xem kết quả kiểm rồi duyệt.'),
        };
    }

    public function reset(string $id)
    {
        $this->ownedProject($id);

        [$done, $reason] = app(ScriptWriterService::class)->reset($id);

        return $done
            ? back()->with('success', $reason === 'pending' ? self::CANCELLED : 'Đã reset lượt viết kịch bản bị kẹt — bản đã duyệt giữ nguyên.')
            : back()->with('error', $reason);
    }

    public function brief(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $request->validate([
            'video_length' => ['nullable', 'integer'],
            'aspect_ratio' => ['nullable', 'string', 'max:10'],
            'platform' => ['nullable', 'string', 'max:200'],
            'tone_notes' => ['nullable', 'string', 'max:3000'],
        ], [
            'video_length.*' => 'Thời lượng phải là số nguyên giây.',
            'aspect_ratio.*' => 'Tỷ lệ khung không hợp lệ.',
            'platform.*' => 'Nền tảng quá dài.',
            'tone_notes.*' => 'Ghi chú giọng điệu quá dài.',
        ]);
        [$done, $reason] = app(ScriptWriterService::class)->saveBrief($id, $data);

        return $done
            ? redirect()->to(route('video-projects.anchor', $id).'#scriptPanel')->with('success', 'Đã lưu brief kịch bản của dự án — lượt viết sau dùng brief này; bản đã viết giữ nguyên.')
            : back()->with('error', $reason);
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
            'stage_id.*' => 'Thiếu bản kịch bản cần duyệt — tải lại trang.',
            'content_hash.*' => 'Thiếu hash nội dung — tải lại trang.',
            'selection_version.*' => 'Thiếu phiên bản lựa chọn — tải lại trang.',
            'operation_id.*' => 'Thiếu mã thao tác — tải lại trang.',
        ]);
        [$decision, $reason] = app(ScriptSourceService::class)->approve(
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

        return redirect()->route('video-projects.locations', $id)
            ->with('success', $reason === 'replayed'
                ? 'Lần duyệt này đã được ghi trước đó — không ghi thêm.'
                : 'Đã duyệt và chọn bản kịch bản này làm nguồn cho các công đoạn sau.');
    }
}
