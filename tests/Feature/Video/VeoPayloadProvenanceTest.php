<?php

namespace Tests\Feature\Video;

use App\Models\VideoArtifact;
use App\Models\VideoProject;
use App\Models\VideoRender;
use App\Models\VideoSession;
use App\Models\VideoShot;
use App\Video\Render\Enums\RenderStatus;
use App\Video\Render\Video\SceneClipDispatchService;
use App\Video\Render\Video\VideoRenderExecutionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * KHONG mot gia tri nao roi khoi may ma khong truy duoc ve `render_request_json`.
 *
 * Spec la thu duy nhat `request_hash` phu len. Cai gi duoc gui ma khong nam trong
 * spec thi khong ai doi soat duoc, va cung khong tai lap duoc lan render do.
 */
class VeoPayloadProvenanceTest extends TestCase
{
    use DatabaseTransactions;

    private const MODEL = 'veo-3.1-lite-generate-preview';

    private VideoProject $project;

    private VideoArtifact $source;

    private string $evidenceDir;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Storage::fake('video_artifacts');

        $this->evidenceDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'veo_prov_'.uniqid();
        mkdir($this->evidenceDir, 0775, true);
        file_put_contents($this->evidenceDir.DIRECTORY_SEPARATOR.'models.json', (string) json_encode([
            // Ca hai phien ban deu co trong bang chung, de bai test duoi tach bach
            // duoc HAI thu: registry cho phep gi, va spec da chot gi.
            'versions' => array_fill_keys(['v1beta', 'v1'], [[
                'name' => 'models/'.self::MODEL,
                'supportedGenerationMethods' => ['predictLongRunning'],
            ]]),
        ]));

        config([
            'video.provider_evidence_dir' => $this->evidenceDir,
            'video.gemini.api_key' => 'test-key',
            'video.media_models.video.scene_clip' => [[
                'id' => 'gemini:'.self::MODEL,
                'provider' => 'gemini',
                'model' => self::MODEL,
                'label' => 'Veo lite',
                'default' => true,
                'api_version' => 'v1beta',
                'mode' => 'async',
                'method' => 'predictLongRunning',
                'controls' => [
                    'durations' => [4, 6, 8],
                    'default_duration' => 8,
                    'aspect_ratios' => ['9:16', '16:9'],
                    'default_aspect_ratio' => '9:16',
                    'resolutions' => ['720p', '1080p'],
                    'default_resolution' => '720p',
                    'long_resolutions' => ['1080p'],
                    'long_resolution_duration' => 8,
                ],
                'evidence' => ['models' => 'models.json'],
            ]],
        ]);

        $this->project = VideoProject::create(['title' => 'TEST provenance '.uniqid()]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->evidenceDir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->evidenceDir);

        parent::tearDown();
    }

    public function test_every_value_sent_to_veo_comes_from_the_frozen_request(): void
    {
        Http::fake(['*:predictLongRunning' => Http::response(['name' => 'ops/x'])]);

        $shot = $this->shot();

        // Chon KHAC mac dinh de mot gia tri bi cam trong code se lo ra. Duration giu
        // 8 vi 1080p doi dung 8 giay; kieu cua no da co test rieng.
        $render = app(SceneClipDispatchService::class)->create(
            $shot, $this->source, 'gemini:'.self::MODEL,
            ['duration_seconds' => 8, 'aspect_ratio' => '16:9', 'resolution' => '1080p'],
        );

        $this->markUnconstrained($render);

        [$submitted, $reason] = app(VideoRenderExecutionService::class)->submit($render->id);

        $this->assertTrue($submitted, $reason);

        $spec = json_decode((string) $render->refresh()->render_request_json, true);

        Http::assertSent(function ($request) use ($spec): bool {
            $body = $request->data();
            $instance = $body['instances'][0];
            $parameters = $body['parameters'];

            $this->assertSame($spec['compiled_prompt']['prompt'], $instance['prompt']);
            $this->assertSame(SceneClipDispatchService::PROVIDER_PAYLOAD_VERSION, $spec['provider_payload_version']);
            $this->assertSame($spec['source_artifact']['mime'], $instance['image']['mimeType']);
            $this->assertSame(
                $spec['source_artifact']['sha256'],
                hash('sha256', (string) base64_decode($instance['image']['bytesBase64Encoded'], true)),
            );
            $this->assertArrayNotHasKey('inlineData', $instance['image']);

            $this->assertSame($spec['aspect_ratio'], $parameters['aspectRatio']);
            $this->assertSame($spec['resolution'], $parameters['resolution']);
            $this->assertSame($spec['duration_seconds'], $parameters['durationSeconds']);
            $this->assertSame($spec['person_generation'], $parameters['personGeneration']);

            // Khong co truong nao ngoai bon truong da dong bang.
            $this->assertSame(
                ['aspectRatio', 'resolution', 'durationSeconds', 'personGeneration'],
                array_keys($parameters),
            );

            // URL cung phai dung tu spec.
            $this->assertStringContainsString('/'.$spec['api_version'].'/models/'.$spec['model'].':', $request->url());

            return true;
        });
    }

    public function test_the_url_follows_the_frozen_spec_not_todays_registry(): void
    {
        Http::fake(['*' => Http::response(['name' => 'ops/x'])]);

        $shot = $this->shot();
        $render = app(SceneClipDispatchService::class)->create($shot, $this->source, 'gemini:'.self::MODEL);

        $this->markUnconstrained($render);

        // Registry doi api_version SAU khi spec da dong bang. Request phai di theo
        // spec, vi do moi la thu `request_hash` phu len.
        config(['video.media_models.video.scene_clip.0.api_version' => 'v1']);
        app()->forgetInstance(\App\Video\Media\VideoModelRegistry::class);

        app(VideoRenderExecutionService::class)->submit($render->id);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v1beta/models/'.self::MODEL.':'));
    }

    /**
     * Cot `render_request_json` da mang du thong tin; hang render chi can o trang
     * thai claim duoc.
     */
    private function markUnconstrained(VideoRender $render): void
    {
        $this->assertSame(RenderStatus::QUEUED, $render->execution_status);
    }

    private function shot(): VideoShot
    {
        $png = $this->pngBytes();
        Storage::disk('video_artifacts')->put('scene/kf.png', $png);


        $session = VideoSession::create([
            'project_id' => $this->project->id,
            'code' => 'prov_'.uniqid(),
        ]);

        $keyframe = VideoRender::create([
            'video_session_id' => $session->id,
            'asset_id' => 'scene_a',
            'render_kind' => 'image',
            'provider' => 'openai',
            'model' => 'gpt-image-2',
            'sent_prompt' => 'p',
            'prompt_sha256' => hash('sha256', 'p'),
            'request_hash' => str_repeat('a', 64),
            'render_request_json' => '{"version":"render-request-v1"}',
            'idempotency_key' => 'kf:'.uniqid(),
            'execution_status' => RenderStatus::SUCCEEDED,
            'attempt_count' => 1,
            'max_attempts' => 3,
            'artifact_path' => 'scene/kf.png',
            'primary_artifact_hash' => hash('sha256', $png),
            'canonical_hash' => str_repeat('c', 64),
            'projection_hash' => str_repeat('d', 64),
            'constraint_set_hash' => str_repeat('e', 64),
            'prompt_spec_hash' => str_repeat('f', 64),
            'provider_prompt_plan_hash' => str_repeat('0', 64),
            'prompt_hash' => str_repeat('1', 64),
        ]);

        $this->source = VideoArtifact::create([
            'project_id' => $this->project->id,
            'render_id' => $keyframe->id,
            'artifact_type' => 'image',
            'role' => 'scene_keyframe',
            'storage_disk' => 'video_artifacts',
            'storage_path' => 'scene/kf.png',
            'mime_type' => 'image/png',
            'file_size' => strlen($png),
            'sha256' => hash('sha256', $png),
        ]);

        return VideoShot::create([
            'session_id' => $session->id,
            'beat' => 'scene_a',
            'shot_code' => 'scene_a_'.uniqid(),
            'shot_type' => 'establish',
            'kind' => 'motion',
            'spec_json' => ['duration_seconds' => 8],
            'compiled_prompt' => 'the camera pushes in through the doorway',
            'scene_image_render_id' => $keyframe->id,
            'scene_status' => 'keyframe_ready',
            'to_state_id' => 'state_b',
        ]);
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
}
