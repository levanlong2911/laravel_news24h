<?php

namespace App\Http\Controllers\Video;

use App\Services\Video\RenderVideoService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class RenderVideoController extends VideoController
{
    public function action(Request $request, RenderVideoService $videos, string $id, string $shot, string $action)
    {
        $this->ownedProject($id);
        if (($refused = $this->refusedClipStep()) !== null) { return $refused; }
        abort_unless($videos->usesShotClipFlow($id), 404);

        if ($action !== 'input' && $videos->usesClipExecution($id)) {
            return $this->clipAction($request, $videos, $id, $shot, $action);
        }

        $data = $request->validate(match ($action) {
            'prompt' => ['operation_id' => 'required|uuid', 'confirm_cost' => 'accepted', 'regenerate' => 'nullable|boolean'],
            'prompt-approve' => ['stage_id' => 'required|uuid'],
            'input' => ['mode' => 'required|in:keyframe,references', 'include' => 'nullable|array', 'include.*' => 'uuid', 'expected_revision' => 'nullable|integer|min:0', 'expected_source_stage' => 'nullable|uuid', 'expected_source_hash' => 'nullable|string|max:100'],
            default => abort(404),
        });
        $requester = auth()->id() === null ? null : ['id' => (string) auth()->id(), 'name' => (string) auth()->user()?->name];
        $page = route('video-projects.render-video', $id);
        $answer = static fn (bool $ok, string $message) => $request->expectsJson()
            ? response()->json(['ok' => $ok, 'message' => $message])
            : redirect()->to($page)->with($ok ? 'success' : 'error', $message);
        $result = $videos->action($id, $shot, $action, $data, auth()->id() === null ? null : (string) auth()->id(), $requester, false, null, $this->sentFields($request), false);

        return $answer($result['ok'], $result['message']);
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response one action on a shot of the Render video screen of a release with an execution policy — its settings, its video prompt, a render, a poll, or a decision on a render — answered with the part of that shot and the notice when the page script sent it (vpAjaxPage), else by going back to the shot on the page. Changing a choice never writes a prompt or renders; a save made from an older revision of the settings is refused with 409 and the shot read again. An action that names the shot plan its page was drawn from (expected_source_stage, expected_source_hash: every save and every prompt run must) is held to it before anything else, a form that fails validation included: from a page drawn for another approved shot plan it is refused with 409 and the notice alone — the shot is not drawn again as if the page were current, nothing is written or sent to a model, the person reloads the page
     */
    private function clipAction(Request $request, RenderVideoService $videos, string $id, string $shot, string $action)
    {
        $note = 'nullable|string|max:'.\App\Services\Video\MotoClipSelectionService::NOTE_MAX_CHARS;
        $rules = match ($action) {
            'settings' => ['expected_revision' => 'required|integer|min:0', 'expected_source_stage' => 'required|uuid', 'expected_source_hash' => 'required|string|max:100', 'motion_method' => 'nullable|in:'.implode(',', \App\Video\Moto\MotoClipPlanBuilder::MOTIONS), 'input_mode' => 'nullable|in:'.implode(',', \App\Video\Moto\MotoClipPlanBuilder::INPUTS),
                'model' => 'nullable|string|max:100', 'resolution' => 'nullable|string|max:20', 'audio_policy' => 'nullable|in:'.implode(',', \App\Video\Moto\MotoClipPlanBuilder::AUDIO_POLICIES), 'images' => 'nullable|array', 'images.*' => 'uuid', 'images_sent' => 'nullable|boolean',
                'crop_at_ms' => 'nullable|integer|min:1', 'crop_x0' => 'nullable|integer|min:0', 'crop_y0' => 'nullable|integer|min:0', 'crop_w0' => 'nullable|integer|min:1', 'crop_h0' => 'nullable|integer|min:1',
                'crop_x1' => 'nullable|integer|min:0', 'crop_y1' => 'nullable|integer|min:0', 'crop_w1' => 'nullable|integer|min:1', 'crop_h1' => 'nullable|integer|min:1'],
            'render' => ['expected_clip_hash' => 'required|string|max:100', 'expected_settings' => 'required|integer|min:0', 'expected_intent_version' => 'required|integer|min:0', 'operation_id' => 'required|uuid',
                'model_id' => 'required|string|max:100', 'resolution' => 'required|string|max:20', 'aspect_ratio' => 'required|string|max:10', 'duration_seconds' => 'required|integer|min:1', 'confirm_cost' => 'accepted'],
            'approve' => ['render_id' => 'required|uuid', 'expected_intent_version' => 'required|integer|min:0', 'operation_id' => 'required|uuid', 'review_key' => 'nullable|string|size:64'],
            'reject' => ['render_id' => 'required|uuid', 'operation_id' => 'required|uuid', 'faults' => 'required|array|min:1', 'faults.*' => 'in:'.implode(',', array_keys(\App\Services\Video\MotoClipSelectionService::REVIEW_GROUPS)), 'note' => $note, 'marks' => 'nullable|string|max:200', 'review_key' => 'nullable|string|size:64'],
            'revoke' => ['render_id' => 'required|uuid', 'expected_intent_version' => 'required|integer|min:0', 'operation_id' => 'required|uuid', 'note' => $note],
            'poll' => ['render_id' => 'required|uuid'],
            'prompt' => ['operation_id' => 'required|uuid', 'confirm_cost' => 'accepted', 'regenerate' => 'nullable|boolean', 'expected_source_stage' => 'required|uuid', 'expected_source_hash' => 'required|string|max:100'],
            'prompt-approve' => ['stage_id' => 'required|uuid'],
            default => abort(404),
        };
        $requester = auth()->id() === null ? null : ['id' => (string) auth()->id(), 'name' => (string) auth()->user()?->name];
        $reviewer = auth()->id() === null ? null : (string) auth()->id();
        $page = route('video-projects.render-video', $id).'#clip-'.$shot;
        $part = static fn (string $key, string $text, int $status = 200, ?array $motoClips = null) => response(view('video-projects.partials.moto-reference-flash', ['flashId' => 'clipsFlash', $key => $text])->render()
            .($motoClips !== null ? view('video-projects.partials.moto-clips-panel', ['id' => $id, 'motoClips' => $motoClips, 'fragment' => true])->render() : ''), $status)->header('X-Vp-Fragment', '1');
        $pageSource = static fn (): ?array => is_string($request->input('expected_source_stage')) && is_string($request->input('expected_source_hash')) && $request->filled('expected_source_stage') && $request->filled('expected_source_hash')
            ? ['stage_id' => (string) $request->input('expected_source_stage'), 'content_hash' => (string) $request->input('expected_source_hash')]
            : null;

        if ($pageSource() !== null && ($changed = $videos->sourceProblem($id, $pageSource())) !== null) {
            return $this->wantsReferencePart($request) ? $part('error', $changed, 409) : redirect()->to($page)->with('error', $changed);
        }

        try {
            $data = $request->validate($rules);
        } catch (ValidationException $e) {
            if (! $this->wantsReferencePart($request)) {
                throw $e;
            }

            return $part('error', implode(' ', Arr::flatten($e->errors())), 200, $videos->shotPanel($id, $shot));
        }

        if ($action === 'render') {
            ignore_user_abort(true);
            @set_time_limit((int) config('video.omni.timeout') + 60);
        }

        $result = $videos->action($id, $shot, $action, $data, $reviewer, $requester, true, $pageSource(), $this->sentFields($request), $this->wantsReferencePart($request));
        $key = $result['ok'] ? 'success' : 'error';

        return $this->wantsReferencePart($request) ? $part($key, $result['message'], $result['status'], $result['panel']) : redirect()->to($page)->with($key, $result['message']);
    }

    /** @return array{crop_at_ms: bool, images_sent: bool} whether the crop form was sent (crop_at_ms filled) and whether the settings form showed the reference images (images_sent true), as the request carries them */
    private function sentFields(Request $request): array
    {
        return ['crop_at_ms' => $request->filled('crop_at_ms'), 'images_sent' => $request->boolean('images_sent')];
    }

    /** @return \Illuminate\Http\Response the strip of review stills of one render of a shot of this project, as a part the page puts beside its player; a render that has none says why */
    public function review(RenderVideoService $videos, string $id, string $shot, string $render)
    {
        $this->ownedProject($id);
        if (($refused = $this->refusedClipStep()) !== null) { return $refused; }
        $review = $videos->review($id, $shot, $render);
        abort_if($review === null, 404);

        return response(view('video-projects.partials.moto-clip-strip', ['id' => $id, 'strip' => $review['strip'], 'error' => $review['error']])->render());
    }

    /** @return \Symfony\Component\HttpFoundation\Response one still of a strip of a render of this project, streamed only when the strip is of that render's file and the still is the bytes it recorded */
    public function still(RenderVideoService $videos, string $id, string $render, string $key, string $index)
    {
        $this->ownedProject($id);
        if (($refused = $this->refusedClipStep()) !== null) { return $refused; }
        $still = $videos->still($id, $render, $key, (int) $index);
        abort_if($still === null, 404);

        $disk = app(\Illuminate\Contracts\Filesystem\Factory::class)->disk($still['disk']);
        abort_unless($disk->exists($still['path']), 404);

        return $disk->response($still['path'], basename($still['path']), ['Content-Type' => $still['mime'], 'Cache-Control' => 'private, max-age=86400']);
    }

    public function index(RenderVideoService $videos, string $id)
    {

        $this->ownedProject($id);
        if (($refused = $this->refusedClipStep()) !== null) { return $refused; }

        $page = $videos->page($id);

        return view($page['view'], $this->chrome() + $page['data']);
    }

    /** Gui render mot clip theo shot: kiem hash trang dang mo, ma thao tac va thiet lap model; tra trang thai cho man. */
    private function renderShotClip(Request $request, RenderVideoService $videos, string $id, string $shotId)
    {
        // Trinh duyet dong lai khong duoc lam dung mot yeu cau da toi provider.
        ignore_user_abort(true);
        @set_time_limit((int) config('video.omni.timeout') + 60);

        try {
            $data = $request->validate([
                'model_id' => ['required', 'string'],
                'resolution' => ['required', 'string'],
                'aspect_ratio' => ['required', 'string'],
                'duration_seconds' => ['required', 'integer', 'min:1'],
                'expected_intent_version' => ['required', 'integer', 'min:0'],
                'operation_id' => ['required', 'uuid'],
                'expected_clip_hash' => ['required', 'string'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->refusedShotClip(implode(' ', $e->validator->errors()->all()), $id, $shotId);
        } catch (\Throwable $e) {
            return $this->refusedShotClip($this->dispatchRefusal($e->getMessage()), $id, $shotId);
        }

        $clip = $videos->sceneRender($id, $shotId, RenderVideoService::SHOT_CLIP, $data, null, true);

        if ($clip['refused'] !== null) {
            return $this->refusedShotClip($this->dispatchRefusal($clip['refused']), $id, $shotId);
        }

        return response()->json($clip['state']);
    }

    /** Duyet ket qua cua mot shot cho buoc final; quyet dinh gan voi dung file do va phien ban y dinh cua shot. */
    private function approveShotClip(Request $request, RenderVideoService $videos, string $id, string $shotId)
    {
        $data = $request->validate([
            'render_id' => ['required', 'uuid'],
            'expected_intent_version' => ['required', 'integer', 'min:0'],
            'operation_id' => ['required', 'uuid'],
        ]);

        $result = $videos->sceneSelect($id, $shotId, RenderVideoService::SHOT_CLIP, $data, (string) auth()->id());

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    private function refusedShotClip(string $reason, string $id, string $shotId)
    {
        \Illuminate\Support\Facades\Log::warning('Clip shot bị từ chối trước khi gọi provider', ['project_id' => $id, 'shot_id' => $shotId, 'reason' => $reason]);

        return response()->json(['state' => 'idle', 'reason' => $reason, 'note' => $reason, 'poll_url' => null, 'file_url' => null, 'meta' => '', 'idle_stage' => 'chờ — chưa gửi render'], 422);
    }

    /** @return string what a person reads of a render that was refused before it reached the provider: the conflicts of the dispatch named in words, any other reason as it was given */
    private function dispatchRefusal(string $message): string
    {
        return match ($message) {
            'shot_dispatch_conflict' => 'Clip đã thay đổi ở thao tác khác. Tải lại trang trước khi render.',
            'shot_dispatch_operation_conflict' => 'Mã thao tác render đã được dùng cho một yêu cầu khác.',
            'shot_dispatch_replay_missing' => 'Không còn tìm thấy lượt render của thao tác trước.',
            default => $message,
        };
    }

    /**
     * Mot luot: sinh shot cho scene (neu chua co), dong bang anh da duyet lam nguon,
     * tao hang render roi submit ngay.
     *
     * Khong cho san hang `queued` chua ai dinh gui: mot o nhu the la mot o treo.
     */
    /**
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    private function renderFilmSegment(Request $request, RenderVideoService $videos, string $id, string $sceneId)
    {
        try {
            $data = $request->validate([
                'model_id' => ['required', 'string'],
                'resolution' => ['required', 'string'],
                'aspect_ratio' => ['required', 'string'],
                'duration_seconds' => ['required', 'integer', 'min:1'],
                'expected_intent_version' => ['required', 'integer', 'min:0'],
                'operation_id' => ['required', 'uuid'],
            ]);
        } catch (ValidationException $e) {
            return $this->clipRefused($request, implode(' ', $e->validator->errors()->all()), $id, $sceneId);
        }

        $clip = $videos->sceneRender($id, $sceneId, RenderVideoService::FILM_SEGMENT, $data, $this->actorId(), $request->expectsJson());

        if ($clip['refused'] !== null) {
            $reason = $clip['refused'];
            [$code, $detail] = array_pad(explode('|', $reason, 2), 2, null);

            return $this->clipRefused($request, match (true) {
                $detail === 'shot_dispatch_conflict' => 'Clip đã thay đổi ở thao tác khác. Tải lại trang trước khi render.',
                $code === 'film_segment_blocked' => (string) $detail,
                default => $this->sceneKeyframeMessage($reason),
            }, $id, $sceneId);
        }

        if ($clip['state'] !== null) {
            return response()->json($clip['state']);
        }

        return back()->with($clip['ok'] ? 'status' : 'error', 'Clip: ' . $clip['reason']);
    }

    public function sceneRender(Request $request, RenderVideoService $videos, string $id, string $sceneId)
    {
        $this->ownedProject($id);
        if (($refused = $this->refusedClipStep()) !== null) { return $refused; }

        // Luong clip theo shot: `sceneId` la ma shot cua ke hoach shot da duyet.
        if ($videos->usesShotClipFlow($id)) {
            return $this->renderShotClip($request, $videos, $id, $sceneId);
        }

        if ($videos->rendersFilmSegment($id, $sceneId)) {
            return $this->renderFilmSegment($request, $videos, $id, $sceneId);
        }

        // MOI thu nam trong try, ke ca validate va tra cuu scene. Mot ngoai le lot ra
        // ngoai se thanh trang loi HTML, ma man hinh lai doc JSON — luc do no that bai
        // IM LANG: hang tu reset, khong ai biet vi sao.
        try {
            $data = $this->form->validate($request, 'SceneClipRenderForm');
        } catch (ValidationException $e) {
            return $this->clipRefused($request, implode(' ', $e->validator->errors()->all()), $id, $sceneId);
        } catch (Throwable $e) {
            return $this->clipRefused($request, $this->dispatchRefusal($e->getMessage()), $id, $sceneId);
        }

        $clip = $videos->sceneRender($id, $sceneId, RenderVideoService::SCENE_CLIP, $data, null, $request->expectsJson());

        if ($clip['refused'] !== null) {
            return $this->clipRefused($request, $this->dispatchRefusal($clip['refused']), $id, $sceneId);
        }

        if ($clip['state'] !== null) {
            return response()->json($clip['state']);
        }

        return back()->with($clip['ok'] ? 'status' : 'error', 'Clip: ' . $clip['reason']);
    }

    public function sceneSelect(Request $request, RenderVideoService $videos, string $id, string $shotId)
    {
        $this->ownedProject($id);
        if (($refused = $this->refusedClipStep()) !== null) { return $refused; }

        // Luong clip theo shot: `shotId` la ma shot; chon clip la duyet ket qua do cho buoc final.
        if ($videos->usesShotClipFlow($id)) {
            return $this->approveShotClip($request, $videos, $id, $shotId);
        }

        $data = $request->validate([
            'render_id' => ['required', 'uuid'],
            'expected_intent_version' => ['required', 'integer', 'min:0'],
            'operation_id' => ['required', 'uuid'],
        ]);
        $result = $videos->sceneSelect($id, $shotId, RenderVideoService::SCENE_CLIP, $data, $this->actorId());

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /**
     * Mot lan bam bi tu choi van phai NOI RA ly do — va de lai dau vet doc duoc.
     * Khong co dong log nay thi lan hong nao cung chi con mot o tu reset.
     */
    private function clipRefused(Request $request, string $reason, string $id, string $sceneId)
    {
        Log::warning('Clip bi tu choi truoc khi goi provider', [
            'project_id' => $id,
            'scene_id' => $sceneId,
            'reason' => $reason,
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'state' => 'idle',
                'reason' => $reason,
                'note' => $reason,
                'poll_url' => null,
                'file_url' => null,
                'meta' => '',
            ], 422);
        }

        return back()->with('error', 'Clip: ' . $reason);
    }

    /**
     * Clip nam tren disk rieng chu khong phai public, nen no chi ra khoi may qua
     * day — sau khi da kiem du an va kiem hang render thuoc du an do.
     */
    public function sceneFile(RenderVideoService $videos, string $id, string $render)
    {
        $this->ownedProject($id);
        if (($refused = $this->refusedClipStep()) !== null) { return $refused; }

        $file = $videos->clipFile($id, $render);
        $disk = app(\Illuminate\Contracts\Filesystem\Factory::class)->disk($file['disk']);
        $path = $file['path'];

        abort_if($path === '' || ! $disk->exists($path), 404);

        $headers = [
            'Content-Type' => 'video/mp4',
            // Trinh duyet doi seek duoc moi ve duoc khung hinh dau; khong noi minh
            // chap nhan range thi the <video> chi hien mot o den.
            'Accept-Ranges' => 'bytes',
        ];

        if ($disk instanceof \Illuminate\Filesystem\FilesystemAdapter && $disk->getAdapter() instanceof \League\Flysystem\Local\LocalFilesystemAdapter) {
            return new \Symfony\Component\HttpFoundation\BinaryFileResponse($disk->path($path), 200, $headers, false, 'inline', false, false);
        }

        return $disk->response($path, basename($path), $headers);
    }

    public function scenePoll(RenderVideoService $videos, string $id, string $render)
    {
        $this->ownedProject($id);
        if (($refused = $this->refusedClipStep()) !== null) { return $refused; }

        $poll = $videos->scenePoll($id, $render, request()->expectsJson());

        if ($poll['state'] !== null) {
            return response()->json($poll['state']);
        }

        return back()->with($poll['ok'] ? 'status' : 'error', 'Clip: ' . $poll['reason']);
    }
}
