<?php

declare(strict_types=1);

namespace Tests\Feature\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Models\VideoRender;
use App\Models\VideoRenderScene;
use App\Models\VideoSession;
use App\Models\VideoShot;
use App\Services\Video\DesignImageStore;
use App\Services\Video\ScreenplayApprovalService;
use App\Video\Render\Enums\RenderStatus;
use App\Video\Scene\Services\ShotIntentService;
use App\Video\Scene\Services\ShotSelectionReconciler;
use App\Video\Screenplay\ScreenplayContentHash;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ShotIntentFlowTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['testing'];

    private VideoProject $project;

    private VideoRenderScene $scene;

    private VideoShot $shot;

    private VideoArtifact $source;

    protected function setUp(): void
    {
        parent::setUp();

        $application = (string) DB::connection('mysql')->selectOne('SELECT DATABASE() AS db')->db;
        $isolated = (string) DB::connection('testing')->selectOne('SELECT DATABASE() AS db')->db;

        if ($isolated === '' || $isolated === $application) {
            $this->markTestSkipped("Refusing to write to the application database ({$application}).");
        }

        config(['database.default' => 'testing']);
        $this->buildSelectedProduction();
    }

    public function test_only_the_current_completion_is_auto_selected(): void
    {
        $a = $this->render(1, RenderStatus::QUEUED);
        $b = $this->render(2, RenderStatus::QUEUED);
        $intents = app(ShotIntentService::class);

        $intents->activateProduction($this->shot, $a);
        $intents->activateProduction($this->shot, $b);
        $a->forceFill([
            'execution_status' => RenderStatus::SUCCEEDED,
            'artifact_path' => 'video/a.mp4',
            'primary_artifact_hash' => str_repeat('7', 64),
        ])->save();
        $b->forceFill([
            'execution_status' => RenderStatus::SUCCEEDED,
            'artifact_path' => 'video/b.mp4',
            'primary_artifact_hash' => str_repeat('8', 64),
        ])->save();

        $intents->complete($this->shot, $a, $this->motionHash());
        $this->assertNull($this->shot->refresh()->video_render_id);

        $intents->complete($this->shot, $b, $this->motionHash());
        $shot = $this->shot->refresh();
        $this->assertSame($b->id, $shot->video_render_id);
        $this->assertNull($shot->auto_select_version);
    }

    public function test_manual_selection_revokes_a_running_requests_auto_selection(): void
    {
        $chosen = $this->render(1, RenderStatus::SUCCEEDED);
        $running = $this->render(2, RenderStatus::QUEUED);
        $intents = app(ShotIntentService::class);
        $fingerprintBefore = app(ShotSelectionReconciler::class)
            ->verdict($this->shot, $chosen)['fingerprint'];

        $intents->activateProduction($this->shot, $running);
        $expected = (int) $this->shot->refresh()->intent_version;
        [$shot, $reason] = $intents->manualSelect(
            $this->shot,
            $chosen,
            $expected,
            (string) Str::uuid(),
            null,
        );

        $this->assertSame('selected', $reason);
        $this->assertSame($chosen->id, $shot?->video_render_id);
        $this->assertSame($running->id, $shot?->current_render_id);
        $this->assertNull($shot?->auto_select_version);
        $this->assertSame(
            $fingerprintBefore,
            app(ShotSelectionReconciler::class)->verdict($shot, $chosen)['fingerprint'],
            'The CAS counter is not a content dependency.',
        );

        $running->forceFill(['execution_status' => RenderStatus::SUCCEEDED])->save();
        $intents->complete($this->shot, $running, $this->motionHash());
        $this->assertSame($chosen->id, $this->shot->refresh()->video_render_id);
    }

    public function test_manual_selection_replays_before_version_check_and_rejects_a_changed_payload(): void
    {
        $chosen = $this->render(1, RenderStatus::SUCCEEDED);
        $other = $this->render(2, RenderStatus::SUCCEEDED);
        $operationId = (string) Str::uuid();
        $intents = app(ShotIntentService::class);

        [$selected, $reason] = $intents->manualSelect(
            $this->shot,
            $chosen,
            0,
            $operationId,
            null,
        );
        [$replayed, $replayReason] = $intents->manualSelect(
            $this->shot,
            $chosen,
            0,
            $operationId,
            null,
        );
        [$conflict, $conflictReason] = $intents->manualSelect(
            $this->shot,
            $other,
            0,
            $operationId,
            null,
        );

        $this->assertSame('selected', $reason);
        $this->assertSame($chosen->id, $selected?->video_render_id);
        $this->assertSame('replayed', $replayReason);
        $this->assertSame($chosen->id, $replayed?->video_render_id);
        $this->assertNull($conflict);
        $this->assertSame('shot_selection_operation_conflict', $conflictReason);
        $this->assertDatabaseHas('video_session_events', [
            'session_id' => $this->shot->session_id,
            'operation_id' => $operationId,
            'event_type' => 'shot_render_selected',
        ], 'testing');
        $this->assertSame(1, DB::connection('testing')->table('video_session_events')
            ->where('session_id', $this->shot->session_id)
            ->where('operation_id', $operationId)
            ->count());
        $decision = DB::connection('testing')->table('video_review_decisions')
            ->where('entity_type', 'shot')
            ->where('entity_id', $this->shot->id)
            ->first();
        $metadata = json_decode((string) $decision?->metadata_json, true);
        $this->assertArrayHasKey('operation_event_id', $metadata);
        $this->assertArrayNotHasKey('operation_id', $metadata);
    }

    public function test_a_stale_dispatch_cannot_regrant_auto_selection_after_a_manual_choice(): void
    {
        $first = $this->render(1, RenderStatus::QUEUED);
        $chosen = $this->render(2, RenderStatus::SUCCEEDED);
        $late = $this->render(3, RenderStatus::QUEUED);
        $intents = app(ShotIntentService::class);
        $firstOperation = (string) Str::uuid();

        [$afterDispatch, $dispatched, $reason] = $intents->dispatch(
            $this->shot,
            0,
            $firstOperation,
            hash('sha256', 'first-dispatch'),
            fn (): VideoRender => $first,
        );
        [$selected] = $intents->manualSelect(
            $this->shot,
            $chosen,
            (int) $afterDispatch?->intent_version,
            (string) Str::uuid(),
            null,
        );
        [$staleShot, $staleRender, $staleReason] = $intents->dispatch(
            $this->shot,
            0,
            (string) Str::uuid(),
            hash('sha256', 'late-dispatch'),
            fn (): VideoRender => $late,
        );

        $this->assertSame('dispatched', $reason);
        $this->assertSame($first->id, $dispatched?->id);
        $this->assertSame($chosen->id, $selected?->video_render_id);
        $this->assertNull($staleShot);
        $this->assertNull($staleRender);
        $this->assertSame('shot_dispatch_conflict', $staleReason);
        $this->assertSame($chosen->id, $this->shot->refresh()->video_render_id);
        $this->assertNull($this->shot->auto_select_version);
    }

    public function test_dispatch_replay_precedes_version_check_and_does_not_regrant_auto_selection(): void
    {
        $render = $this->render(1, RenderStatus::QUEUED);
        $intents = app(ShotIntentService::class);
        $operationId = (string) Str::uuid();
        $payloadHash = hash('sha256', 'same-dispatch');

        [$first] = $intents->dispatch(
            $this->shot,
            0,
            $operationId,
            $payloadHash,
            fn (): VideoRender => $render,
        );
        $first?->forceFill(['auto_select_version' => null])->save();
        [$replayed, $sameRender, $reason] = $intents->dispatch(
            $this->shot,
            0,
            $operationId,
            $payloadHash,
            fn (): VideoRender => $this->fail('A replay must not create another render.'),
        );
        [$conflict, $conflictRender, $conflictReason] = $intents->dispatch(
            $this->shot,
            0,
            $operationId,
            hash('sha256', 'changed-dispatch'),
            fn (): VideoRender => $this->fail('A conflicting replay must not create a render.'),
        );

        $this->assertSame('replayed', $reason);
        $this->assertSame($render->id, $sameRender?->id);
        $this->assertNull($replayed?->auto_select_version);
        $this->assertNull($conflict);
        $this->assertNull($conflictRender);
        $this->assertSame('shot_dispatch_operation_conflict', $conflictReason);
        $this->assertSame(1, DB::connection('testing')->table('video_session_events')
            ->where('operation_id', $operationId)
            ->count());
    }

    public function test_changed_inputs_keep_history_but_make_the_selection_stale(): void
    {
        $selected = $this->render(1, RenderStatus::SUCCEEDED);
        $this->shot->forceFill(['video_render_id' => $selected->id])->save();
        $before = app(ShotSelectionReconciler::class)->verdict($this->shot, $selected);

        $updated = app(ShotIntentService::class)->replaceInputs($this->shot, [
            'compiled_prompt' => 'a different motion prompt',
        ]);
        $verdict = app(ShotSelectionReconciler::class)->verdict($updated, $selected);

        $this->assertSame($selected->id, $updated->video_render_id);
        $this->assertNull($updated->current_render_id);
        $this->assertNull($updated->auto_select_version);
        $this->assertSame('stale', $verdict['status']);
        $this->assertContains('compiled_prompt_changed', $verdict['reasons']);
        $this->assertNotSame($before['fingerprint'], $verdict['fingerprint']);
    }

    public function test_approving_a_new_keyframe_invalidates_the_request_but_keeps_history(): void
    {
        $selected = $this->render(1, RenderStatus::SUCCEEDED);
        $this->shot->forceFill([
            'video_render_id' => $selected->id,
            'current_render_id' => $selected->id,
            'intent_version' => 3,
            'auto_select_version' => 3,
        ])->save();
        $image = VideoDesignImage::create([
            'project_id' => $this->project->id,
            'render_scene_id' => $this->scene->id,
            'image_code' => 'scene-keyframe-replacement',
            'image_type' => DesignImageStore::SCENE_KEYFRAME_TYPE,
            'status' => 'rendered',
            'revision' => 2,
        ]);
        $artifact = VideoArtifact::create([
            'project_id' => $this->project->id,
            'design_image_id' => $image->id,
            'artifact_type' => 'image',
            'role' => 'scene_keyframe',
            'storage_disk' => 'video_artifacts',
            'storage_path' => 'intent/replacement.png',
            'mime_type' => 'image/png',
            'file_size' => 1,
            'sha256' => str_repeat('2', 64),
        ]);

        [$approved] = app(DesignImageStore::class)->approve(
            $this->project->id,
            $artifact->id,
            null,
            $image->id,
            DesignImageStore::SCENE_KEYFRAME_TYPE,
            $this->scene->id,
        );
        $shot = $this->shot->refresh();
        $verdict = app(ShotSelectionReconciler::class)->verdict($shot, $selected);

        $this->assertTrue($approved);
        $this->assertSame($selected->id, $shot->video_render_id);
        $this->assertNull($shot->current_render_id);
        $this->assertNull($shot->auto_select_version);
        $this->assertSame(4, $shot->intent_version);
        $this->assertSame('stale', $verdict['status']);
        $this->assertContains('source_artifact_changed', $verdict['reasons']);
    }

    public function test_failed_current_does_not_remove_a_valid_selected_render(): void
    {
        $selected = $this->render(1, RenderStatus::SUCCEEDED);
        $failed = $this->render(2, RenderStatus::FAILED);
        $this->shot->forceFill([
            'video_render_id' => $selected->id,
            'current_render_id' => $failed->id,
            'intent_version' => 2,
            'auto_select_version' => null,
        ])->save();

        $verdict = app(ShotSelectionReconciler::class)->verdict($this->shot->refresh(), $selected);

        $this->assertSame('valid', $verdict['status']);
        $this->assertSame($selected->id, $this->shot->video_render_id);
        $this->assertSame($failed->id, $this->shot->current_render_id);
    }

    public function test_a_succeeded_render_without_checkpoint_evidence_cannot_be_selected(): void
    {
        $render = $this->render(1, RenderStatus::SUCCEEDED);
        $render->forceFill(['primary_artifact_hash' => null])->save();

        $verdict = app(ShotSelectionReconciler::class)->verdict($this->shot, $render);

        $this->assertSame('stale', $verdict['status']);
        $this->assertContains('selected_artifact_unverifiable', $verdict['reasons']);
    }

    public function test_a_hard_render_failure_is_not_downgraded_when_its_snapshot_is_missing(): void
    {
        $render = $this->render(1, RenderStatus::QUEUED);
        $render->forceFill(['render_request_json' => null])->save();

        $verdict = app(ShotSelectionReconciler::class)->verdict($this->shot, $render);

        $this->assertSame('stale', $verdict['status']);
        $this->assertContains('selected_render_not_usable', $verdict['reasons']);
        $this->assertContains('selected_artifact_unverifiable', $verdict['reasons']);
        $this->assertNotContains('render_snapshot_missing', $verdict['reasons']);
    }

    public function test_render_intent_migration_refuses_an_unsafe_legacy_unique_rollback(): void
    {
        VideoShot::create([
            'session_id' => $this->shot->session_id,
            'scene_id' => $this->scene->id,
            'beat' => $this->shot->beat,
            'shot_code' => $this->shot->shot_code,
            'shot_type' => $this->shot->shot_type,
            'kind' => $this->shot->kind,
            'plan_revision' => 2,
            'shot_index' => 1,
            'spec_json' => $this->shot->spec_json,
            'compiled_prompt' => $this->shot->compiled_prompt,
        ]);
        $migration = require database_path(
            'migrations/2026_09_25_000400_add_render_intent_to_video_shots.php'
        );

        try {
            $migration->down();
            $this->fail('down() must refuse when the legacy key would collide');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('multiple plan revisions', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('video_shots', 'current_render_id'));
        $this->assertTrue(Schema::hasColumn('video_shots', 'intent_version'));
        $this->assertTrue(Schema::hasColumn('video_shots', 'auto_select_version'));
    }

    public function test_operation_migration_refuses_to_drop_recorded_replay_evidence(): void
    {
        $render = $this->render(1, RenderStatus::SUCCEEDED);
        app(ShotIntentService::class)->manualSelect(
            $this->shot,
            $render,
            0,
            (string) Str::uuid(),
            null,
        );
        $migration = require database_path(
            'migrations/2026_09_25_000500_add_operation_identity_to_video_session_events.php'
        );

        try {
            $migration->down();
            $this->fail('down() must preserve recorded operation evidence');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('recorded session operations', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('video_session_events', 'operation_id'));
        $this->assertTrue(Schema::hasColumn('video_session_events', 'operation_payload_hash'));
        $this->assertTrue(Schema::hasColumn('video_session_events', 'operation_result_json'));
    }

    private function buildSelectedProduction(): void
    {
        $this->project = VideoProject::create(['title' => 'Shot intent '.uniqid()]);
        $screenplay = [
            'schema_version' => 'screenplay_v4',
            'logline' => 'A selected production screenplay.',
            'scenes' => [['id' => 'sc_01']],
        ];
        $screenplayStage = VideoPlanningStage::create([
            'project_id' => $this->project->id,
            'planning_revision' => 1,
            'stage' => PlanningStageName::SCREENPLAY->value,
            'status' => VideoPlanningStageStatus::SUCCEEDED->value,
            'input_hash' => hash('sha256', 'screenplay-'.$this->project->id),
            'output_json' => $screenplay,
        ]);
        (new ScreenplayApprovalService)->approve(
            $this->project->id,
            $screenplayStage->id,
            null,
            (string) Str::uuid(),
        );
        $planStage = VideoPlanningStage::create([
            'project_id' => $this->project->id,
            'planning_revision' => 1,
            'stage' => PlanningStageName::SCENE_PLAN->value,
            'status' => VideoPlanningStageStatus::SUCCEEDED->value,
            'input_hash' => hash('sha256', 'plan-'.$this->project->id),
            'output_json' => [
                'revision' => 1,
                'review' => ['status' => 'passed'],
                'warnings' => [],
            ],
        ]);
        $this->project->forceFill([
            'selected_screenplay_stage_id' => $screenplayStage->id,
            'selected_scene_plan_stage_id' => $planStage->id,
            'production_selection_version' => 1,
        ])->save();
        $this->scene = VideoRenderScene::create([
            'project_id' => $this->project->id,
            'screenplay_stage_id' => $screenplayStage->id,
            'screenplay_scene_code' => 'sc_01',
            'screenplay_hash' => ScreenplayContentHash::of($screenplay),
            'revision' => 1,
            'scene_index' => 1,
            'shot_index' => 1,
            'scene_code' => 'shot_01',
            'delta_prompt' => 'A visible action.',
            'prompt_version' => 'test-v1',
            'transition_mode' => 'hard_cut_edit',
        ]);
        $session = VideoSession::create([
            'project_id' => $this->project->id,
            'code' => 'intent_'.uniqid(),
        ]);
        $this->shot = VideoShot::create([
            'session_id' => $session->id,
            'scene_id' => $this->scene->id,
            'beat' => 'shot_01',
            'shot_code' => 'shot_01',
            'shot_type' => 'design',
            'kind' => 'motion',
            'plan_revision' => 1,
            'shot_index' => 1,
            'spec_json' => ['duration_seconds' => 2, 'motion' => ['move'], 'camera' => []],
            'compiled_prompt' => 'the subject moves',
            'scene_status' => 'keyframe_ready',
            'to_state_id' => 'state_b',
        ]);
        $image = VideoDesignImage::create([
            'project_id' => $this->project->id,
            'render_scene_id' => $this->scene->id,
            'image_code' => 'scene-keyframe-01',
            'image_type' => 'scene_keyframe',
            'status' => 'approved',
            'revision' => 1,
        ]);
        $keyframeRender = $this->renderRow(null, 1, RenderStatus::SUCCEEDED, 'image', $image->id);
        $this->source = VideoArtifact::create([
            'project_id' => $this->project->id,
            'render_id' => $keyframeRender->id,
            'design_image_id' => $image->id,
            'artifact_type' => 'image',
            'role' => 'scene_keyframe',
            'storage_disk' => 'video_artifacts',
            'storage_path' => 'intent/source.png',
            'mime_type' => 'image/png',
            'file_size' => 1,
            'sha256' => str_repeat('a', 64),
        ]);
        $image->forceFill(['selected_artifact_id' => $this->source->id])->save();
    }

    private function render(int $attempt, RenderStatus $status): VideoRender
    {
        return $this->renderRow($this->shot->id, $attempt, $status, 'video');
    }

    private function renderRow(
        ?string $shotId,
        int $attempt,
        RenderStatus $status,
        string $kind,
        ?string $designImageId = null,
    ): VideoRender {
        $request = $kind === 'video' ? [
            'compiled_prompt' => ['prompt' => 'the subject moves'],
            'motion_spec_hash' => $this->motionHash(),
            'source_artifact_id' => $this->source?->id,
            'source_artifact' => ['scene_id' => $this->scene->id],
            'end_frame' => null,
        ] : ['kind' => 'keyframe'];

        return VideoRender::create([
            'shot_id' => $shotId,
            'design_image_id' => $designImageId,
            'asset_id' => 'intent-'.$attempt.'-'.$kind,
            'attempt_no' => $attempt,
            'render_kind' => $kind,
            'execution_purpose' => 'production',
            'provider' => 'test',
            'model' => 'test-model',
            'sent_prompt' => 'the subject moves',
            'prompt_sha256' => hash('sha256', 'the subject moves'),
            'request_hash' => hash('sha256', json_encode($request, JSON_THROW_ON_ERROR)),
            'render_request_json' => json_encode($request, JSON_THROW_ON_ERROR),
            'idempotency_key' => 'intent-'.$attempt.'-'.$kind.'-'.uniqid(),
            'execution_status' => $status,
            'attempt_count' => 1,
            'max_attempts' => 3,
            'artifact_path' => $status === RenderStatus::SUCCEEDED ? 'video/'.$attempt.'.mp4' : null,
            'primary_artifact_hash' => $status === RenderStatus::SUCCEEDED ? str_repeat('9', 64) : null,
            'canonical_hash' => str_repeat('b', 64),
            'projection_hash' => str_repeat('c', 64),
            'constraint_set_hash' => str_repeat('d', 64),
            'prompt_spec_hash' => str_repeat('e', 64),
            'provider_prompt_plan_hash' => str_repeat('f', 64),
            'prompt_hash' => str_repeat('1', 64),
        ]);
    }

    private function motionHash(): string
    {
        return ShotSelectionReconciler::motionSpecHash($this->shot);
    }
}
