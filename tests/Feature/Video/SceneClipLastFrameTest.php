<?php

namespace Tests\Feature\Video;

use App\Enums\DesignImageStatus;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Models\VideoProject;
use App\Models\VideoRender;
use App\Models\VideoRenderScene;
use App\Models\VideoSession;
use App\Models\VideoShot;
use App\Services\Video\DesignImageStore;
use App\Video\Render\Enums\RenderStatus;
use App\Video\Render\Video\SceneClipDispatchService;
use App\Video\Scene\ScenePreservationPrompt;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Clip cua scene N ket thuc o keyframe DA DUYET cua scene N+1.
 *
 * Cai dang duoc khoa o day khong phai "payload co them mot truong", ma la: khi nao
 * thi mot clip DUOC PHEP chay ma khong bi rang trang thai ket thuc.
 */
class SceneClipLastFrameTest extends TestCase
{
    use DatabaseTransactions;

    private const MODEL = 'veo-3.1-generate-preview';

    private VideoProject $project;

    private VideoSession $session;

    private string $evidenceDir;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Storage::fake('video_artifacts');

        $this->evidenceDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'veo_lastframe_'.uniqid();
        mkdir($this->evidenceDir, 0775, true);
        file_put_contents($this->evidenceDir.DIRECTORY_SEPARATOR.'veo_models_test.json', (string) json_encode([
            'versions' => ['v1beta' => [[
                'name' => 'models/'.self::MODEL,
                'supportedGenerationMethods' => ['predictLongRunning'],
            ]]],
        ]));

        $this->useRegistry(lastFrame: true);

        $this->project = VideoProject::create(['title' => 'TEST lastframe '.uniqid()]);
        $this->session = VideoSession::create([
            'project_id' => $this->project->id,
            'code' => 'lf_'.uniqid(),
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

    public function test_a_continuing_successor_becomes_the_end_frame(): void
    {
        [$shot, , $endArtifact] = $this->pair();

        $render = $this->dispatch()->create($shot, $this->sourceOf($shot), 'gemini:'.self::MODEL);
        $spec = $this->specOf($render);

        $this->assertNotNull($spec['end_frame'], 'scene ke noi tiep thi clip phai co anh cuoi');
        $this->assertSame((string) $endArtifact->id, $spec['end_frame']['artifact_id']);
        $this->assertSame('veo-image-to-video-v5', $spec['provider_payload_version']);
    }

    public function test_the_end_frame_snapshot_carries_the_scene_it_came_from(): void
    {
        [$shot, $next] = $this->pair();

        $spec = $this->specOf($this->dispatch()->create($shot, $this->sourceOf($shot), 'gemini:'.self::MODEL));

        $this->assertSame(
            (string) $next->id,
            $spec['end_frame']['scene_id'],
            'scene_id phai nam TRONG snapshot: no chung minh resolver chon dung scene ke',
        );
        $this->assertSame(8, $spec['end_frame']['width']);
        $this->assertSame(8, $spec['end_frame']['height']);
        $this->assertArrayHasKey('sha256', $spec['end_frame']);
    }

    public function test_a_scene_whose_successor_is_a_hard_cut_takes_no_end_frame(): void
    {
        [$shot] = $this->pair(nextMode: ScenePreservationPrompt::HARD_CUT);

        $spec = $this->specOf($this->dispatch()->create($shot, $this->sourceOf($shot), 'gemini:'.self::MODEL));

        $this->assertNull($spec['end_frame'], 'hard cut khong noi tiep, nen khong rang trang thai ket thuc');
    }

    public function test_the_last_scene_of_a_group_takes_no_end_frame(): void
    {
        $scene = $this->scene(0, 'sc_a');
        $shot = $this->shotFor($scene);
        $this->approvedKeyframe($scene);

        $spec = $this->specOf($this->dispatch()->create($shot, $this->sourceOf($shot), 'gemini:'.self::MODEL));

        $this->assertNull($spec['end_frame']);
    }

    public function test_a_successor_in_another_continuity_group_is_not_a_successor(): void
    {
        [$shot] = $this->pair(nextGroup: 'another_group');

        $spec = $this->specOf($this->dispatch()->create($shot, $this->sourceOf($shot), 'gemini:'.self::MODEL));

        $this->assertNull(
            $spec['end_frame'],
            '`source_scene_code` mot minh khong du: hang trong DB co the bi chinh thang',
        );
    }

    public function test_a_successor_without_an_approved_keyframe_blocks_the_clip(): void
    {
        [$shot] = $this->pair(approveNext: false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/successor_keyframe_unapproved/');

        $before = VideoRender::query()->count();

        try {
            $this->dispatch()->create($shot, $this->sourceOf($shot), 'gemini:'.self::MODEL);
        } finally {
            $this->assertSame($before, VideoRender::query()->count(), 'chan thi khong duoc de lai hang nao');
        }
    }

    public function test_the_block_names_the_scene_that_is_being_waited_on(): void
    {
        [$shot] = $this->pair(approveNext: false);

        try {
            $this->dispatch()->create($shot, $this->sourceOf($shot), 'gemini:'.self::MODEL);
            $this->fail('phai chan');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sc_b', $e->getMessage(), 'nguoi doc phai biet dang cho scene nao');
        }
    }

    public function test_with_the_capability_off_an_unapproved_successor_does_not_block(): void
    {
        $this->useRegistry(lastFrame: false);
        [$shot] = $this->pair(approveNext: false);

        $spec = $this->specOf($this->dispatch()->create($shot, $this->sourceOf($shot), 'gemini:'.self::MODEL));

        $this->assertNull($spec['end_frame'], 'tat co phai tra lai DUNG hanh vi cu, khong de lai mot cai chan moi');
    }

    public function test_with_the_capability_off_a_valid_successor_is_still_not_used(): void
    {
        $this->useRegistry(lastFrame: false);
        [$shot] = $this->pair();

        $spec = $this->specOf($this->dispatch()->create($shot, $this->sourceOf($shot), 'gemini:'.self::MODEL));

        $this->assertNull($spec['end_frame']);
    }

    public function test_the_canary_attaches_an_end_frame_even_with_the_capability_off(): void
    {
        $this->useRegistry(lastFrame: false);
        [$shot, , $endArtifact] = $this->pair();

        $render = $this->dispatch()->createCanary($shot, $this->sourceOf($shot), 'gemini:'.self::MODEL);
        $spec = $this->specOf($render);

        $this->assertSame((string) $endArtifact->id, $spec['end_frame']['artifact_id']);
        $this->assertSame('canary', (string) $render->execution_purpose);
    }

    public function test_a_canary_on_a_scene_with_no_successor_is_refused(): void
    {
        $scene = $this->scene(0, 'sc_a');
        $shot = $this->shotFor($scene);
        $this->approvedKeyframe($scene);

        $this->expectException(RuntimeException::class);

        $this->dispatch()->createCanary($shot, $this->sourceOf($shot), 'gemini:'.self::MODEL);
    }

    public function test_a_production_clip_is_marked_production(): void
    {
        [$shot] = $this->pair();

        $render = $this->dispatch()->create($shot, $this->sourceOf($shot), 'gemini:'.self::MODEL);

        $this->assertSame('production', (string) $render->execution_purpose);
    }

    public function test_changing_the_end_frame_changes_the_request_hash(): void
    {
        [$shot, $next] = $this->pair();

        $first = $this->dispatch()->create($shot, $this->sourceOf($shot), 'gemini:'.self::MODEL)->request_hash;

        // Duyet mot anh KHAC cho scene ke: cung mot scene, cung mot prompt, chi doi
        // anh cuoi — hash van phai doi, neu khong thi hai luot khac nhau dung chung
        // mot idempotency key.
        VideoDesignImage::query()->where('render_scene_id', $next->id)->delete();
        $this->approvedKeyframe($next, 'scene/other.png');

        $shot->refresh();
        $second = $this->dispatch()->create($shot, $this->sourceOf($shot), 'gemini:'.self::MODEL)->request_hash;

        $this->assertNotSame($first, $second);
    }

    public function test_frames_of_different_ratios_are_refused_before_the_request_leaves(): void
    {
        [$shot, $next] = $this->pair();

        VideoDesignImage::query()->where('render_scene_id', $next->id)->delete();
        $this->approvedKeyframe($next, 'scene/wide.png', width: 32, height: 8);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/khac ti le/');

        $this->dispatch()->create($shot, $this->sourceOf($shot), 'gemini:'.self::MODEL);
    }

    public function test_an_end_frame_on_a_model_without_the_capability_is_refused(): void
    {
        // Canary bo qua co khi GAN anh cuoi, nhung registry van phai la noi noi model
        // nao duoc phep — khong co canary nao duoc gui cho mot model chua khai.
        $this->useRegistry(lastFrame: null);
        [$shot] = $this->pair();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/khong khai `last_frame`/');

        $this->dispatch()->createCanary($shot, $this->sourceOf($shot), 'gemini:'.self::MODEL);
    }

    public function test_a_canary_never_takes_over_the_production_cell(): void
    {
        [$shot] = $this->pair();

        $production = $this->dispatch()->create($shot, $this->sourceOf($shot), 'gemini:'.self::MODEL);
        $this->dispatch()->createCanary($shot, $this->sourceOf($shot), 'gemini:'.self::MODEL);

        $cells = app(\App\Services\VideoProjectService::class)->sceneClipCells($this->project->id, 1);
        $cell = $cells[(string) $shot->scene_id] ?? null;

        $this->assertNotNull($cell);
        $this->assertSame(
            (string) $production->id,
            (string) $cell['render_id'],
            'canary co attempt_no lon hon; keyBy giu phan tu cuoi nen no se chiem o neu khong loc',
        );
    }

    // --- day dien: cai that su roi khoi may --------------------------------------

    public function test_the_end_frame_actually_reaches_the_wire(): void
    {
        Http::fake(['*:predictLongRunning' => Http::response(['name' => 'models/x/operations/abc'])]);

        [$shot] = $this->pair();
        $render = $this->dispatch()->createCanary($shot, $this->sourceOf($shot), 'gemini:'.self::MODEL);

        [$ok, $reason] = app(\App\Video\Render\Video\VideoRenderExecutionService::class)->submit($render->id);

        $this->assertTrue($ok, $reason);

        $startBytes = $this->pngBytes();
        $endBytes = $this->pngBytes(8, 8, 200);

        $this->assertNotSame($startBytes, $endBytes, 'fixture phai khac nhau, neu khong test nay khong chung minh gi');

        Http::assertSent(function ($request) use ($startBytes, $endBytes): bool {
            $instance = $request->data()['instances'][0] ?? [];
            $image = $instance['image'] ?? [];
            $last = $instance['lastFrame'] ?? null;

            return is_array($last)
                && base64_decode((string) ($image['bytesBase64Encoded'] ?? ''), true) === $startBytes
                && base64_decode((string) ($last['bytesBase64Encoded'] ?? ''), true) === $endBytes
                && ($last['mimeType'] ?? null) === 'image/png';
        });
    }

    public function test_a_clip_without_an_end_frame_sends_no_last_frame_field(): void
    {
        Http::fake(['*:predictLongRunning' => Http::response(['name' => 'models/x/operations/abc'])]);

        $scene = $this->scene(0, 'sc_a');
        $shot = $this->shotFor($scene);
        $this->approvedKeyframe($scene);

        $render = $this->dispatch()->create($shot, $this->sourceOf($shot), 'gemini:'.self::MODEL);
        app(\App\Video\Render\Video\VideoRenderExecutionService::class)->submit($render->id);

        Http::assertSent(static function ($request): bool {
            return ! array_key_exists('lastFrame', $request->data()['instances'][0] ?? []);
        });
    }

    public function test_an_end_frame_rewritten_behind_its_checksum_never_reaches_the_provider(): void
    {
        Http::fake(['*:predictLongRunning' => Http::response(['name' => 'models/x/operations/abc'])]);

        [$shot] = $this->pair();
        $render = $this->dispatch()->createCanary($shot, $this->sourceOf($shot), 'gemini:'.self::MODEL);

        Storage::disk('video_artifacts')->put('scene/end.png', $this->pngBytes(8, 8, 90));

        [$ok, $reason] = app(\App\Video\Render\Video\VideoRenderExecutionService::class)->submit($render->id);

        $this->assertFalse($ok);
        $this->assertSame('end_frame_invalid', $reason);
        Http::assertNothingSent();
    }

    public function test_a_hash_from_before_the_plan_moved_creates_no_row(): void
    {
        [$shot, $next] = $this->pair();
        $preflight = $this->dispatch()->preflight($shot, $this->sourceOf($shot), 'gemini:'.self::MODEL);

        // Nguoi dung dang doc man hinh xac nhan thi mot nguoi khac duyet anh cuoi khac.
        VideoDesignImage::query()->where('render_scene_id', $next->id)->delete();
        $this->approvedKeyframe($next, 'scene/end2.png', shade: 120);

        $before = VideoRender::query()->count();

        try {
            $this->dispatch()->createCanary(
                $shot, $this->sourceOf($shot), 'gemini:'.self::MODEL, [], $preflight['request_hash'],
            );
            $this->fail('hash cu phai bi tu choi');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('da doi', $e->getMessage());
        }

        $this->assertSame($before, VideoRender::query()->count(), 'tu choi thi khong duoc de lai hang nao');
    }

    public function test_an_unchanged_plan_lets_the_pinned_hash_through(): void
    {
        [$shot] = $this->pair();
        $preflight = $this->dispatch()->preflight($shot, $this->sourceOf($shot), 'gemini:'.self::MODEL);

        $render = $this->dispatch()->createCanary(
            $shot, $this->sourceOf($shot), 'gemini:'.self::MODEL, [], $preflight['request_hash'],
        );

        $this->assertSame($preflight['request_hash'], (string) $render->request_hash);
    }

    public function test_a_canary_is_never_the_same_row_as_a_production_clip(): void
    {
        [$shot] = $this->pair();

        $production = $this->dispatch()->create($shot, $this->sourceOf($shot), 'gemini:'.self::MODEL);
        $canary = $this->dispatch()->createCanary($shot, $this->sourceOf($shot), 'gemini:'.self::MODEL);

        $this->assertNotSame((string) $production->id, (string) $canary->id);
        $this->assertSame('production', (string) $production->execution_purpose);
        $this->assertSame('canary', (string) $canary->execution_purpose);
    }

    public function test_a_production_click_after_a_canary_does_not_inherit_the_canary_row(): void
    {
        // Chieu nguy hiem: man hinh LOC canary di, nen neu cu bam Render tra ve chinh
        // hang canary thi clip cua nguoi dung bien mat khoi man hinh trong khi tien
        // da tieu — khong co gi bao.
        [$shot] = $this->pair();

        $canary = $this->dispatch()->createCanary($shot, $this->sourceOf($shot), 'gemini:'.self::MODEL);
        $production = $this->dispatch()->create($shot, $this->sourceOf($shot), 'gemini:'.self::MODEL);

        $this->assertNotSame((string) $canary->id, (string) $production->id);
        $this->assertSame('production', (string) $production->execution_purpose);

        $cells = app(\App\Services\VideoProjectService::class)->sceneClipCells($this->project->id, 1);
        $this->assertSame((string) $production->id, (string) ($cells[(string) $shot->scene_id]['render_id'] ?? ''));
    }

    public function test_preflight_creates_no_row_at_all(): void
    {
        [$shot] = $this->pair();
        $before = VideoRender::query()->count();

        $this->dispatch()->preflight($shot, $this->sourceOf($shot), 'gemini:'.self::MODEL);

        $this->assertSame($before, VideoRender::query()->count());
    }

    // --- lenh canary --------------------------------------------------------------

    public function test_the_command_refuses_a_wrong_expected_artifact_before_creating_a_row(): void
    {
        [$shot, , $endArtifact] = $this->pair();
        $before = VideoRender::query()->count();

        $this->artisan('video:canary-last-frame', [
            '--scene' => (string) $shot->scene_id,
            '--model' => 'gemini:'.self::MODEL,
            '--end-artifact' => (string) $endArtifact->id.'-khong-phai',
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame($before, VideoRender::query()->count(), 'go nham --end-artifact khong duoc de lai hang nao');
    }

    public function test_declining_the_confirmation_creates_no_row(): void
    {
        [$shot] = $this->pair();
        $before = VideoRender::query()->count();

        $this->artisan('video:canary-last-frame', [
            '--scene' => (string) $shot->scene_id,
            '--model' => 'gemini:'.self::MODEL,
        ])
            ->expectsConfirmation('Gui luot canary nay?', 'no')
            ->assertExitCode(1);

        $this->assertSame($before, VideoRender::query()->count());
    }

    public function test_the_command_shows_the_prompt_and_both_frames_before_asking(): void
    {
        [$shot] = $this->pair();

        $this->artisan('video:canary-last-frame', [
            '--scene' => (string) $shot->scene_id,
            '--model' => 'gemini:'.self::MODEL,
        ])
            ->expectsOutputToContain('the camera pushes in')
            ->expectsOutputToContain('Anh cuoi :')
            ->expectsOutputToContain('veo-image-to-video-v5')
            ->expectsConfirmation('Gui luot canary nay?', 'no')
            ->assertExitCode(1);
    }

    public function test_the_command_submits_exactly_once(): void
    {
        Http::fake(['*:predictLongRunning' => Http::response(['name' => 'models/x/operations/abc'])]);

        [$shot] = $this->pair();

        $this->artisan('video:canary-last-frame', [
            '--scene' => (string) $shot->scene_id,
            '--model' => 'gemini:'.self::MODEL,
            '--force' => true,
        ])->assertExitCode(0);

        Http::assertSentCount(1);

        $render = VideoRender::query()
            ->where('asset_id', $shot->scene_id)
            ->where('render_kind', 'video')
            ->where('execution_purpose', 'canary')
            ->sole();

        $this->assertSame('canary', $render->execution_purpose);
    }

    public function test_the_command_points_at_poll_only_when_a_job_exists(): void
    {
        Http::fake(['*:predictLongRunning' => Http::response(['name' => 'models/x/operations/abc'])]);

        [$shot] = $this->pair();

        $this->artisan('video:canary-last-frame', [
            '--scene' => (string) $shot->scene_id,
            '--model' => 'gemini:'.self::MODEL,
            '--force' => true,
        ])
            ->expectsOutputToContain('Provider da nhan job')
            ->expectsOutputToContain('video:poll-provider')
            ->assertExitCode(0);
    }

    public function test_an_ambiguous_connection_loss_never_tells_the_operator_to_poll(): void
    {
        // Mat ket noi giua chung: khong co job id, va cung khong the khang dinh la khong
        // mat tien. Bao "di poll" o day la noi doi, va giuc gui lai la co the tra tien
        // hai lan cho cung mot canh.
        Http::fake(function (): void {
            throw new ConnectionException('timeout');
        });

        [$shot] = $this->pair();

        $this->artisan('video:canary-last-frame', [
            '--scene' => (string) $shot->scene_id,
            '--model' => 'gemini:'.self::MODEL,
            '--force' => true,
        ])
            ->expectsOutputToContain('KHONG biet provider co nhan job hay khong')
            ->doesntExpectOutputToContain('video:poll-provider')
            ->assertExitCode(1);
    }

    public function test_a_submit_that_never_reached_the_provider_says_there_is_nothing_to_poll(): void
    {
        [$shot] = $this->pair();
        $render = $this->dispatch()->createCanary($shot, $this->sourceOf($shot), 'gemini:'.self::MODEL);

        // ffprobe bien mat SAU khi hang da duoc tao: submit dung lai truoc khi goi provider.
        config(['video.veo.ffprobe_bin' => 'ffprobe-that-does-not-exist']);
        app()->forgetInstance(\App\Video\Media\Mp4Probe::class);

        [$ok, $reason] = app(\App\Video\Render\Video\VideoRenderExecutionService::class)->submit($render->id);

        $this->assertFalse($ok);
        $this->assertSame('', (string) $render->fresh()->provider_job_id, 'khong co job thi khong co gi de poll');
        Http::assertNothingSent();
    }

    public function test_a_job_paid_for_but_lost_at_the_write_still_points_at_poll(): void
    {
        // `ownership_lost_after_submit`: provider DA nhan job, bien lai da ghi, nhung
        // CAS len `video_renders` truot vi lease het han. Cot `provider_job_id` tren
        // render van rong — doc moi cot do thi lenh se bao "khong co gi de poll" cho
        // mot luot DA TRA TIEN.
        [$shot] = $this->pair();
        $renderId = null;

        Http::fake(['*:predictLongRunning' => function () use (&$renderId, $shot) {
            $row = VideoRender::query()
                ->where('asset_id', $shot->scene_id)
                ->where('render_kind', 'video')
                ->where('execution_purpose', 'canary')
                ->sole();
            $renderId = $row->id;

            VideoRender::query()->whereKey($renderId)->update(['lease_expires_at' => now()->subSecond()]);

            return Http::response(['name' => 'models/x/operations/paid-but-lost']);
        }]);

        $this->artisan('video:canary-last-frame', [
            '--scene' => (string) $shot->scene_id,
            '--model' => 'gemini:'.self::MODEL,
            '--force' => true,
        ])
            ->expectsOutputToContain('ownership_lost_after_submit')
            ->expectsOutputToContain('models/x/operations/paid-but-lost')
            ->expectsOutputToContain('video:poll-provider')
            ->assertExitCode(1);

        $this->assertNull(
            VideoRender::query()->whereKey($renderId)->value('provider_job_id'),
            'cot tren render phai van rong — neu khong thi test nay khong chung minh gi',
        );
    }
    // --- fixtures -------------------------------------------------------------

    private function dispatch(): SceneClipDispatchService
    {
        app()->forgetInstance(SceneClipDispatchService::class);

        return app(SceneClipDispatchService::class);
    }

    private function useRegistry(?bool $lastFrame): void
    {
        $controls = [
            'durations' => [2, 8],
            'default_duration' => 2,
            'aspect_ratios' => ['9:16'],
            'default_aspect_ratio' => '9:16',
            'resolutions' => ['720p'],
            'default_resolution' => '720p',
        ];

        if ($lastFrame !== null) {
            $controls['last_frame'] = $lastFrame;
        }

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
                'controls' => $controls,
                'evidence' => ['models' => 'veo_models_test.json'],
            ]],
            'video.gemini.api_key' => 'test-key',
        ]);

        app()->forgetInstance(\App\Video\Media\VideoModelRegistry::class);
    }

    /**
     * Hai scene noi tiep nhau, scene truoc co shot.
     *
     * @return array{0: VideoShot, 1: VideoRenderScene, 2: ?VideoArtifact}
     */
    private function pair(
        string $nextMode = ScenePreservationPrompt::CONTINUATION,
        ?string $nextGroup = null,
        bool $approveNext = true,
    ): array {
        $current = $this->scene(0, 'sc_a');
        $next = $this->scene(1, 'sc_b', $nextMode, 'sc_a', $nextGroup ?? 'grp_one');

        $this->approvedKeyframe($current);
        $endArtifact = $approveNext ? $this->approvedKeyframe($next, 'scene/end.png', shade: 200) : null;

        return [$this->shotFor($current), $next, $endArtifact];
    }

    private function scene(
        int $index,
        string $code,
        string $mode = ScenePreservationPrompt::HARD_CUT,
        ?string $sourceCode = null,
        string $group = 'grp_one',
    ): VideoRenderScene {
        return VideoRenderScene::create([
            'project_id' => $this->project->id,
            'revision' => 1,
            'scene_index' => $index,
            'scene_code' => $code,
            'title' => 'Scene '.$code,
            'purpose' => 'a scene that exists only so this test has something to resolve',
            'delta_prompt' => 'delta for '.$code,
            'prompt_version' => ScenePreservationPrompt::VERSION,
            'transition_mode' => $mode,
            'continuity_group' => $group,
            'source_scene_code' => $sourceCode,
            // `video_prompt` khong nam tren scene: no duoc dung tu day khi ban ke
            // hoach duoc doc ra. Thieu no thi lenh canary khong co prompt de gui.
            'video_plan_json' => [
                'action' => 'the camera pushes in',
                'preserve' => 'the hull stays where it is',
                'end_state' => 'the frame rests on the bow',
            ],
        ]);
    }

    private function approvedKeyframe(
        VideoRenderScene $scene,
        string $path = 'scene/keyframe.png',
        int $width = 8,
        int $height = 8,
        int $shade = 0,
    ): VideoArtifact {
        $bytes = $this->pngBytes($width, $height, $shade);
        Storage::disk('video_artifacts')->put($path, $bytes);

        $render = VideoRender::create([
            'video_session_id' => $this->session->id,
            'asset_id' => (string) $scene->scene_code,
            'render_kind' => 'image',
            'provider' => 'openai',
            'model' => 'gpt-image-2',
            'sent_prompt' => 'p',
            'prompt_sha256' => hash('sha256', 'p'),
            'request_hash' => hash('sha256', $path.$scene->id),
            'render_request_json' => '{"version":"render-request-v1"}',
            'idempotency_key' => 'kf:'.uniqid('', true),
            'execution_status' => RenderStatus::SUCCEEDED,
            'attempt_count' => 1,
            'max_attempts' => 3,
            'artifact_path' => $path,
            'primary_artifact_hash' => hash('sha256', $bytes),
            'canonical_hash' => str_repeat('c', 64),
            'projection_hash' => str_repeat('d', 64),
            'constraint_set_hash' => str_repeat('e', 64),
            'prompt_spec_hash' => str_repeat('f', 64),
            'provider_prompt_plan_hash' => str_repeat('0', 64),
            'prompt_hash' => str_repeat('1', 64),
        ]);

        $artifact = VideoArtifact::create([
            'project_id' => $this->project->id,
            'render_id' => $render->id,
            'artifact_type' => 'image',
            'role' => 'scene_keyframe',
            'storage_disk' => 'video_artifacts',
            'storage_path' => $path,
            'mime_type' => 'image/png',
            'file_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
        ]);

        $image = VideoDesignImage::create([
            'project_id' => $this->project->id,
            'render_scene_id' => $scene->id,
            'image_code' => 'kf_'.$scene->scene_code.'_'.uniqid(),
            'image_type' => DesignImageStore::SCENE_KEYFRAME_TYPE,
            'status' => DesignImageStatus::APPROVED->value,
            'prompt_sha256' => hash('sha256', 'kf'.$scene->id.$path),
        ]);

        $artifact->design_image_id = $image->id;
        $artifact->save();

        $image->selected_artifact_id = $artifact->id;
        $image->save();

        return $artifact;
    }

    private function shotFor(VideoRenderScene $scene): VideoShot
    {
        return VideoShot::create([
            'session_id' => $this->session->id,
            'scene_id' => $scene->id,
            'beat' => (string) $scene->scene_code,
            'shot_code' => (string) $scene->scene_code.'_'.uniqid(),
            'shot_type' => 'establish',
            'kind' => 'motion',
            'spec_json' => ['duration_seconds' => 2, 'motion' => ['x'], 'camera' => []],
            'compiled_prompt' => 'the camera pushes in',
            'scene_status' => 'keyframe_ready',
            'to_state_id' => 'state_b',
        ]);
    }

    private function sourceOf(VideoShot $shot): VideoArtifact
    {
        $image = VideoDesignImage::query()
            ->where('render_scene_id', $shot->scene_id)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->firstOrFail();

        return VideoArtifact::query()->whereKey($image->selected_artifact_id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function specOf(VideoRender $render): array
    {
        return (array) json_decode((string) $render->render_request_json, true);
    }

    /**
     * `$shade` ton tai de anh dau va anh cuoi KHAC BYTES nhau.
     *
     * Neu hai khung cung mot chuoi byte thi test day dien khong phan biet duoc
     * `lastFrame` voi `image`: mot payload chep nham anh dau vao ca hai cho van
     * xanh. Ma do dung la thu duy nhat tinh nang nay phai lam dung.
     */
    private function pngBytes(int $width = 8, int $height = 8, int $shade = 0): string
    {
        $image = imagecreatetruecolor($width, $height);

        if ($shade !== 0) {
            imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, $shade, $shade, $shade));
        }

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
