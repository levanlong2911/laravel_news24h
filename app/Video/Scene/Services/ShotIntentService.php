<?php

declare(strict_types=1);

namespace App\Video\Scene\Services;

use App\Models\VideoRender;
use App\Models\VideoShot;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Repositories\Interfaces\VideoRenderRepositoryInterface;
use App\Repositories\Interfaces\VideoRenderSceneRepositoryInterface;
use App\Repositories\Interfaces\VideoReviewDecisionRepositoryInterface;
use App\Repositories\Interfaces\VideoSessionEventRepositoryInterface;
use App\Repositories\Interfaces\VideoSessionRepositoryInterface;
use App\Repositories\Interfaces\VideoShotRepositoryInterface;
use App\Video\Render\Enums\RenderStatus;
use App\Video\Render\Video\SceneClipDispatchService;
use App\Video\Scene\ScenePreservationPrompt;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ShotIntentService
{
    /** @var string the key of a frozen request that says how a finished render may become the clip in use */
    public const SELECTION_KEY = 'selection_policy';

    /** @var string a render that is only a candidate when it finishes: it never takes the place of the clip in use, which changes only when a person approves another render or revokes it */
    public const SELECTION_REVIEW = 'review_before_use';

    public function __construct(
        private readonly ShotSelectionReconciler $reconciler,
        private readonly VideoProjectRepositoryInterface $projects,
        private readonly VideoSessionRepositoryInterface $sessions,
        private readonly VideoShotRepositoryInterface $shots,
        private readonly VideoRenderRepositoryInterface $renders,
        private readonly VideoRenderSceneRepositoryInterface $scenes,
        private readonly VideoSessionEventRepositoryInterface $events,
        private readonly VideoReviewDecisionRepositoryInterface $decisions,
    ) {}

    /** @param array<string, mixed> $values */
    public function replaceInputs(VideoShot $shot, array $values): VideoShot
    {
        return DB::transaction(function () use ($shot, $values): VideoShot {
            $locked = $this->shots->lockShotOrFail($shot->id);
            $changed = false;

            foreach ($values as $field => $value) {
                if ($this->canonical($locked->getAttribute($field)) !== $this->canonical($value)) {
                    $changed = true;
                    break;
                }
            }

            if (! $changed) {
                return $locked;
            }

            $this->shots->saveShot($locked, $values + [
                'intent_version' => (int) $locked->intent_version + 1,
                'current_render_id' => null,
                'auto_select_version' => null,
            ]);

            return $this->shots->refreshShot($locked);
        }, attempts: 3);
    }

    /**
     * Record a user dispatch and grant its one-time auto-selection right in the
     * same transaction. The callback may only create/reuse the immutable render
     * row; it must not contact a provider while these locks are held.
     *
     * @param  Closure(VideoShot): VideoRender  $createRender
     * @return array{0: ?VideoShot, 1: ?VideoRender, 2: string}
     */
    public function dispatch(
        VideoShot $shot,
        int $expectedIntentVersion,
        string $operationId,
        string $operationPayloadHash,
        Closure $createRender,
    ): array {
        if (! Str::isUuid($operationId)
            || preg_match('/^[a-f0-9]{64}$/', $operationPayloadHash) !== 1) {
            return [null, null, 'shot_dispatch_operation_invalid'];
        }

        $projectId = $this->sessions->findProjectIdOfSession($shot->session_id);

        if (! is_string($projectId) || $projectId === '') {
            return [null, null, 'shot_dispatch_incompatible'];
        }

        return DB::transaction(function () use (
            $shot,
            $expectedIntentVersion,
            $operationId,
            $operationPayloadHash,
            $createRender,
            $projectId,
        ): array {
            $this->projects->lockProject($projectId);
            $locked = $this->shots->lockShotOrFail($shot->id);

            $sceneProjectId = $locked->scene_id === null
                ? $projectId
                : $this->scenes->findProjectIdOfScene($locked->scene_id);

            if ((string) $sceneProjectId !== $projectId) {
                return [null, null, 'shot_dispatch_incompatible'];
            }

            // Replay precedes the ETag check: a committed operation necessarily
            // made its caller's expected version old.
            $replayed = $this->events->findOperationForSession($locked->session_id, $operationId);

            if ($replayed !== null) {
                if (! hash_equals(
                    (string) $replayed->operation_payload_hash,
                    $operationPayloadHash,
                )) {
                    return [null, null, 'shot_dispatch_operation_conflict'];
                }

                $renderId = $replayed->operation_result_json['render_id'] ?? null;
                $render = is_string($renderId)
                    ? $this->renders->findProductionRenderOfShot($renderId, $locked->id)
                    : null;

                return $render === null
                    ? [null, null, 'shot_dispatch_replay_missing']
                    : [$locked, $render, 'replayed'];
            }

            if ((int) $locked->intent_version !== $expectedIntentVersion) {
                return [null, null, 'shot_dispatch_conflict'];
            }

            $render = $createRender($locked);
            $this->assertProductionOwner($locked, $render);
            $next = $expectedIntentVersion + 1;
            $review = self::reviewBeforeUse($render);
            $locked->forceFill([
                'current_render_id' => $render->id,
                'intent_version' => $next,
                'auto_select_version' => $review ? null : $next,
            ]);

            if (! $review && $render->execution_status === RenderStatus::SUCCEEDED) {
                $verdict = $this->reconciler->verdict($locked, $render);

                if ($verdict['status'] === 'valid') {
                    $this->applySelection($locked, $render, $this->motionHashFrom($render));
                } else {
                    $locked->auto_select_version = null;
                }
            }

            $this->shots->saveShot($locked);
            $this->events->createEvent([
                'session_id' => $locked->session_id,
                'event_type' => 'shot_render_dispatched',
                'entity_type' => 'shot',
                'entity_id' => $locked->id,
                'payload_json' => [
                    'render_id' => (string) $render->id,
                    'expected_intent_version' => $expectedIntentVersion,
                ],
                'operation_id' => $operationId,
                'operation_payload_hash' => $operationPayloadHash,
                'operation_result_json' => [
                    'status' => 'dispatched',
                    'render_id' => (string) $render->id,
                    'intent_version' => $next,
                ],
            ]);

            return [$this->shots->refreshShot($locked), $render, 'dispatched'];
        }, attempts: 3);
    }

    public function invalidateForKeyframe(string $sceneId): void
    {
        DB::transaction(function () use ($sceneId): void {
            $scene = $this->scenes->findScene($sceneId);

            if ($scene === null) {
                return;
            }

            $sceneIds = [$sceneId];

            if ($scene->transition_mode === ScenePreservationPrompt::CONTINUATION
                && $scene->source_scene_code !== null) {
                $predecessor = $this->scenes->findContinuationPredecessor($scene);

                if ($predecessor !== null) {
                    $sceneIds[] = (string) $predecessor->id;
                }
            }

            $shots = $this->shots->lockShotsOfScenes($sceneIds);

            foreach ($shots as $shot) {
                $this->shots->saveShot($shot, [
                    'intent_version' => (int) $shot->intent_version + 1,
                    'current_render_id' => null,
                    'auto_select_version' => null,
                ]);
            }
        }, attempts: 3);
    }

    public function complete(VideoShot $shot, VideoRender $render, string $motionSpecHash): VideoShot
    {
        return DB::transaction(function () use ($shot, $render, $motionSpecHash): VideoShot {
            $locked = $this->shots->lockShotOrFail($shot->id);
            $render = $this->renders->findRenderOrFail($render->id);
            $this->assertProductionOwner($locked, $render);

            if ($render->execution_status !== RenderStatus::SUCCEEDED) {
                throw new RuntimeException('Video render must succeed before shot is ready.');
            }

            $snapshotHash = $this->motionHashFrom($render);

            if ($snapshotHash === '' || ! hash_equals($snapshotHash, $motionSpecHash)) {
                throw new RuntimeException('Video render motion snapshot does not match its completion.');
            }

            if (! self::reviewBeforeUse($render)
                && (string) $locked->current_render_id === (string) $render->id
                && $locked->auto_select_version !== null
                && (int) $locked->auto_select_version === (int) $locked->intent_version) {
                $verdict = $this->reconciler->verdict($locked, $render);

                if ($verdict['status'] === 'valid') {
                    $this->applySelection($locked, $render, $motionSpecHash);
                } else {
                    $locked->auto_select_version = null;
                }
                $this->shots->saveShot($locked);
            }

            return $this->shots->refreshShot($locked);
        }, attempts: 3);
    }

    /** @return array{0: ?VideoShot, 1: string} */
    public function manualSelect(
        VideoShot $shot,
        VideoRender $render,
        int $expectedIntentVersion,
        string $operationId,
        ?string $reviewerId,
    ): array {
        if (! Str::isUuid($operationId)) {
            return [null, 'shot_selection_operation_invalid'];
        }

        $projectId = $this->scenes->findProjectIdOfScene($shot->scene_id);

        if (! is_string($projectId) || $projectId === '') {
            return [null, 'shot_selection_incompatible'];
        }

        return DB::transaction(function () use (
            $shot, $render, $expectedIntentVersion, $operationId, $reviewerId, $projectId
        ): array {
            $this->projects->lockProject($projectId);
            $locked = $this->shots->lockShotOrFail($shot->id);
            $render = $this->renders->findRenderOrFail($render->id);
            $this->assertProductionOwner($locked, $render);

            $scene = $this->scenes->findScene($locked->scene_id);

            if ((string) $scene?->project_id !== $projectId) {
                return [null, 'shot_selection_incompatible'];
            }
            $payloadHash = hash('sha256', json_encode([
                'shot_id' => (string) $locked->id,
                'render_id' => (string) $render->id,
                'expected_intent_version' => $expectedIntentVersion,
            ], JSON_THROW_ON_ERROR));
            $replayed = $this->events->findOperationForSession($locked->session_id, $operationId);

            if ($replayed !== null) {
                return hash_equals(
                    (string) $replayed->operation_payload_hash,
                    $payloadHash,
                ) ? [$locked, 'replayed'] : [null, 'shot_selection_operation_conflict'];
            }

            if ((int) $locked->intent_version !== $expectedIntentVersion) {
                return [null, 'shot_selection_conflict'];
            }

            $verdict = $this->reconciler->verdict($locked, $render);

            if ($verdict['status'] !== 'valid') {
                return [null, 'shot_selection_incompatible'];
            }

            $next = $expectedIntentVersion + 1;
            $this->shots->saveShot($locked, [
                'video_render_id' => $render->id,
                'intent_version' => $next,
                'auto_select_version' => null,
                'motion_spec_hash' => $this->motionHashFrom($render),
                'scene_status' => 'video_ready',
            ]);

            $event = $this->events->createEvent([
                'session_id' => $locked->session_id,
                'event_type' => 'shot_render_selected',
                'entity_type' => 'shot',
                'entity_id' => $locked->id,
                'payload_json' => [
                    'render_id' => (string) $render->id,
                    'expected_intent_version' => $expectedIntentVersion,
                ],
                'operation_id' => $operationId,
                'operation_payload_hash' => $payloadHash,
                'operation_result_json' => [
                    'status' => 'selected',
                    'selected_render_id' => (string) $render->id,
                    'intent_version' => $next,
                ],
            ]);

            $revision = 1 + $this->decisions->maxRevisionFor('shot', $locked->id);
            $this->decisions->createDecision([
                'project_id' => $projectId,
                'session_id' => $locked->session_id,
                'entity_type' => 'shot',
                'entity_id' => $locked->id,
                'decision' => 'approved',
                'revision' => $revision,
                'reviewer_id' => $reviewerId,
                'reason' => 'Manually selected render for timeline.',
                'metadata_json' => [
                    'operation_event_id' => (string) $event->id,
                    'selected_render_id' => (string) $render->id,
                    'selection_fingerprint' => $verdict['fingerprint'],
                    'intent_version' => $next,
                ],
            ]);

            return [$this->shots->refreshShot($locked), 'selected'];
        }, attempts: 3);
    }

    private function assertProductionOwner(VideoShot $shot, VideoRender $render): void
    {
        if ((string) $render->shot_id !== (string) $shot->id) {
            throw new RuntimeException('Video render does not belong to this shot.');
        }

        if ((string) $render->execution_purpose !== SceneClipDispatchService::PURPOSE_PRODUCTION) {
            throw new RuntimeException('Only a production render may update a shot selection.');
        }
    }

    private function applySelection(VideoShot $shot, VideoRender $render, string $motionSpecHash): void
    {
        $shot->forceFill([
            'video_render_id' => $render->id,
            'motion_spec_hash' => $motionSpecHash,
            'scene_status' => 'video_ready',
            'auto_select_version' => null,
        ]);
    }

    /** @return bool whether the request of the render froze SELECTION_REVIEW, so finishing it only makes a candidate; a request that froze no policy keeps the auto-selection of its flow */
    public static function reviewBeforeUse(VideoRender $render): bool
    {
        $request = json_decode((string) $render->render_request_json, true);

        return is_array($request) && ($request[self::SELECTION_KEY] ?? null) === self::SELECTION_REVIEW;
    }

    private function motionHashFrom(VideoRender $render): string
    {
        $request = json_decode((string) $render->render_request_json, true);

        return is_array($request) && is_string($request['motion_spec_hash'] ?? null)
            ? $request['motion_spec_hash']
            : '';
    }

    private function canonical(mixed $value): string
    {
        if (is_array($value)) {
            $value = $this->sort($value);
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function sort(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = $this->sort($child);
            }
        }

        return $value;
    }
}
