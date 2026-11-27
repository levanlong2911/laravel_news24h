<?php

namespace App\Http\Controllers\Video;

use App\Services\Video\LocationReferenceService;
use App\Services\Video\LocationSourceService;
use App\Services\Video\LocationWriterService;
use App\Services\Video\SceneSourceService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class LocationController extends VideoController
{
    public function index(Request $request, string $id)
    {
        $this->ownedProject($id);
        $stage = $request->query('stage');

        return view('video-projects.locations', $this->chrome() + [
            'id' => $id,
            'step' => app(LocationWriterService::class)->panel($id, is_string($stage) && Str::isUuid($stage) ? $stage : null),
            'scenesSupported' => app(SceneSourceService::class)->supports($id),
        ]);
    }

    public function run(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'effort' => ['nullable', 'string', Rule::in((array) config('video.superyacht.locations.efforts'))],
        ], ['operation_id.*' => 'Thiếu mã thao tác — tải lại trang.', 'effort.*' => 'Mức effort không hợp lệ.']);
        [$stage, $reason] = app(LocationWriterService::class)->run($id, $request->boolean('rewrite'), (string) $data['operation_id'], isset($data['effort']) ? (string) $data['effort'] : null, $this->requester());

        if ($stage === null) {
            return back()->with('error', $reason);
        }

        if ($reason === 'queued') {
            return $this->queued($request, $id, $stage, 'tạo địa điểm và bối cảnh', route('video-projects.locations', $id));
        }

        return redirect()->route('video-projects.locations', ['id' => $id, 'stage' => $stage->id])->with('success', match ($reason) {
            'cached' => 'Nguồn và prompt không đổi — dùng lại bộ địa điểm và bối cảnh đã lưu, không gọi model.',
            'replayed' => 'Thao tác này đã chạy trước đó — không gọi model lần nữa.',
            default => 'Đã lưu địa điểm và bối cảnh — xem kết quả kiểm và prompt rồi render.',
        });
    }

    public function reset(string $id)
    {
        $this->ownedProject($id);

        [$done, $reason] = app(LocationWriterService::class)->reset($id);

        if (! $done) {
            return back()->with('error', $reason);
        }

        return back()->with('success', $reason === 'pending' ? self::CANCELLED : 'Đã reset lượt tạo địa điểm và bối cảnh bị kẹt.');
    }

    public function render(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $request->validate([
            'request_id' => ['required', 'regex:/^(P|BG)[0-9]{2}$/'],
            'image_id' => ['nullable', 'uuid'],
            'stage_id' => ['required_without:image_id', 'nullable', 'uuid'],
            'content_hash' => ['required_without:image_id', 'nullable', 'string', 'max:80'],
            'prompts_hash' => ['required_without:image_id', 'nullable', 'string', 'max:80'],
            'prompt_sha256' => ['required_without:image_id', 'nullable', 'string', 'max:80'],
            'selection_version' => ['required_without:image_id', 'nullable', 'integer', 'min:0'],
            'provider_model' => ['nullable', 'string', 'max:120'],
            'quality' => ['nullable', 'string', 'max:20'],
            'size' => ['nullable', 'string', 'max:20'],
            'aspect_ratio' => ['nullable', 'string', 'max:10'],
            'image_size' => ['nullable', 'string', 'max:10'],
            'variations' => ['nullable', 'integer', 'min:1'],
            'operation_id' => ['required', 'uuid'],
        ], ['*' => 'Thiết lập render không hợp lệ — tải lại trang.']);
        $service = app(LocationReferenceService::class);
        [$image, $reason] = isset($data['image_id'])
            ? $service->retry($id, (string) $data['image_id'], $this->requester())
            : $service->render($id, (string) auth()->user()?->name, (string) $data['request_id'], $data, (string) $data['operation_id'], [
                'stage_id' => (string) $data['stage_id'],
                'content_hash' => (string) $data['content_hash'],
                'prompts_hash' => (string) $data['prompts_hash'],
                'prompt_sha256' => (string) $data['prompt_sha256'],
                'selection_version' => (int) $data['selection_version'],
            ], auth()->id(), $this->requester());

        return $image === null
            ? back()->with('error', $reason)
            : redirect()->to(route('video-projects.locations', $id).'#ref_'.$data['request_id'])->with('success', match ($reason) {
                'already_exists' => 'Ảnh với đúng thiết lập này đã có — không render lại.',
                'queued' => "Đã đưa render {$data['request_id']} vào hàng đợi — chạy nền trên server; đóng trang vẫn chạy tiếp, trang tự cập nhật khi xong.",
                default => 'Render xong — duyệt ảnh cho bối cảnh này.',
            });
    }

    public function choose(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $request->validate([
            'request_id' => ['required', 'regex:/^(P|BG)[0-9]{2}$/'],
            'artifact_id' => ['required', 'uuid'],
        ], ['*' => 'Thiếu dữ liệu chọn ảnh — tải lại trang.']);
        $actor = auth()->id();
        [$done, $reason] = app(LocationSourceService::class)->choose($id, (string) $data['request_id'], (string) $data['artifact_id'], $actor === null ? null : (string) $actor);

        return $done
            ? redirect()->to(route('video-projects.locations', $id).'#ref_'.$data['request_id'])->with('success', 'Đã duyệt ảnh cho '.$data['request_id'].'.')
            : back()->with('error', $reason);
    }

    /**
     * @return \Illuminate\Http\RedirectResponse the standard plate of a place chosen or changed. The request names the place, the plate and what the page showed — the set, its content hash, its prompt set and the selection version; the set on screen is put in use first when it is not, and nothing is written when any of them no longer holds. The role of a plate is never read from the request
     */
    public function standard(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $request->validate([
            'location' => ['required', 'regex:/^LOC[0-9]{2}$/'],
            'plate' => ['required', 'regex:/^BG[0-9]{2}$/'],
            'stage_id' => ['required', 'uuid'],
            'content_hash' => ['required', 'string', 'max:80'],
            'prompts_hash' => ['required', 'string', 'max:80'],
            'selection_version' => ['required', 'integer', 'min:0'],
            'operation_id' => ['required', 'uuid'],
        ], ['*' => 'Thiếu dữ liệu chọn ảnh chuẩn — tải lại trang.']);
        $actor = auth()->id();
        [$done, $reason, $released] = app(LocationSourceService::class)->chooseStandard(
            $id,
            (string) $data['location'],
            (string) $data['plate'],
            (int) $data['selection_version'],
            $actor === null ? null : (string) $actor,
            ['stage_id' => (string) $data['stage_id'], 'content_hash' => (string) $data['content_hash'], 'prompts_hash' => (string) $data['prompts_hash']],
            (string) $data['operation_id'],
        );

        if (! $done) {
            return back()->with('error', $reason);
        }

        return redirect()->to(route('video-projects.locations', $id).'#ref_'.$data['plate'])->with('success', $reason === 'unchanged'
            ? "{$data['plate']} đã là ảnh chuẩn của {$data['location']} — không đổi gì."
            : "Đã chọn {$data['plate']} làm ảnh chuẩn của {$data['location']}.".($released === [] ? '' : ' Lựa chọn ảnh của '.implode(', ', $released).' đã được gỡ khỏi bộ đang dùng; ảnh, lượt render và chi phí vẫn giữ.'));
    }
}
