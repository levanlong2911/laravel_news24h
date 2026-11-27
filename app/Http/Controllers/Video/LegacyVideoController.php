<?php

namespace App\Http\Controllers\Video;

use App\Services\VideoProjectService;
use App\Services\Video\VesselDesignService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class LegacyVideoController extends VideoController
{
    public function renderDesignImage(string $id, string $imageId)
    {
        $this->ownedProject($id);

        [$image, $reason] = $this->videoProjectService->renderDesignImage($id, $imageId);

        if ($image === null) {
            return back()->with('error', $this->referenceMessage($reason));
        }

        return back()->with(
            $reason === 'rendered' ? 'success' : 'error',
            $this->renderOutcome($reason, $image),
        );
    }

    public function approveReference(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $this->form->validate($request, 'AnchorApproveForm');

        [$done, $reason] = $this->videoProjectService->approveReference(
            $id,
            (string) $data['artifact_id'],
            auth()->id(),
        );

        return back()->with($done ? 'success' : 'error', $this->anchorMessage($reason));
    }

    public function deleteReference(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $this->form->validate($request, 'AnchorApproveForm');

        [$done, $reason] = $this->videoProjectService->deleteReference($id, (string) $data['artifact_id']);

        [$code, $detail] = array_pad(explode('|', $reason, 2), 2, '');

        return back()->with($done ? 'success' : 'error', match ($code) {
            'deleted' => __('messages.reference_deleted'),
            'artifact_in_use' => __('messages.reference_delete_in_use'),
            'artifact_used_by' => __('messages.reference_delete_used_by', ['users' => $detail]),
            'render_in_flight' => __('messages.reference_delete_in_flight'),
            'file_not_deleted' => __('messages.reference_delete_file_failed'),
            'delete_failed' => __('messages.reference_delete_rolled_back'),
            'file_left_in_trash' => __('messages.reference_delete_file_in_trash', ['trash' => $detail]),
            default => $this->anchorMessage($reason),
        });
    }

    public function lockReferences(string $id)
    {
        $this->ownedProject($id);

        [$done, $reason] = app(VesselDesignService::class)->lockReferences($id, auth()->id());

        return $done
            ? redirect()->route('video-projects.anchor', $id)->with('success', $this->anchorMessage($reason))
            : back()->with('error', $this->anchorMessage($reason));
    }

    public function writeReferencePrompt(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $this->form->validate($request, 'ReferenceImageForm', 'prompt');


        [$stage, $reason, $violations] = $this->videoProjectService->writeReferencePrompt($id, $data);


        $message = $this->referenceMessage($reason);

        if ($violations !== []) {
            $message .= ' Chi tiết: '.implode(' | ', $violations);
        }

        return redirect()
            ->route('video-projects.reference', $id)
            ->withInput(['view' => $data['view']])
            ->with($stage === null ? 'error' : 'success', $message);
    }

    public function writeReferencePlan(Request $request, string $id)
    {
        $this->ownedProject($id);

        [$stage, $reason, $violations] = $this->videoProjectService->writeReferencePlan($id, auth()->id(), $request->boolean('force'));
        $message = $this->referenceMessage(explode('|', $reason, 2)[0]);

        if ($violations !== []) {
            $message .= ' Chi tiết: '.implode(' | ', $violations);
        }

        return redirect()->to(route('video-projects.reference', $id).'#referencePlan')
            ->with($stage === null ? 'error' : 'success', $message);
    }

    public function decideReferencePlanItem(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $this->form->validate($request, 'ReferenceImageForm', 'plan_decision');
        [$ok, $reason] = $this->videoProjectService->decideReferencePlanItem(
            $id, auth()->id(), (string) $data['reference_plan_stage_id'], (string) $data['request_id'], (string) $data['status'],
        );

        return redirect()->to(route('video-projects.reference', $id).'#referencePlan')
            ->with($ok ? 'success' : 'error', $ok ? 'Đã lưu quyết định cho mục Reference.' : $this->referenceMessage(explode('|', $reason, 2)[0]));
    }

    public function renderReferencePlanItem(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $this->form->validate($request, 'ReferenceImageForm', 'plan_render');
        [$image, $reason] = $this->videoProjectService->renderReferencePlanItem($id, (string) auth()->user()?->name, $data);

        if ($image === null) {
            return redirect()->to(route('video-projects.reference', $id).'#referencePlan')
                ->with('error', $this->referenceMessage(explode('|', $reason, 2)[0]));
        }

        return redirect()->to(route('video-projects.reference', $id).'#referencePlan')
            ->with(in_array($reason, ['rendered', 'already_exists'], true) ? 'success' : 'error', $this->renderOutcome($reason, $image));
    }

    public function referenceSheetPreview(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $this->form->validate($request, 'ReferenceImageForm', 'sheet_preview');

        [$sheet, $reason] = $this->videoProjectService->referenceSheetPreview(
            $id,
            (string) $data['sheet_state'],
            (string) $data['environment'],
        );

        if ($sheet === null) {
            return response()->json(['ok' => false, 'message' => $this->referenceMessage($reason)]);
        }

        return response()->json([
            'ok' => true,
            'prompt' => $sheet['prompt'],
            'prompt_sha256' => $sheet['prompt_sha256'],
            'length' => mb_strlen($sheet['prompt']),
        ]);
    }

    public function approveEnvironment(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $this->form->validate($request, 'AnchorApproveForm');

        [$done, $reason] = $this->videoProjectService->approveEnvironmentReference(
            $id,
            (string) $data['artifact_id'],
            auth()->id(),
        );

        if ($request->expectsJson()) {
            return response()->json(['ok' => $done, 'message' => $this->anchorMessage($reason)]);
        }

        return back()->with($done ? 'success' : 'error', $this->anchorMessage($reason));
    }

    public function environment(Request $request, string $id)
    {
        $this->ownedProject($id);

        if ($request->isMethod('post')) {
            return $this->createEnvironment($request, $id);
        }

        $data = $this->videoProjectService->environmentPageData($id);

        if ($data === null) {
            return redirect()->route('video-projects.index')
                ->with('error', __('messages.project_not_found'));
        }

        return view('video-projects.environment', $this->chrome() + $data);
    }

    private function createEnvironment(Request $request, string $id)
    {
        try {
            $data = $this->form->validate($request, 'EnvironmentImageForm');
        } catch (InvalidArgumentException $e) {
            Log::error('environment: registry model hong khi validate', ['error' => $e->getMessage()]);

            return $request->expectsJson()
                ? response()->json(['ok' => false, 'message' => $this->anchorMessage('environment_media_models_broken')])
                : back()->with('error', $this->anchorMessage('environment_media_models_broken'));
        }

        [$image, $reason] = $this->videoProjectService->renderEnvironmentDirect(
            $id,
            (string) auth()->user()?->name,
            $data,
        );

        if ($image === null) {
            return $request->expectsJson()
                ? response()->json(['ok' => false, 'message' => $this->anchorMessage($reason)])
                : back()->with('error', $this->anchorMessage($reason));
        }

        $level = in_array($reason, ['rendered', 'already_exists'], true) ? 'success' : 'error';

        if ($request->expectsJson()) {
            return response()->json(['ok' => $level === 'success', 'message' => $this->renderOutcome($reason, $image)]);
        }

        return redirect()
            ->route('video-projects.environment', $id)
            ->with($level, $this->renderOutcome($reason, $image));
    }

    public function generateStoryboard(Request $request, string $id)
    {
        $this->ownedProject($id);

        $mode = $request->input('mode', VideoProjectService::STORYBOARD_MODE);

        [$count, $reason] = $this->videoProjectService->generateStoryboard(
            $id,
            auth()->id(),
            $request->boolean('force'),
            is_string($mode) ? $mode : '',
        );

        $message = $count === null ? $this->anchorMessage($reason) : __('messages.'.$reason, ['count' => $count]);

        if (! $request->expectsJson()) {
            return redirect()->to(route('video-projects.anchor', $id).'#storyboard-panel')
                ->with($count === null ? 'error' : 'success', $message);
        }

        return response()->json(['ok' => $count !== null, 'reason' => $reason, 'message' => $message]);
    }

    public function recheckStoryboard(string $id)
    {
        $this->ownedProject($id);

        [$count, $reason] = $this->videoProjectService->recheckStoredStoryboard($id, auth()->id());

        return response()->json([
            'ok' => $count !== null,
            'reason' => $reason,
            'message' => $count === null ? $this->anchorMessage($reason) : __('messages.'.$reason, ['count' => $count]),
        ]);
    }

    public function acceptStoryboardFinding(Request $request, string $id)
    {
        $this->ownedProject($id);

        [$ok, $reason] = $this->videoProjectService->acceptStoryboardFinding(
            $id,
            auth()->id(),
            (int) $request->input('revision'),
            (string) $request->input('finding_id'),
            (string) $request->input('reason'),
        );

        return response()->json([
            'ok' => $ok,
            'reason' => $reason,
            'message' => $ok ? __('messages.storyboard_finding_accepted') : $this->anchorMessage($reason),
        ]);
    }

    public function confirmShotReference(Request $request, string $id, string $sceneId)
    {
        $this->ownedProject($id);

        [$ok, $reason] = $this->videoProjectService->confirmShotReference(
            $id,
            auth()->id(),
            $sceneId,
            (int) $request->input('index'),
            (string) $request->input('artifact_id'),
        );

        return response()->json([
            'ok' => $ok,
            'reason' => $reason,
            'message' => __('messages.'.($ok ? 'reference_confirmed' : $reason)),
        ]);
    }

    public function approveStoryboard(Request $request, string $id)
    {
        $this->ownedProject($id);

        [$revision, $reason] = $this->videoProjectService->approveStoryboard(
            $id,
            auth()->id(),
            (int) $request->input('revision'),
        );

        return response()->json([
            'ok' => $revision !== null,
            'reason' => $reason,
            'message' => match ($reason) {
                'ok' => __('messages.storyboard_approved', ['revision' => $revision]),
                'storyboard_already_approved' => __('messages.storyboard_already_approved', ['revision' => $revision]),
                'storyboard_not_latest' => __('messages.storyboard_not_latest'),
                'project_not_found' => __('messages.project_not_found'),
                'storyboard_findings_blocking', 'storyboard_findings_open', 'storyboard_source_changed' => __('messages.'.$reason),
                default => $this->sceneKeyframeMessage($reason),
            },
        ]);
    }

    public function sceneKeyframePreview(Request $request, string $id, string $sceneId)
    {
        $this->ownedProject($id);

        [$preview, $reason] = $this->videoProjectService->sceneImagePreview(
            $id,
            $this->actorId(),
            $sceneId,
            $request->filled('provider_model') ? $request->only(['provider_model', 'size', 'quality']) : null,
        );

        return $this->keyframeJson($preview, $reason);
    }

    public function renderSceneKeyframe(Request $request, string $id, string $sceneId)
    {
        $this->ownedProject($id);

        $data = $this->form->validate($request, 'SceneKeyframeRenderForm');

        [$image, $reason] = $this->videoProjectService->renderSceneImage(
            $id,
            $this->actorId(),
            $sceneId,
            (string) $data['prompt_sha256'],
            $data['anchor_artifact_id'] ?? null,
            isset($data['provider_model']) ? array_intersect_key($data, array_flip(['provider_model', 'size', 'quality'])) : null,
            isset($data['reference_choice_version']) ? (int) $data['reference_choice_version'] : null,
        );

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => $this->keyframeOutcome($image, $reason) === 'success',
                'message' => $this->sceneKeyframeMessage($reason),
            ]);
        }

        return back()->with(
            $this->keyframeOutcome($image, $reason),
            $this->sceneKeyframeMessage($reason),
        );
    }

    public function retrySceneKeyframe(Request $request, string $id, string $image)
    {
        $this->ownedProject($id);

        $data = $this->form->validate($request, 'SceneKeyframeRenderForm');

        [$done, $reason] = $this->videoProjectService->resumeSceneCandidate(
            $id,
            $this->actorId(),
            $image,
            (string) $data['prompt_sha256'],
        );

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => $this->keyframeOutcome($done, $reason) === 'success',
                'message' => $this->sceneKeyframeMessage($reason),
            ]);
        }

        return back()->with(
            $this->keyframeOutcome($done, $reason),
            $this->sceneKeyframeMessage($reason),
        );
    }

    public function saveSceneReferences(Request $request, string $id, string $sceneId)
    {
        $this->ownedProject($id);

        $data = $request->validate([
            'expected_version' => ['required', 'integer', 'min:0'],
            'items' => ['nullable', 'array', 'max:'.(VideoProjectService::SCENE_MAX_SOURCE_IMAGES - 1)],
            'items.*.artifact_id' => ['required', 'uuid'],
            'items.*.role' => ['required', 'string', 'max:40'],
        ]);

        [$version, $reason] = $this->videoProjectService->saveReferenceChoice(
            $id,
            $this->actorId(),
            $sceneId,
            (int) $data['expected_version'],
            $request->boolean('reset') ? null : array_values((array) ($data['items'] ?? [])),
        );

        return $version === null
            ? response()->json(['ok' => false, 'reason' => $reason, 'message' => $this->sceneKeyframeMessage($reason)], 422)
            : response()->json(['ok' => true, 'version' => $version]);
    }

    public function chooseSceneKeyframe(Request $request, string $id, string $sceneId)
    {
        $this->ownedProject($id);

        $data = $request->validate(['use' => ['required', 'boolean']]);

        [$done, $reason] = $this->videoProjectService->chooseFilmKeyframe(
            $id,
            $this->actorId(),
            $sceneId,
            (bool) $data['use'],
        );

        if ($request->expectsJson()) {
            return response()->json(['ok' => $done, 'message' => $this->sceneKeyframeMessage($reason)], $done ? 200 : 422);
        }

        return back()->with($done ? 'success' : 'error', $this->sceneKeyframeMessage($reason));
    }

    public function chooseFilmViews(Request $request, string $id, string $sceneId)
    {
        $this->ownedProject($id);

        $data = $request->validate([
            'auto' => ['nullable', 'boolean'],
            'views' => ['nullable', 'array', 'max:10'],
            'views.*' => ['required', 'uuid'],
        ]);

        [$done, $reason] = $this->videoProjectService->chooseFilmReferenceViews(
            $id,
            $this->actorId(),
            $sceneId,
            $request->boolean('auto') ? null : array_values((array) ($data['views'] ?? [])),
        );

        if ($request->expectsJson()) {
            return response()->json(['ok' => $done, 'message' => $this->sceneKeyframeMessage($reason)], $done ? 200 : 422);
        }

        return back()->with($done ? 'success' : 'error', $this->sceneKeyframeMessage($reason));
    }

    public function approveSceneKeyframe(Request $request, string $id, string $image)
    {
        $this->ownedProject($id);

        $data = $this->form->validate($request, 'SceneKeyframeApproveForm');

        [$done, $reason] = $this->videoProjectService->approveSceneKeyframe(
            $id,
            $this->actorId(),
            $image,
            (string) $data['artifact_id'],
        );

        if ($request->expectsJson()) {
            return response()->json(['ok' => $done, 'message' => $this->sceneKeyframeMessage($reason)]);
        }

        return back()->with($done ? 'success' : 'error', $this->sceneKeyframeMessage($reason));
    }

    /** @param array<string, mixed>|null $preview */
    private function keyframeJson(?array $preview, string $reason)
    {
        return $preview === null
            ? response()->json([
                'ok' => false,
                'reason' => $reason,
                'message' => $this->sceneKeyframeMessage($reason),
            ], 422)
            : response()->json(['ok' => true, 'reason' => $reason, 'preview' => $preview]);
    }

    private function keyframeOutcome(?object $image, string $reason): string
    {
        return $image !== null && in_array($reason, ['rendered', 'already_exists'], true)
            ? 'success'
            : 'error';
    }
}
