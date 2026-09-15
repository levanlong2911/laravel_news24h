<?php

namespace Tests\Feature\Video;

use App\Models\VideoCostEntry;
use App\Models\VideoProject;
use App\Models\VideoProviderSubmissionReceipt;
use App\Models\VideoRender;
use App\Models\VideoSession;
use App\Models\VideoShot;
use App\Video\Render\DTO\RenderAttemptUsage;
use App\Video\Render\Enums\RenderStatus;
use App\Video\Render\Video\SceneClipDispatchService;
use App\Video\Render\Video\VideoRenderExecutionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class SceneClipRenderTest extends TestCase
{
    use DatabaseTransactions;

    private const MODEL = 'veo-3.1-generate-preview';

    private const OPERATION = 'models/veo-3.1-generate-preview/operations/abc123';

    private const DOWNLOAD = 'https://generativelanguage.googleapis.com/v1beta/files/clip:download';

    private VideoProject $project;

    private VideoSession $session;

    private \App\Models\VideoArtifact $source;

    private string $evidenceDir;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Storage::fake('video_artifacts');

        $this->evidenceDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'veo_evidence_'.uniqid();
        mkdir($this->evidenceDir, 0775, true);
        file_put_contents($this->evidenceDir.DIRECTORY_SEPARATOR.'veo_models_test.json', (string) json_encode([
            'versions' => ['v1beta' => [[
                'name' => 'models/'.self::MODEL,
                'supportedGenerationMethods' => ['predictLongRunning'],
            ]]],
        ]));

        config([
            'video.provider_evidence_dir' => $this->evidenceDir,
            'video.media_models.video.scene_clip' => [[
                'id' => 'gemini:'.self::MODEL,
                'provider' => 'gemini',
                'model' => self::MODEL,
                'label' => 'Veo test',
                'default' => true,
                'api_version' => 'v1beta',
                'mode' => 'async',
                'method' => 'predictLongRunning',
                'controls' => [
                    'durations' => [2, 8],
                    'default_duration' => 2,
                    'aspect_ratios' => ['9:16'],
                    'default_aspect_ratio' => '9:16',
                    'resolutions' => ['720p'],
                    'default_resolution' => '720p',
                ],
                'evidence' => ['models' => 'veo_models_test.json'],
            ]],
            'video.gemini.api_key' => 'test-key',
        ]);

        $this->project = VideoProject::create(['title' => 'TEST clip '.uniqid()]);
        $this->session = VideoSession::create([
            'project_id' => $this->project->id,
            'code' => 'clip_'.uniqid(),
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->evidenceDir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->evidenceDir);

        parent::tearDown();
    }

    public function test_a_machine_without_ffprobe_creates_no_render_at_all(): void
    {
        config(['video.veo.ffprobe_bin' => 'ffprobe-that-does-not-exist']);
        app()->forgetInstance(\App\Video\Media\Mp4Probe::class);

        $shot = $this->shotWithKeyframe();
        $before = VideoRender::query()->count();

        $this->expectException(RuntimeException::class);

        try {
            app(SceneClipDispatchService::class)->create($shot, $this->source, 'gemini:'.self::MODEL);
        } finally {
            $this->assertSame($before, VideoRender::query()->count(), 'khong duoc tao render vi may thieu binary');
            Http::assertNothingSent();
        }
    }

    public function test_a_clip_request_carries_the_frozen_keyframe_bytes(): void
    {
        Http::fake([
            '*:predictLongRunning' => Http::response(['name' => self::OPERATION]),
        ]);

        $shot = $this->shotWithKeyframe();
        $render = app(SceneClipDispatchService::class)->create($shot, $this->source, 'gemini:'.self::MODEL);

        [$ok, $reason] = app(VideoRenderExecutionService::class)->submit($render->id);

        $this->assertTrue($ok, $reason);
        $this->assertSame('provider_running', $reason);

        Http::assertSent(function ($request): bool {
            $instance = $request->data()['instances'][0] ?? [];

            $image = $instance['image'] ?? [];
            $params = $request->data()['parameters'] ?? [];

            return base64_decode((string) ($image['bytesBase64Encoded'] ?? ''), true) === $this->pngBytes()
                && ($image['mimeType'] ?? null) === 'image/png'
                && ($params['durationSeconds'] ?? null) === 2
                && ($params['personGeneration'] ?? null) === 'allow_adult'
                && ! array_key_exists('sampleCount', $params);
        });

        $render->refresh();

        $this->assertSame(RenderStatus::PROVIDER_RUNNING, $render->execution_status);
        $this->assertSame(self::OPERATION, $render->provider_job_id);
        $this->assertNull($render->claim_token, 'phai nha lease sau khi submit');
        $this->assertSame('submitted', $render->attempts()->first()->status->value);
    }

    public function test_a_duration_that_arrives_as_a_string_is_still_accepted(): void
    {
        // Form HTTP gui chuoi; registry khai so nguyen va so sanh nghiem ngat.
        // Kieu phai duoc dua ve dung o bien, neu khong nut Render luon hong.
        $form = new \App\Form\SceneClipRenderForm;

        $data = $form->validate(\Illuminate\Http\Request::create("/x", "POST", [
            "model_id" => "gemini:".self::MODEL,
            "duration_seconds" => "8",
        ]));

        $this->assertSame(8, $data["duration_seconds"]);

        Http::fake(["*:predictLongRunning" => Http::response(["name" => self::OPERATION])]);

        $shot = $this->shotWithKeyframe();
        $render = app(SceneClipDispatchService::class)->create(
            $shot, $this->source, "gemini:".self::MODEL,
            ["duration_seconds" => $data["duration_seconds"]],
        );

        $this->assertSame(8, json_decode($render->render_request_json, true)["duration_seconds"]);
    }

    public function test_a_long_resolution_with_a_short_duration_is_refused(): void
    {
        config([
            "video.media_models.video.scene_clip.0.controls.resolutions" => ["720p", "1080p"],
            "video.media_models.video.scene_clip.0.controls.long_resolutions" => ["1080p"],
            "video.media_models.video.scene_clip.0.controls.long_resolution_duration" => 8,
        ]);
        app()->forgetInstance(\App\Video\Media\VideoModelRegistry::class);

        $shot = $this->shotWithKeyframe();

        $this->expectExceptionMessageMatches("/1080p chi nhan thoi luong 8s/");

        try {
            app(SceneClipDispatchService::class)->create(
                $shot, $this->source, "gemini:".self::MODEL,
                ["resolution" => "1080p", "duration_seconds" => 2],
            );
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_source_file_that_changed_since_freezing_sends_nothing(): void
    {
        $shot = $this->shotWithKeyframe();
        $render = app(SceneClipDispatchService::class)->create($shot, $this->source, 'gemini:'.self::MODEL);

        Storage::disk('video_artifacts')->put('scene/keyframe.png', 'khac han truoc do');

        [$ok, $reason] = app(VideoRenderExecutionService::class)->submit($render->id);

        $this->assertFalse($ok);
        $this->assertSame('source_artifact_invalid', $reason);
        Http::assertNothingSent();
        $this->assertSame(RenderStatus::FAILED, $render->refresh()->execution_status);
    }

    public function test_an_ambiguous_submit_is_never_sent_twice(): void
    {
        Http::fake(function (): void {
            throw new ConnectionException('timeout');
        });

        $shot = $this->shotWithKeyframe();
        $render = app(SceneClipDispatchService::class)->create($shot, $this->source, 'gemini:'.self::MODEL);

        [$ok, $reason] = app(VideoRenderExecutionService::class)->submit($render->id);

        $this->assertFalse($ok);
        $this->assertSame('provider_unknown', $reason);
        $this->assertSame(RenderStatus::PROVIDER_UNKNOWN, $render->refresh()->execution_status);
        $this->assertSame('ambiguous', $render->attempts()->first()->status->value);

        $this->assertSame(
            [false, 'not_claimable'],
            app(VideoRenderExecutionService::class)->submit($render->id),
            'o mo ho khong duoc phep gui lai — tien co the da tieu roi',
        );
    }

    public function test_a_pending_poll_only_writes_a_checkpoint(): void
    {
        $render = $this->runningRender([
            '*'.self::OPERATION => Http::response(['name' => self::OPERATION, 'done' => false]),
        ]);

        [$ok, $reason] = app(VideoRenderExecutionService::class)->poll($render->id);

        $this->assertTrue($ok);
        $this->assertSame('provider_running', $reason);

        $render->refresh();

        $this->assertSame(RenderStatus::PROVIDER_RUNNING, $render->execution_status);
        $this->assertSame(1, $render->provider_poll_count);
        $this->assertNull($render->artifact_path);
        Http::assertSentCount(2);
    }

    public function test_a_finished_poll_writes_a_clip_measured_by_ffprobe(): void
    {
        $render = $this->runningRender([
            '*'.self::OPERATION => Http::response($this->doneBody()),
            self::DOWNLOAD => Http::response($this->mp4Bytes(), 200, ['Content-Type' => 'video/mp4']),
        ]);

        [$ok, $reason] = app(VideoRenderExecutionService::class)->poll($render->id);

        $this->assertTrue($ok, $reason);
        $this->assertSame('succeeded', $reason);

        $render->refresh();

        $this->assertSame(RenderStatus::SUCCEEDED, $render->execution_status);
        $this->assertSame('video/'.$render->id.'/clip.mp4', $render->artifact_path);
        Storage::disk('video_artifacts')->assertExists('video/'.$render->id.'/clip.mp4');

        $this->assertSame(64, $render->width, 'kich thuoc phai den tu ffprobe');
        $this->assertSame(64, $render->height);
        $this->assertEqualsWithDelta(2000, $render->duration_ms, 200);
        $this->assertSame(hash('sha256', $this->mp4Bytes()), $render->primary_artifact_hash);
        $this->assertSame('generated_video', $render->artifact_manifest['artifacts'][0]['kind']);
        $this->assertSame('video_ready', $render->shot->refresh()->scene_status);
    }

    public function test_a_clip_whose_length_does_not_match_is_thrown_away(): void
    {
        $render = $this->runningRender([
            '*'.self::OPERATION => Http::response($this->doneBody()),
            self::DOWNLOAD => Http::response($this->mp4Bytes(), 200, ['Content-Type' => 'video/mp4']),
        ], durationSeconds: 8);

        [$ok, $reason] = app(VideoRenderExecutionService::class)->poll($render->id);

        $this->assertFalse($ok);
        $this->assertSame('duration_mismatch', $reason);
        $this->assertSame(RenderStatus::FAILED, $render->refresh()->execution_status);
        Storage::disk('video_artifacts')->assertMissing('video/'.$render->id.'/clip.mp4');
    }

    public function test_a_download_from_a_host_nobody_allowed_never_reaches_the_disk(): void
    {
        $render = $this->runningRender([
            '*'.self::OPERATION => Http::response($this->doneBody('https://cdn.example.com/clip.mp4')),
        ]);

        [$ok, $reason] = app(VideoRenderExecutionService::class)->poll($render->id);

        $this->assertFalse($ok);
        $this->assertSame('no_video_sample', $reason, 'uri ngoai allowlist khong duoc coi la mot sample');
        $this->assertSame(RenderStatus::FAILED, $render->refresh()->execution_status);
        Http::assertSentCount(2, 'khong duoc goi toi host la');
    }

    public function test_an_operation_that_returns_no_video_fails_without_an_artifact(): void
    {
        $render = $this->runningRender([
            '*'.self::OPERATION => Http::response(['name' => self::OPERATION, 'done' => true, 'response' => []]),
        ]);

        [$ok, $reason] = app(VideoRenderExecutionService::class)->poll($render->id);

        $this->assertFalse($ok);
        $this->assertSame('no_video_sample', $reason);
        $this->assertNull($render->refresh()->artifact_path);
    }

    public function test_a_clip_ledger_row_is_refused_when_no_money_was_reported(): void
    {
        $render = $this->runningRender([
            '*'.self::OPERATION => Http::response($this->doneBody()),
            self::DOWNLOAD => Http::response($this->mp4Bytes(), 200, ['Content-Type' => 'video/mp4']),
        ]);

        app(VideoRenderExecutionService::class)->poll($render->id);

        $this->assertSame(
            0,
            VideoCostEntry::query()->where('entity_id', $render->attempts()->first()->id)->count(),
            'Veo khong tra gia nen khong duoc ghi dong tien nao',
        );
    }

    public function test_a_second_clip_for_the_same_shot_gets_its_own_attempt_number(): void
    {
        Http::fake(['*:predictLongRunning' => Http::response(['name' => self::OPERATION])]);

        $shot = $this->shotWithKeyframe();
        $dispatch = app(SceneClipDispatchService::class);

        $first = $dispatch->create($shot, $this->source, 'gemini:'.self::MODEL, ['duration_seconds' => 2]);
        $second = $dispatch->create($shot, $this->source, 'gemini:'.self::MODEL, ['duration_seconds' => 8]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(1, $first->attempt_no);
        $this->assertSame(2, $second->attempt_no, 'unique (shot_id, attempt_no) phai dem theo shot');
    }

    public function test_a_failed_clip_that_never_reached_the_provider_can_be_retried(): void
    {
        Http::fake(["*:predictLongRunning" => Http::response(["error" => ["message" => "khong nhan"]], 400)]);

        $shot = $this->shotWithKeyframe();
        $dispatch = app(SceneClipDispatchService::class);

        $first = $dispatch->create($shot, $this->source, "gemini:".self::MODEL);
        app(VideoRenderExecutionService::class)->submit($first->id);

        $this->assertSame(RenderStatus::FAILED, $first->refresh()->execution_status);
        $this->assertSame(0, VideoProviderSubmissionReceipt::query()->where("render_id", $first->id)->count());

        // Cung yeu cau, nhung luot truoc da hong va KHONG co bien lai — phai la hang MOI.
        $second = $dispatch->create($shot, $this->source, "gemini:".self::MODEL);

        $this->assertNotSame($first->id, $second->id, "lam lai phai la mot hang render moi");
        $this->assertSame(RenderStatus::QUEUED, $second->execution_status);
        $this->assertNull($second->failure_message, "hang moi khong duoc mang loi cua luot truoc");
    }

    public function test_a_clip_the_provider_already_took_is_never_silently_resent(): void
    {
        Http::fake(["*:predictLongRunning" => Http::response(["name" => self::OPERATION])]);

        $shot = $this->shotWithKeyframe();
        $dispatch = app(SceneClipDispatchService::class);

        $first = $dispatch->create($shot, $this->source, "gemini:".self::MODEL);
        app(VideoRenderExecutionService::class)->submit($first->id);

        $this->assertSame(1, VideoProviderSubmissionReceipt::query()->where("render_id", $first->id)->count());

        // Ep hang do thanh failed nhung bien lai van con: tien da di.
        VideoRender::query()->whereKey($first->id)->update(["execution_status" => RenderStatus::FAILED->value]);

        $this->expectExceptionMessageMatches("/tra tien hai lan/");

        $dispatch->create($shot, $this->source, "gemini:".self::MODEL);
    }

    public function test_the_same_clip_request_twice_reuses_one_render(): void
    {
        Http::fake(['*:predictLongRunning' => Http::response(['name' => self::OPERATION])]);

        $shot = $this->shotWithKeyframe();
        $dispatch = app(SceneClipDispatchService::class);

        $first = $dispatch->create($shot, $this->source, 'gemini:'.self::MODEL);
        $second = $dispatch->create($shot, $this->source, 'gemini:'.self::MODEL);

        $this->assertSame($first->id, $second->id, 'bam hai lan cung mot yeu cau khong duoc tra tien hai lan');
    }

    public function test_a_clip_is_reachable_through_its_shot_not_through_a_session(): void
    {
        $render = $this->runningRender();

        $this->assertNull($render->video_session_id, 'chu cua clip la shot, khong phai session');

        $found = VideoRender::query()
            ->whereKey($render->id)
            ->where('render_kind', 'video')
            ->where(fn ($query) => $query
                ->whereHas('shot.session', fn ($scope) => $scope->where('project_id', $this->project->id))
                ->orWhereHas('session', fn ($scope) => $scope->where('project_id', $this->project->id)))
            ->first();

        $this->assertNotNull($found, 'duong so huu phai di qua shot.session, neu khong route poll luon 404');
    }

    public function test_a_lease_that_expires_while_downloading_writes_nothing(): void
    {
        $render = $this->runningRender([
            '*'.self::OPERATION => Http::response($this->doneBody()),
            self::DOWNLOAD => function () use (&$render) {
                VideoRender::query()->whereKey($render->id)
                    ->update(['lease_expires_at' => now()->subSecond()]);

                return Http::response($this->mp4Bytes(), 200, ['Content-Type' => 'video/mp4']);
            },
        ]);

        [$ok, $reason] = app(VideoRenderExecutionService::class)->poll($render->id);

        $this->assertFalse($ok);
        $this->assertSame('lease_lost_before_artifact', $reason);

        $render->refresh();

        $this->assertNotSame(RenderStatus::SUCCEEDED, $render->execution_status);
        $this->assertNull($render->artifact_path, 'het lease thi khong duoc ghi metadata');
        Storage::disk('video_artifacts')->assertMissing('video/'.$render->id.'/clip.mp4');
    }

    public function test_a_job_stranded_by_a_broken_checkpoint_can_still_be_polled(): void
    {
        $render = $this->runningRender([
            '*'.self::OPERATION => Http::response(['name' => self::OPERATION, 'done' => false]),
        ]);

        // Dung canh submit da thanh cong nhung checkpoint hong: job id con, trang thai ket.
        VideoRender::query()->whereKey($render->id)->update([
            'execution_status' => RenderStatus::SUBMITTING->value,
            'claim_token' => null,
            'claimed_by' => null,
            'lease_expires_at' => null,
        ]);

        [$ok, $reason] = app(VideoRenderExecutionService::class)->poll($render->id);

        $this->assertTrue($ok, $reason);
        $this->assertSame('provider_running', $reason);
        $this->assertSame(RenderStatus::PROVIDER_RUNNING, $render->refresh()->execution_status);
    }

    public function test_a_job_id_survives_even_when_the_claim_is_lost_mid_submit(): void
    {
        $shot = $this->shotWithKeyframe();

        Http::fake(['*:predictLongRunning' => function () use (&$render) {
            // Lease chet dung trong luc Veo dang nhan job.
            VideoRender::query()->whereKey($render->id)->update(['lease_expires_at' => now()->subSecond()]);

            return Http::response(['name' => self::OPERATION]);
        }]);

        $render = app(SceneClipDispatchService::class)->create($shot, $this->source, 'gemini:'.self::MODEL);

        [$ok, $reason] = app(VideoRenderExecutionService::class)->submit($render->id);

        $this->assertFalse($ok);
        $this->assertSame('ownership_lost_after_submit', $reason);

        $this->assertNull(
            $render->refresh()->provider_job_id,
            'job cua luot da mat quyen khong duoc gan vao cot chung — hang co the da sang generation khac',
        );

        $attempt = $render->attempts()->first();

        $this->assertSame(
            self::OPERATION,
            $attempt->provider_job_id,
            'tien da tieu thi job id phai o lai tren dung attempt da tra tien',
        );
        $this->assertSame(1, $attempt->claim_generation, 'dau vet phai dinh danh generation');
    }

    public function test_a_stale_worker_never_writes_its_job_onto_a_newer_attempt(): void
    {
        $shot = $this->shotWithKeyframe();

        Http::fake(['*:predictLongRunning' => function () use (&$render) {
            // Lease chet VA hang da sang luot sau, co han mot attempt moi cua worker khac.
            VideoRender::query()->whereKey($render->id)->update([
                'lease_expires_at' => now()->subSecond(),
                'attempt_count' => 2,
                'claim_generation' => 2,
            ]);

            \App\Models\VideoRenderAttempt::create([
                'render_id' => $render->id,
                'attempt_no' => 2,
                'status' => 'submitting',
                'provider_key' => 'gemini',
                'model_key' => self::MODEL,
                'request_hash' => $render->request_hash,
                'base_semantic_hash' => $render->request_hash,
                'claim_token' => (string) Str::uuid(),
                'claim_generation' => 2,
                'worker_id' => 'worker-khac',
                'started_at' => now(),
            ]);

            return Http::response(['name' => self::OPERATION]);
        }]);

        $render = app(SceneClipDispatchService::class)->create($shot, $this->source, 'gemini:'.self::MODEL);

        [$ok, $reason] = app(VideoRenderExecutionService::class)->submit($render->id);

        $this->assertFalse($ok);
        $this->assertSame('ownership_lost_after_submit', $reason);
        $this->assertNull($render->refresh()->provider_job_id);

        $this->assertNull(
            $render->attempts()->where('attempt_no', 2)->first()->provider_job_id,
            'job cua luot cu khong duoc gan vao attempt cua luot moi',
        );
        $this->assertSame(
            self::OPERATION,
            $render->attempts()->where('attempt_no', 1)->first()->provider_job_id,
            'no phai o lai dung luot da tra tien',
        );
    }

    public function test_a_rescued_job_is_adopted_from_its_attempt_and_polled(): void
    {
        $shot = $this->shotWithKeyframe();

        Http::fake([
            '*:predictLongRunning' => function () use (&$render) {
                VideoRender::query()->whereKey($render->id)->update(['lease_expires_at' => now()->subSecond()]);

                return Http::response(['name' => self::OPERATION]);
            },
            '*'.self::OPERATION => Http::response(['name' => self::OPERATION, 'done' => false]),
        ]);

        $render = app(SceneClipDispatchService::class)->create($shot, $this->source, 'gemini:'.self::MODEL);
        $execution = app(VideoRenderExecutionService::class);

        $this->assertSame([false, 'ownership_lost_after_submit'], $execution->submit($render->id));
        $this->assertNull($render->refresh()->provider_job_id);

        [$ok, $reason] = $execution->poll($render->id);

        $this->assertTrue($ok, $reason);
        $this->assertSame('provider_running', $reason);

        $render->refresh();

        $this->assertSame(RenderStatus::PROVIDER_RUNNING, $render->execution_status);
        $this->assertSame(self::OPERATION, $render->provider_job_id, 'job da tra tien phai poll duoc');
        $this->assertSame(1, $render->provider_poll_count);
    }

    public function test_a_rescued_job_is_left_alone_when_a_newer_attempt_exists(): void
    {
        $render = $this->runningRender([
            '*'.self::OPERATION => Http::response(['name' => self::OPERATION, 'done' => false]),
        ]);

        $render->attempts()->update(['provider_job_id' => self::OPERATION]);

        VideoRender::query()->whereKey($render->id)->update([
            'execution_status' => RenderStatus::PROVIDER_UNKNOWN->value,
            'provider_job_id' => null,
            'claim_token' => null, 'claimed_by' => null, 'lease_expires_at' => null,
        ]);

        \App\Models\VideoRenderAttempt::create([
            'render_id' => $render->id,
            'attempt_no' => 2,
            'status' => 'submitting',
            'provider_key' => 'gemini',
            'model_key' => self::MODEL,
            'request_hash' => $render->request_hash,
            'base_semantic_hash' => $render->request_hash,
            'claim_token' => (string) Str::uuid(),
            'claim_generation' => 2,
            'worker_id' => 'worker-khac',
            'started_at' => now(),
        ]);

        [$ok, $reason] = app(VideoRenderExecutionService::class)->poll($render->id);

        $this->assertFalse($ok);
        $this->assertSame('not_running', $reason, 'co luot moi hon thi job cu khong duoc vot');
        $this->assertNull($render->refresh()->provider_job_id);
    }

    public function test_a_file_swapped_for_one_of_the_same_size_is_refused(): void
    {
        $render = $this->readyToCheckpoint($token);
        $swapped = false;
        $path = 'video/'.$render->id.'/clip.mp4';

        DB::beforeExecuting(function (string $query) use ($path, &$swapped): void {
            if ($swapped || ! str_contains($query, 'for update')) {
                return;
            }

            $swapped = true;

            // Cung so byte, khac noi dung: chi so sanh size thi khong bao gio thay.
            Storage::disk('video_artifacts')->put($path, str_repeat('x', strlen($this->mp4Bytes())));
        });

        $this->expectExceptionMessageMatches('/content changed before checkpoint/');

        try {
            app(\App\Video\Render\RenderCheckpointService::class)->complete(
                renderId: $render->id,
                claimToken: $token,
                requestHash: (string) $render->request_hash,
                providerRequestId: null,
                artifactManifest: $this->manifestFor($render),
                usage: new RenderAttemptUsage(null, null, null, null, null),
            );
        } finally {
            $this->assertTrue($swapped, 'phai trao file dung trong khe giua do va commit');
            $this->assertNotSame(RenderStatus::SUCCEEDED, $render->refresh()->execution_status);
        }
    }

    public function test_a_receipt_is_written_even_when_the_claim_is_lost(): void
    {
        $shot = $this->shotWithKeyframe();

        Http::fake(['*:predictLongRunning' => function () use (&$render) {
            VideoRender::query()->whereKey($render->id)->update(['lease_expires_at' => now()->subSecond()]);

            return Http::response(['name' => self::OPERATION]);
        }]);

        $render = app(SceneClipDispatchService::class)->create($shot, $this->source, 'gemini:'.self::MODEL);

        $this->assertSame(
            [false, 'ownership_lost_after_submit'],
            app(VideoRenderExecutionService::class)->submit($render->id),
        );

        $receipt = VideoProviderSubmissionReceipt::query()->where('render_id', $render->id)->first();

        $this->assertNotNull($receipt, 'tien da tieu thi phai co bien lai');
        $this->assertSame(self::OPERATION, $receipt->provider_job_id);
        $this->assertSame(1, $receipt->attempt_no);
        $this->assertSame(1, $receipt->claim_generation);
        $this->assertSame($render->attempts()->first()->id, $receipt->attempt_id);
    }

    public function test_the_same_job_never_makes_two_receipts(): void
    {
        $render = $this->runningRender();
        $receipt = VideoProviderSubmissionReceipt::query()->where('render_id', $render->id)->first();

        $this->assertNotNull($receipt, 'duong thanh cong cung phai de lai bien lai');

        VideoProviderSubmissionReceipt::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'render_id' => $render->id,
            'attempt_id' => $receipt->attempt_id,
            'attempt_no' => 1,
            'claim_generation' => 1,
            'claim_token' => (string) Str::uuid(),
            'provider' => 'gemini',
            'model' => self::MODEL,
            'provider_job_id' => self::OPERATION,
            'observed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(
            1,
            VideoProviderSubmissionReceipt::query()->where('render_id', $render->id)->count(),
            'ghi lai cung mot job cho cung mot attempt phai la idempotent',
        );
    }

    public function test_a_receipt_is_never_edited_or_deleted(): void
    {
        $render = $this->runningRender();
        $receipt = VideoProviderSubmissionReceipt::query()->where('render_id', $render->id)->firstOrFail();

        try {
            $receipt->forceFill(['provider_job_id' => 'models/veo/operations/sua-trom'])->save();
            $this->fail('bien lai khong duoc sua');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        try {
            $receipt->delete();
            $this->fail('bien lai khong duoc xoa');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertSame(self::OPERATION, $receipt->fresh()->provider_job_id);
    }

    public function test_even_a_raw_query_cannot_touch_a_receipt(): void
    {
        // Chan o tang model chi chan duong Eloquent. Day la duong ma mot doan code
        // voi va se dung, nen no phai bi chan o ngay tang DB.
        $render = $this->runningRender();
        $receipt = VideoProviderSubmissionReceipt::query()->where("render_id", $render->id)->firstOrFail();

        try {
            DB::table("video_provider_submission_receipts")->where("id", $receipt->id)
                ->update(["provider_job_id" => "sua-trom"]);
            $this->fail("query builder khong duoc sua bien lai");
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString("append-only", $e->getMessage());
        }

        try {
            DB::table("video_provider_submission_receipts")->where("id", $receipt->id)->delete();
            $this->fail("query builder khong duoc xoa bien lai");
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString("append-only", $e->getMessage());
        }

        $this->assertSame(self::OPERATION, $receipt->fresh()->provider_job_id);
    }

    public function test_a_receipt_from_another_claim_is_not_accepted_as_evidence(): void
    {
        $shot = $this->shotWithKeyframe();

        Http::fake(['*:predictLongRunning' => function () use (&$render) {
            // Mot bien lai CUNG job nhung cua claim khac da nam san trong bang.
            $attempt = $render->attempts()->first();

            VideoProviderSubmissionReceipt::query()->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'render_id' => $render->id,
                'attempt_id' => $attempt->id,
                'attempt_no' => 1,
                'claim_generation' => 99,
                'claim_token' => (string) Str::uuid(),
                'provider' => 'gemini',
                'model' => self::MODEL,
                'provider_job_id' => self::OPERATION,
                'observed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return Http::response(['name' => self::OPERATION]);
        }]);

        $render = app(SceneClipDispatchService::class)->create($shot, $this->source, 'gemini:'.self::MODEL);

        [$ok, $reason] = app(VideoRenderExecutionService::class)->submit($render->id);

        $this->assertFalse($ok);
        $this->assertSame(
            'ownership_lost_after_submit',
            $reason,
            'bien lai cua claim khac khong duoc tinh la bang chung cho lan nay',
        );
    }

    public function test_a_receipt_of_the_current_generation_is_adopted_and_polled(): void
    {
        $shot = $this->shotWithKeyframe();

        Http::fake([
            '*:predictLongRunning' => function () use (&$render) {
                VideoRender::query()->whereKey($render->id)->update(['lease_expires_at' => now()->subSecond()]);

                return Http::response(['name' => self::OPERATION]);
            },
            '*'.self::OPERATION => Http::response(['name' => self::OPERATION, 'done' => false]),
        ]);

        $render = app(SceneClipDispatchService::class)->create($shot, $this->source, 'gemini:'.self::MODEL);
        $execution = app(VideoRenderExecutionService::class);
        $execution->submit($render->id);

        // Xoa dau vet tren attempt: chi con bien lai lam nguon su that.
        $render->attempts()->update(['provider_job_id' => null]);

        [$ok, $reason] = $execution->poll($render->id);

        $this->assertTrue($ok, $reason);
        $this->assertSame(self::OPERATION, $render->refresh()->provider_job_id);
    }

    public function test_two_conflicting_receipts_are_never_polled_blindly(): void
    {
        $shot = $this->shotWithKeyframe();

        Http::fake(['*:predictLongRunning' => function () use (&$render) {
            VideoRender::query()->whereKey($render->id)->update(['lease_expires_at' => now()->subSecond()]);

            return Http::response(['name' => self::OPERATION]);
        }]);

        $render = app(SceneClipDispatchService::class)->create($shot, $this->source, 'gemini:'.self::MODEL);
        app(VideoRenderExecutionService::class)->submit($render->id);

        $render->attempts()->update(['provider_job_id' => null]);

        $first = VideoProviderSubmissionReceipt::query()->where('render_id', $render->id)->first();

        VideoProviderSubmissionReceipt::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'render_id' => $render->id,
            'attempt_id' => $first->attempt_id,
            'attempt_no' => 1,
            'claim_generation' => 1,
            'claim_token' => (string) Str::uuid(),
            'provider' => 'gemini',
            'model' => self::MODEL,
            'provider_job_id' => 'models/veo/operations/mot-job-khac',
            'observed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$ok, $reason] = app(VideoRenderExecutionService::class)->poll($render->id);

        $this->assertFalse($ok);
        $this->assertSame('not_running', $reason, 'hai job mau thuan thi khong duoc doan');
        $this->assertNull($render->refresh()->provider_job_id);
    }

    public function test_a_lease_that_dies_before_the_request_stops_the_submit(): void
    {
        $shot = $this->shotWithKeyframe();
        $render = app(SceneClipDispatchService::class)->create($shot, $this->source, 'gemini:'.self::MODEL);

        app(\App\Video\Render\Claims\RenderClaimService::class)
            ->claimById($render->id, VideoRenderExecutionService::WORKER);

        VideoRender::query()->whereKey($render->id)->update(['lease_expires_at' => now()->subSecond()]);

        [$ok, $reason] = app(VideoRenderExecutionService::class)->submit($render->id);

        $this->assertFalse($ok);
        $this->assertSame('not_claimable', $reason);
        Http::assertNothingSent();
    }

    public function test_adopting_a_job_repairs_the_attempt_record_too(): void
    {
        $render = $this->runningRender([
            '*'.self::OPERATION => Http::response(['name' => self::OPERATION, 'done' => false]),
        ]);

        VideoRender::query()->whereKey($render->id)->update([
            'execution_status' => RenderStatus::PROVIDER_UNKNOWN->value,
            'claim_token' => null, 'claimed_by' => null, 'lease_expires_at' => null,
        ]);
        $render->attempts()->update(['status' => 'ambiguous', 'provider_job_id' => null]);

        app(VideoRenderExecutionService::class)->poll($render->id);

        $attempt = $render->attempts()->first();

        $this->assertSame('submitted', $attempt->status->value);
        $this->assertSame(self::OPERATION, $attempt->provider_job_id, 'dau vet job phai theo ca attempt');
    }

    public function test_a_job_whose_lease_is_alive_is_never_adopted(): void
    {
        $render = $this->runningRender();

        VideoRender::query()->whereKey($render->id)->update([
            'execution_status' => RenderStatus::SUBMITTING->value,
            'claim_token' => (string) Str::uuid(),
            'claimed_by' => 'worker-khac',
            'lease_expires_at' => now()->addMinutes(5),
        ]);

        [$ok, $reason] = app(VideoRenderExecutionService::class)->poll($render->id);

        $this->assertFalse($ok);
        $this->assertSame('not_running', $reason, 'khong duoc cuop job dang co nguoi giu lease');
        $this->assertSame('worker-khac', $render->refresh()->claimed_by);
    }

    public function test_a_lease_that_expires_before_the_failure_stops_the_write(): void
    {
        $render = $this->runningRender([
            '*'.self::OPERATION => Http::response($this->doneBody()),
            self::DOWNLOAD => function () use (&$render) {
                // Lease chet giua chung, roi file tra ve khong hop le: duong that bai
                // chay khi ta da mat quyen ghi.
                VideoRender::query()->whereKey($render->id)
                    ->update(['lease_expires_at' => now()->subSecond()]);

                return Http::response('khong phai mp4', 200, ['Content-Type' => 'text/plain']);
            },
        ]);

        [$ok, $reason] = app(VideoRenderExecutionService::class)->poll($render->id);

        $this->assertFalse($ok);
        $this->assertSame('lease_lost_before_failure', $reason);
        $this->assertNotSame(RenderStatus::FAILED, $render->refresh()->execution_status);
    }

    public function test_a_made_up_manifest_is_refused_at_checkpoint(): void
    {
        $render = $this->runningRender();

        $this->provider()->claimPoll($render, $token = (string) Str::uuid(), VideoRenderExecutionService::WORKER);
        $this->provider()->markArtifactWriting($render->refresh(), $token, []);

        $this->expectException(RuntimeException::class);

        app(\App\Video\Render\RenderCheckpointService::class)->complete(
            renderId: $render->id,
            claimToken: $token,
            requestHash: (string) $render->request_hash,
            providerRequestId: null,
            artifactManifest: [
                'render_id' => $render->id,
                'request_hash' => $render->request_hash,
                'canonical_hash' => $render->canonical_hash,
                'artifacts' => [[
                    'kind' => 'generated_video',
                    'path' => 'video/khong-he-ton-tai/clip.mp4',
                    'sha256' => str_repeat('b', 64),
                    'bytes' => 1024,
                    'mime' => 'video/mp4',
                    'duration_ms' => 2000,
                    'width' => 64,
                    'height' => 64,
                ]],
            ],
            usage: new RenderAttemptUsage(null, null, null, null, null),
        );
    }

    public function test_a_real_file_with_invented_numbers_is_refused(): void
    {
        $render = $this->readyToCheckpoint($token);

        $this->expectExceptionMessageMatches('/duration does not match/');

        app(\App\Video\Render\RenderCheckpointService::class)->complete(
            renderId: $render->id,
            claimToken: $token,
            requestHash: (string) $render->request_hash,
            providerRequestId: null,
            artifactManifest: $this->manifestFor($render, ['duration_ms' => 30000]),
            usage: new RenderAttemptUsage(null, null, null, null, null),
        );
    }

    public function test_an_artifact_belonging_to_another_render_is_refused(): void
    {
        $render = $this->readyToCheckpoint($token);

        Storage::disk('video_artifacts')->put('video/mot-render-khac/clip.mp4', $this->mp4Bytes());

        $this->expectExceptionMessageMatches('/does not belong to this render/');

        app(\App\Video\Render\RenderCheckpointService::class)->complete(
            renderId: $render->id,
            claimToken: $token,
            requestHash: (string) $render->request_hash,
            providerRequestId: null,
            artifactManifest: $this->manifestFor($render, ['path' => 'video/mot-render-khac/clip.mp4']),
            usage: new RenderAttemptUsage(null, null, null, null, null),
        );
    }

    public function test_an_artifact_over_the_limit_is_refused_before_it_fills_the_disk(): void
    {
        $render = $this->readyToCheckpoint($token);

        config(['video.veo.max_bytes' => 100]);

        $this->expectExceptionMessageMatches('/larger than the allowed size/');

        app(\App\Video\Render\RenderCheckpointService::class)->complete(
            renderId: $render->id,
            claimToken: $token,
            requestHash: (string) $render->request_hash,
            providerRequestId: null,
            artifactManifest: $this->manifestFor($render),
            usage: new RenderAttemptUsage(null, null, null, null, null),
        );
    }

    public function test_a_lease_already_dead_by_checkpoint_time_leaves_the_render_alone(): void
    {
        $render = $this->readyToCheckpoint($token);

        VideoRender::query()->whereKey($render->id)->update(['lease_expires_at' => now()->subSecond()]);

        try {
            app(\App\Video\Render\RenderCheckpointService::class)->complete(
                renderId: $render->id,
                claimToken: $token,
                requestHash: (string) $render->request_hash,
                providerRequestId: null,
                artifactManifest: $this->manifestFor($render),
                usage: new RenderAttemptUsage(null, null, null, null, null),
            );

            $this->fail('lease da chet thi khong duoc ghi succeeded');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('lease expired', $e->getMessage());
        }

        $this->assertSame(RenderStatus::ARTIFACT_WRITING, $render->refresh()->execution_status);
        Storage::disk('video_artifacts')->assertExists('video/'.$render->id.'/clip.mp4');
    }

    public function test_a_lease_that_dies_while_verifying_never_reaches_succeeded(): void
    {
        $render = $this->readyToCheckpoint($token);

        // Dong ho nhay qua han lease NGAY SAU lan kiem dau tien, tuc la trong luc
        // dang bam lai file.
        $clock = new class implements \App\Video\Concept\Support\Clock
        {
            private int $calls = 0;

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->calls++ === 0 ? 'now' : '+10 minutes');
            }
        };

        $checkpoint = new \App\Video\Render\RenderCheckpointService(
            $clock,
            app(\App\Video\Render\Cost\RenderCostAccountingService::class),
            new \App\Video\Render\StateMachine\RenderStateMachine,
            app(\Illuminate\Contracts\Filesystem\Factory::class),
            app(\App\Video\Media\Mp4Probe::class),
        );

        try {
            $checkpoint->complete(
                renderId: $render->id,
                claimToken: $token,
                requestHash: (string) $render->request_hash,
                providerRequestId: null,
                artifactManifest: $this->manifestFor($render),
                usage: new RenderAttemptUsage(null, null, null, null, null),
            );

            $this->fail('lease chet giua luc verify thi khong duoc ghi succeeded');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('lease expired', $e->getMessage());
        }

        $render->refresh();

        $this->assertSame(RenderStatus::ARTIFACT_WRITING, $render->execution_status);
        $this->assertNull($render->artifact_manifest, 'khong duoc ghi manifest');
        $this->assertSame(
            0,
            VideoCostEntry::query()->where('entity_id', $render->attempts()->first()->id)->count(),
            'khong duoc ghi dong tien nao',
        );
    }

    private function readyToCheckpoint(?string &$token): VideoRender
    {
        $render = $this->runningRender();

        Storage::disk('video_artifacts')->put('video/'.$render->id.'/clip.mp4', $this->mp4Bytes());

        $this->provider()->claimPoll($render, $token = (string) Str::uuid(), VideoRenderExecutionService::WORKER);
        $this->provider()->markArtifactWriting($render->refresh(), $token, []);

        return $render->refresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function manifestFor(VideoRender $render, array $overrides = []): array
    {
        return [
            'render_id' => $render->id,
            'request_hash' => $render->request_hash,
            'canonical_hash' => $render->canonical_hash,
            'artifacts' => [array_replace([
                'kind' => 'generated_video',
                'path' => 'video/'.$render->id.'/clip.mp4',
                'sha256' => hash('sha256', $this->mp4Bytes()),
                'bytes' => strlen($this->mp4Bytes()),
                'mime' => 'video/mp4',
                'duration_ms' => 2000,
                'width' => 64,
                'height' => 64,
            ], $overrides)],
        ];
    }

    public function test_a_clip_cost_row_still_knows_its_session(): void
    {
        $render = $this->runningRender();
        $attempt = $render->attempts()->first();

        $entry = app(\App\Video\Render\Cost\RenderCostAccountingService::class)->record(
            $render, $attempt, new RenderAttemptUsage(null, null, null, null, '0.40000000'),
        );

        $this->assertNotNull($entry);
        $this->assertSame($this->session->id, $entry->session_id, 'session suy duoc qua shot');
        $this->assertSame($this->project->id, $entry->project_id);
        $this->assertSame('video_render', $entry->stage);
    }

    private function provider(): \App\Video\Render\VideoProviderCheckpointService
    {
        return app(\App\Video\Render\VideoProviderCheckpointService::class);
    }

    public function test_the_screen_sees_a_clip_that_is_still_running(): void
    {
        $scene = \App\Models\VideoRenderScene::create([
            'project_id' => $this->project->id,
            'revision' => 1,
            'scene_code' => 'scene_a',
            'scene_index' => 1,
            // CHECK `video_render_scenes_project_payload_ck`: scene cua mot project
            // phai mang du payload, khong duoc de trong.
            'delta_prompt' => 'x',
            'prompt_version' => 'test-v1',
            'transition_mode' => 'hard_cut_edit',
        ]);

        $render = $this->runningRender();
        $render->shot->forceFill(['scene_id' => $scene->id])->save();

        $cells = app(\App\Services\VideoProjectService::class)->sceneClipCells($this->project->id, 1);

        $this->assertArrayHasKey($scene->id, $cells);
        $this->assertSame($render->shot->id, $cells[$scene->id]['shot_id']);
        $this->assertSame(
            $render->id,
            $cells[$scene->id]['render_id'],
            'clip dang chay chua set video_render_id, man hinh van phai thay no',
        );
        $this->assertSame('provider_running', $cells[$scene->id]['status']);
    }

    public function test_money_that_is_not_a_number_never_reaches_the_ledger(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RenderAttemptUsage::fromArray(['provider_cost_usd' => 'mien phi']);
    }

    public function test_a_negative_price_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RenderAttemptUsage::fromArray(['provider_cost_usd' => -1.5]);
    }

    private function shotWithKeyframe(): VideoShot
    {
        Storage::disk('video_artifacts')->put('scene/keyframe.png', $this->pngBytes());


        $keyframe = VideoRender::create([
            'video_session_id' => $this->session->id,
            'asset_id' => 'scene_a',
            'render_kind' => 'image',
            'provider' => 'openai',
            'model' => 'gpt-image-2',
            'sent_prompt' => 'p',
            'prompt_sha256' => hash('sha256', 'p'),
            'request_hash' => str_repeat('a', 64),
            'render_request_json' => '{"version":"render-request-v1"}',
            'idempotency_key' => 'keyframe:'.uniqid(),
            'execution_status' => RenderStatus::SUCCEEDED,
            'attempt_count' => 1,
            'max_attempts' => 3,
            'artifact_path' => 'scene/keyframe.png',
            'primary_artifact_hash' => hash('sha256', $this->pngBytes()),
            'canonical_hash' => str_repeat('c', 64),
            'projection_hash' => str_repeat('d', 64),
            'constraint_set_hash' => str_repeat('e', 64),
            'prompt_spec_hash' => str_repeat('f', 64),
            'provider_prompt_plan_hash' => str_repeat('0', 64),
            'prompt_hash' => str_repeat('1', 64),
        ]);

        $this->source = \App\Models\VideoArtifact::create([
            'project_id' => $this->project->id,
            'render_id' => $keyframe->id,
            'artifact_type' => 'image',
            'role' => 'scene_keyframe',
            'storage_disk' => 'video_artifacts',
            'storage_path' => 'scene/keyframe.png',
            'mime_type' => 'image/png',
            'file_size' => strlen($this->pngBytes()),
            'sha256' => hash('sha256', $this->pngBytes()),
        ]);

        return VideoShot::create([
            'session_id' => $this->session->id,
            'beat' => 'scene_a',
            'shot_code' => 'scene_a_'.uniqid(),
            'shot_type' => 'establish',
            'kind' => 'motion',
            'spec_json' => ['duration_seconds' => 2, 'motion' => ['x'], 'camera' => []],
            'compiled_prompt' => 'the camera pushes in',
            'scene_image_render_id' => $keyframe->id,
            'scene_status' => 'keyframe_ready',
            'to_state_id' => 'state_b',
        ]);
    }

    /** @param array<string, mixed> $stubs */
    private function runningRender(array $stubs = [], int $durationSeconds = 2): VideoRender
    {
        Http::fake(array_merge(['*:predictLongRunning' => Http::response(['name' => self::OPERATION])], $stubs));

        $shot = $this->shotWithKeyframe();
        $render = app(SceneClipDispatchService::class)->create(
            $shot, $this->source, 'gemini:'.self::MODEL, ['duration_seconds' => $durationSeconds],
        );

        app(VideoRenderExecutionService::class)->submit($render->id);

        $render = $render->refresh();
        $this->assertSame(RenderStatus::PROVIDER_RUNNING, $render->execution_status, (string) $render->failure_message);

        return $render;
    }

    /** @return array<string, mixed> */
    private function doneBody(string $uri = self::DOWNLOAD): array
    {
        return [
            'name' => self::OPERATION,
            'done' => true,
            'response' => ['generateVideoResponse' => ['generatedSamples' => [['video' => ['uri' => $uri]]]]],
        ];
    }

    private function pngBytes(): string
    {
        $image = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function mp4Bytes(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/video/clip_2s.mp4'));
    }
}
