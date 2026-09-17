<?php

namespace Tests\Feature\Video;

use App\Models\VideoProject;
use App\Models\VideoRender;
use App\Models\VideoSession;
use App\Video\Media\FfmpegBinary;
use App\Video\Media\Mp4Probe;
use App\Video\Render\Enums\RenderStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `video:compose-preview` la bien gioi chan doan.
 *
 * Bon cua o day la ly do `-safe 0` ben trong `ClipConcatenator` an toan: duong dan
 * di vao ffmpeg deu da qua kiem loai render, trang thai, su ton tai cua file, va
 * sha256 doi chieu `primary_artifact_hash`.
 */
class VideoComposePreviewTest extends TestCase
{
    use DatabaseTransactions;

    private VideoProject $project;

    private VideoSession $session;

    private string $previewDir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('video_artifacts');

        $this->previewDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'preview_'.uniqid();
        config(['video.veo.preview_dir' => $this->previewDir]);

        $this->project = VideoProject::create(['title' => 'TEST preview '.uniqid()]);
        $this->session = VideoSession::create([
            'project_id' => $this->project->id,
            'code' => 'pv_'.uniqid(),
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->previewDir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->previewDir);

        parent::tearDown();
    }

    private function clip(string $fixture = 'pair_a.mp4', array $override = []): VideoRender
    {
        $bytes = (string) file_get_contents(base_path('tests/Fixtures/Video/'.$fixture));
        $path = 'video/'.Str::uuid()->toString().'/clip.mp4';

        Storage::disk('video_artifacts')->put($path, $bytes);

        return VideoRender::create(array_replace([
            'video_session_id' => $this->session->id,
            'asset_id' => 'preview',
            'render_kind' => 'video',
            'provider' => 'gemini',
            'model' => 'veo-3.1-lite-generate-preview',
            'sent_prompt' => 'p',
            'prompt_sha256' => hash('sha256', 'p'),
            'request_hash' => hash('sha256', $path),
            'render_request_json' => '{}',
            'idempotency_key' => 'preview:'.uniqid('', true),
            'execution_status' => RenderStatus::SUCCEEDED,
            'attempt_count' => 1,
            'max_attempts' => 3,
            'artifact_path' => $path,
            'primary_artifact_hash' => hash('sha256', $bytes),
        ], $override));
    }

    private function skipWithoutFfmpeg(): void
    {
        if (! app(FfmpegBinary::class)->available() || ! app(Mp4Probe::class)->available()) {
            $this->markTestSkipped('may nay khong chay duoc ffmpeg/ffprobe');
        }
    }

    public function test_one_render_is_refused(): void
    {
        $this->artisan('video:compose-preview', ['--renders' => $this->clip()->id])
            ->expectsOutputToContain('Can it nhat hai render')
            ->assertExitCode(1);
    }

    public function test_a_repeated_id_is_refused(): void
    {
        $id = $this->clip()->id;

        $this->artisan('video:compose-preview', ['--renders' => $id.','.$id])
            ->expectsOutputToContain('Co id bi lap')
            ->assertExitCode(1);
    }

    public function test_a_render_that_is_not_a_video_is_refused(): void
    {
        $a = $this->clip();
        $b = $this->clip(override: ['render_kind' => 'image']);

        $this->artisan('video:compose-preview', ['--renders' => $a->id.','.$b->id])
            ->expectsOutputToContain('khong phai video')
            ->assertExitCode(1);
    }

    public function test_a_render_that_never_succeeded_is_refused(): void
    {
        $a = $this->clip();
        $b = $this->clip(override: ['execution_status' => RenderStatus::QUEUED]);

        $this->artisan('video:compose-preview', ['--renders' => $a->id.','.$b->id])
            ->expectsOutputToContain('chua succeeded')
            ->assertExitCode(1);
    }

    public function test_a_file_rewritten_behind_its_hash_is_refused(): void
    {
        $a = $this->clip();
        $b = $this->clip();

        // Day la cua quan trong nhat: sau no duong dan di thang vao ffmpeg voi
        // `-safe 0`. Mot file da bi thay the khong duoc lot qua chi vi no van nam
        // dung cho.
        Storage::disk('video_artifacts')->put($b->artifact_path, 'khong con la mp4 nua');

        $this->artisan('video:compose-preview', ['--renders' => $a->id.','.$b->id])
            ->expectsOutputToContain('sha256 lech')
            ->assertExitCode(1);
    }

    public function test_a_missing_file_is_refused(): void
    {
        $a = $this->clip();
        $b = $this->clip();

        Storage::disk('video_artifacts')->delete($b->artifact_path);

        $this->artisan('video:compose-preview', ['--renders' => $a->id.','.$b->id])
            ->expectsOutputToContain('khong con tren dia')
            ->assertExitCode(1);
    }

    /** @dataProvider badOutputNames */
    public function test_an_output_name_that_escapes_the_preview_directory_is_refused(
        string $name,
        string $said,
    ): void {
        $a = $this->clip();
        $b = $this->clip();

        // TU CHOI chu khong cat am tham: `basename()` se lang le doi cho ghi, roi
        // nguoi chay di tim file o noi ho da go.
        $this->artisan('video:compose-preview', [
            '--renders' => $a->id.','.$b->id,
            '--out' => $name,
        ])
            ->expectsOutputToContain($said)
            ->assertExitCode(1);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function badOutputNames(): array
    {
        return [
            'di len tren' => ['../x.mp4', 'khong duoc chua ".."'],
            'duong dan' => ['a/b.mp4', 'chi nhan TEN TEP'],
            'duong dan windows' => ['a\\b.mp4', 'chi nhan TEN TEP'],
            'duoi khac' => ['x.mov', 'phai ket thuc bang .mp4'],
        ];
    }

    public function test_it_refuses_to_overwrite_an_existing_preview(): void
    {
        $a = $this->clip();
        $b = $this->clip();

        @mkdir($this->previewDir, 0775, true);
        file_put_contents($this->previewDir.DIRECTORY_SEPARATOR.'da-co.mp4', 'cu');

        $this->artisan('video:compose-preview', [
            '--renders' => $a->id.','.$b->id,
            '--out' => 'da-co.mp4',
        ])
            ->expectsOutputToContain('da ton tai')
            ->assertExitCode(1);
    }

    public function test_two_compatible_clips_compose_and_nothing_is_written_to_the_database(): void
    {
        $this->skipWithoutFfmpeg();

        $a = $this->clip('pair_a.mp4');
        $b = $this->clip('pair_b.mp4');

        $before = VideoRender::query()->count();

        $this->artisan('video:compose-preview', [
            '--renders' => $a->id.','.$b->id,
            '--out' => 'ket-qua.mp4',
        ])
            ->expectsOutputToContain('khung hinh : 48')
            ->assertExitCode(0);

        $this->assertFileExists($this->previewDir.DIRECTORY_SEPARATOR.'ket-qua.mp4');
        $this->assertSame($before, VideoRender::query()->count(), 'cong cu chan doan khong duoc ghi DB');
    }

    public function test_clips_that_do_not_match_are_refused_before_ffmpeg(): void
    {
        $this->skipWithoutFfmpeg();

        $a = $this->clip('pair_a.mp4');
        $b = $this->clip('mismatch.mp4');

        $this->artisan('video:compose-preview', ['--renders' => $a->id.','.$b->id])
            ->expectsOutputToContain('stream_metadata_mismatch')
            ->expectsOutputToContain('video.r_frame_rate')
            ->assertExitCode(1);
    }
}
