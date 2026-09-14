<?php

namespace Tests\Feature\Video;

use App\Enums\DesignImageStatus;
use App\Form\AdminCustomValidator;
use App\Models\Admin;
use App\Models\Article;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Models\VideoProject;
use App\Services\Video\DesignImageDirectRenderer;
use App\Services\Video\DesignImageStore;
use App\Services\VideoProjectService;
use App\Video\Environment\EnvironmentPlatePrompt;
use Closure;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class EnvironmentGeminiDispatchTest extends TestCase
{
    use DatabaseTransactions;

    private const GEMINI_ENDPOINT = 'https://gemini.test/v1/models/gemini-3.1-flash-lite-image:generateContent';

    private const UNPROVEN = 'Chưa có lần render Environment thật nào bằng model này.';

    private VideoProject $project;

    private Admin $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('video_artifacts');

        config([
            'video.render_mode' => 'direct',
            'video.openai_image.disk' => 'video_artifacts',
            'canonical_concept.openai.api_key' => 'test-key',
            'canonical_concept.openai.base_url' => 'https://api.openai.com',
            'video.gemini.api_key' => 'test-gemini-key-never-real',
            'video.gemini.base_url' => 'https://gemini.test',
            'video.gemini.disk' => 'video_artifacts',
        ]);

        [$categoryId, $slug] = $this->category();

        config(['video.environment.profiles.'.$slug => 'vessel_v2']);

        $this->owner = $this->admin();
        $this->project = VideoProject::create([
            'title' => 'TEST environment gemini '.uniqid(),
            'article_id' => $this->article($categoryId),
            'admin_id' => $this->owner->id,
        ]);

        $this->actingAs($this->owner);
    }

    public function test_a_gemini_post_travels_the_whole_route_to_gemini_and_never_to_openai(): void
    {
        $this->fakeProviders();

        $this->post($this->url(), $this->geminiSettings())->assertRedirect($this->url());

        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::GEMINI_ENDPOINT);
        Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), 'openai.com'));

        $image = $this->plates()->sole();

        $this->assertSame(DesignImageStatus::RENDERED->value, $image->status);
        $this->assertSame(1, VideoArtifact::query()->where('design_image_id', $image->id)->count());

        $ledger = DB::table('video_cost_entries')->where('entity_id', $image->id)->get();

        $this->assertCount(1, $ledger);
        $this->assertSame('gemini', $ledger[0]->provider);
        $this->assertSame('unpriced', json_decode((string) $ledger[0]->metadata_json, true)['pricing']);
    }

    public function test_the_spec_a_gemini_post_persists_comes_from_the_registry(): void
    {
        $this->fakeProviders();

        $this->post($this->url(), $this->geminiSettings());

        $spec = $this->plates()->sole()->prompt_spec_json;

        $this->assertSame('gemini', $spec['provider']);
        $this->assertSame('gemini-3.1-flash-lite-image', $spec['model']);
        $this->assertSame('unpriced', $spec['pricing']);
        $this->assertSame('v1', $spec['api_version']);
        $this->assertSame('image_config', $spec['shape']);
        $this->assertSame('9:16', $spec['aspect_ratio']);
        $this->assertSame('1K', $spec['image_size']);
        $this->assertSame('9:16@1K', $spec['size']);
        $this->assertArrayHasKey('quality', $spec);
        $this->assertNull($spec['quality']);
    }

    public function test_the_gemini_request_carries_the_image_config_the_spec_kept(): void
    {
        $this->fakeProviders();

        $this->post($this->url(), $this->geminiSettings());

        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::GEMINI_ENDPOINT
            && ($request->data()['generationConfig']['imageConfig'] ?? null) === [
                'aspectRatio' => '9:16',
                'imageSize' => '1K',
            ]);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function forgedRoutingFields(): iterable
    {
        yield 'provider' => ['provider', 'fal'];
        yield 'model' => ['model', 'gpt-image-2'];
        yield 'api version' => ['api_version', 'v1beta'];
        yield 'shape' => ['shape', 'response_format_image'];
        yield 'pricing' => ['pricing', 'free'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forgedRoutingFields')]
    public function test_a_forged_routing_field_is_refused_before_any_request(string $field, string $value): void
    {
        Http::fake();

        foreach ([$this->geminiSettings(), $this->openaiSettings()] as $settings) {
            $this->post($this->url(), [$field => $value] + $settings)->assertSessionHasErrors($field);
        }

        $this->assertSame(0, $this->plates()->count());
        Http::assertNothingSent();
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function crossedControls(): iterable
    {
        yield 'gemini with a quality' => [['quality' => 'low'], 'quality'];
        yield 'gemini with an openai size' => [['size' => '1152x2048'], 'size'];
        yield 'gemini with two variations' => [['variations' => 2], 'variations'];
        yield 'a model outside the registry' => [['provider_model' => 'gemini:gemini-2.5-flash-image'], 'provider_model'];
    }

    /** @param array<string, mixed> $override */
    #[\PHPUnit\Framework\Attributes\DataProvider('crossedControls')]
    public function test_a_control_that_does_not_belong_to_the_chosen_gemini_model_is_refused(array $override, string $field): void
    {
        Http::fake();

        $this->post($this->url(), $override + $this->geminiSettings())->assertSessionHasErrors($field);

        $this->assertSame(0, $this->plates()->count());
        Http::assertNothingSent();
    }

    public function test_an_openai_post_carrying_a_gemini_control_is_refused(): void
    {
        Http::fake();

        $this->post($this->url(), ['aspect_ratio' => '9:16'] + $this->openaiSettings())
            ->assertSessionHasErrors('aspect_ratio');

        $this->assertSame(0, $this->plates()->count());
        Http::assertNothingSent();
    }

    public function test_a_legacy_openai_cell_without_a_provider_still_goes_to_openai(): void
    {
        $this->fakeProviders();

        $image = $this->cell([
            'operation' => 'environment_plate',
            'model' => 'gpt-image-2',
            'quality' => 'low',
            'size' => '1152x2048',
            'variations' => 1,
        ]);

        [$done, $reason] = app(DesignImageDirectRenderer::class)->renderNow($image->id);

        $this->assertSame('rendered', $reason, (string) $done?->render_error);
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/v1/images/generations')
            && ($request->data()['quality'] ?? null) === 'low');
        Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), 'gemini.test'));
    }

    public function test_a_stored_cell_with_an_unknown_provider_fails_before_any_request(): void
    {
        Http::fake();

        $image = $this->cell([
            'operation' => 'environment_plate',
            'provider' => 'fal',
            'model' => 'flux-dev',
            'size' => '1152x2048',
            'variations' => 1,
        ]);

        [$done, $reason] = app(DesignImageDirectRenderer::class)->renderNow($image->id);

        $this->assertSame('failed', $reason);
        $this->assertStringContainsString('Provider chua co client', (string) $done->render_error);
        Http::assertNothingSent();
    }

    public function test_a_stored_gemini_cell_for_another_operation_fails_before_any_request(): void
    {
        Http::fake();

        $image = $this->cell($this->geminiSpec(['operation' => 'generate']));

        [$done, $reason] = app(DesignImageDirectRenderer::class)->renderNow($image->id);

        $this->assertSame('failed', $reason);
        $this->assertStringContainsString('chi duoc dung cho environment_plate', (string) $done->render_error);
        Http::assertNothingSent();
    }

    public function test_a_broken_registry_keeps_the_page_readable_but_offers_no_render(): void
    {
        $pending = $this->cell($this->geminiSpec());
        $this->breakRegistry();

        $response = $this->get($this->url());

        $response->assertOk();
        $response->assertSee(__('messages.environment_media_models_broken'), false);
        $response->assertSee('An empty location plate.', false);
        $response->assertSee($pending->image_code, false);

        foreach (['id="envForm_', 'name="provider_model"', 'confirmEnv_', 'Render Plate', 'renderEnv_'] as $control) {
            $response->assertDontSee($control, false);
        }
    }

    public function test_a_broken_registry_refuses_a_post_before_any_request(): void
    {
        Http::fake();
        $this->breakRegistry();

        $this->post($this->url(), $this->openaiSettings())
            ->assertSessionHas('error', __('messages.environment_media_models_broken'));

        $this->assertSame(0, $this->plates()->count());
        Http::assertNothingSent();
    }

    public function test_a_broken_registry_never_stops_an_openai_anchor_render(): void
    {
        $this->fakeProviders();
        $this->breakRegistry();

        $anchor = VideoDesignImage::create([
            'project_id' => $this->project->id,
            'image_code' => 'anchor_'.uniqid(),
            'image_type' => DesignImageStore::ANCHOR_TYPE,
            'prompt_spec_json' => [
                'operation' => 'generate',
                'prompt' => 'CAMERA: front three-quarter. SUBJECT: a hull.',
                'model' => 'gpt-image-2',
                'quality' => 'low',
                'size' => '1152x2048',
                'variations' => 1,
            ],
            'prompt_sha256' => hash('sha256', uniqid('', true)),
            'status' => DesignImageStatus::CANDIDATE->value,
            'revision' => 1,
        ]);

        [$done, $reason] = app(DesignImageDirectRenderer::class)->renderNow($anchor->id);

        $this->assertSame('rendered', $reason, (string) $done?->render_error);
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/v1/images/generations'));
    }

    public function test_the_service_turns_a_broken_registry_into_a_reason(): void
    {
        Http::fake();
        $this->breakRegistry();

        $this->assertSame(
            [null, 'environment_media_models_broken'],
            $this->renderDirect($this->openaiSettings()),
        );

        $this->assertSame(0, $this->plates()->count());
        Http::assertNothingSent();
    }

    public function test_the_service_refuses_a_model_the_registry_does_not_list(): void
    {
        Http::fake();

        $this->assertSame(
            [null, 'environment_unknown_media_model'],
            $this->renderDirect(['provider_model' => 'gemini:gemini-2.5-flash-image'] + $this->geminiSettings()),
        );

        $this->assertSame(0, $this->plates()->count());
        Http::assertNothingSent();
    }

    /** @return iterable<string, array{0: string, 1: array<string, mixed>}> */
    public static function settingsOutsideTheRegistry(): iterable
    {
        yield 'openai size' => ['openai', ['size' => '4096x4096']];
        yield 'openai quality' => ['openai', ['quality' => 'auto']];
        yield 'openai missing size' => ['openai', ['size' => null]];
        yield 'openai three variations' => ['openai', ['variations' => '3']];
        yield 'gemini image size' => ['gemini', ['image_size' => '2K']];
        yield 'gemini aspect ratio' => ['gemini', ['aspect_ratio' => '7:9']];
        yield 'gemini two variations' => ['gemini', ['variations' => '2']];
        yield 'zero variations' => ['openai', ['variations' => '0']];
        yield 'fractional variations' => ['openai', ['variations' => '1.5']];
        yield 'boolean variations' => ['openai', ['variations' => true]];
        yield 'missing variations' => ['openai', ['variations' => null]];
    }

    /** @param array<string, mixed> $override */
    #[\PHPUnit\Framework\Attributes\DataProvider('settingsOutsideTheRegistry')]
    public function test_the_service_rechecks_every_control_against_the_registry(string $provider, array $override): void
    {
        Http::fake();

        $settings = array_replace(
            $provider === 'openai' ? $this->openaiSettings() : $this->geminiSettings(),
            $override,
        );

        $this->assertSame([null, 'environment_media_setting_invalid'], $this->renderDirect($settings));

        $this->assertSame(0, $this->plates()->count());
        Http::assertNothingSent();
    }

    public function test_the_service_recheck_lets_a_valid_openai_request_through(): void
    {
        $this->fakeProviders();

        [$image, $reason] = $this->renderDirect(['variations' => '2'] + $this->openaiSettings());

        $this->assertSame('rendered', $reason, (string) $image?->render_error);
        $this->assertSame(2, $image->prompt_spec_json['variations']);
    }

    public function test_a_registry_that_breaks_after_validation_stops_at_the_service(): void
    {
        Http::fake();
        Log::spy();

        $this->afterValidation(fn () => $this->breakRegistry());

        $this->post($this->url(), $this->openaiSettings())
            ->assertSessionHas('error', __('messages.environment_media_models_broken'));

        Log::shouldHaveReceived('error')
            ->with('environment: registry model hong khi render', Mockery::type('array'))
            ->once();
        $this->assertSame(0, $this->plates()->count());
        Http::assertNothingSent();
    }

    public function test_a_control_removed_after_validation_stops_at_the_service(): void
    {
        Http::fake();

        $this->afterValidation(function () {
            $sizes = config('video.media_models.image.environment_plate.0.controls.sizes');

            config(['video.media_models.image.environment_plate.0.controls.sizes' => array_values(
                array_diff($sizes, ['1536x1024']),
            )]);
        });

        $this->post($this->url(), ['size' => '1536x1024'] + $this->openaiSettings())
            ->assertSessionHas('error', __('messages.environment_media_setting_invalid'));

        $this->assertSame(0, $this->plates()->count());
        Http::assertNothingSent();
    }

    public function test_a_model_without_a_real_environment_render_is_flagged(): void
    {
        $id = $this->isolateOpenAiModel();

        $this->assertStringContainsString(self::UNPROVEN, $this->groupHtml($id));
    }

    public function test_a_succeeded_environment_render_clears_the_flag_for_that_model(): void
    {
        $id = $this->isolateOpenAiModel();
        $this->fakeProviders();

        $this->post($this->url(), $this->openaiSettings(['provider_model' => $id]))->assertRedirect($this->url());

        $this->assertStringNotContainsString(self::UNPROVEN, $this->groupHtml($id));
    }

    /** @return iterable<string, array{0: array<string, string>}> */
    public static function rendersThatProveNothing(): iterable
    {
        yield 'an anchor render' => [['render_kind' => 'generate']];
        yield 'a failed render' => [['status' => 'failed']];
    }

    /** @param array<string, string> $change */
    #[\PHPUnit\Framework\Attributes\DataProvider('rendersThatProveNothing')]
    public function test_a_render_that_is_not_a_succeeded_environment_render_keeps_the_flag(array $change): void
    {
        $id = $this->isolateOpenAiModel();
        $this->fakeProviders();

        $this->post($this->url(), $this->openaiSettings(['provider_model' => $id]));

        DB::table('video_renders')
            ->where('design_image_id', $this->plates()->sole()->id)
            ->update($change);

        $this->assertStringContainsString(self::UNPROVEN, $this->groupHtml($id));
    }

    /** @return array{0: ?VideoDesignImage, 1: string} */
    private function renderDirect(array $settings): array
    {
        return app(VideoProjectService::class)->renderEnvironmentDirect($this->project->id, 'tester', $settings);
    }

    private function afterValidation(Closure $mutate): void
    {
        $this->app->instance(AdminCustomValidator::class, new class($mutate) extends AdminCustomValidator
        {
            public function __construct(private Closure $mutate) {}

            public function validate($request, string $class, ...$params)
            {
                $data = parent::validate($request, $class, ...$params);
                ($this->mutate)();

                return $data;
            }
        });
    }

    private function breakRegistry(): void
    {
        config(['video.media_models.image.environment_plate.0.default' => false]);
    }

    private function isolateOpenAiModel(): string
    {
        $model = 'gpt-image-test-'.uniqid();

        config([
            'video.media_models.image.environment_plate.0.model' => $model,
            'video.media_models.image.environment_plate.0.id' => 'openai:'.$model,
        ]);

        return 'openai:'.$model;
    }

    private function groupHtml(string $id): string
    {
        $html = $this->get($this->url())->assertOk()->getContent();
        $start = strpos($html, 'data-group="'.$id.'"');

        $this->assertNotFalse($start, 'group '.$id.' is not on the page');

        $end = strpos($html, 'data-group="', $start + 1);

        return substr($html, $start, $end === false ? null : $end - $start);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function geminiSettings(array $override = []): array
    {
        return $override + [
            'environment_key' => 'paint_shed',
            'provider_model' => 'gemini:gemini-3.1-flash-lite-image',
            'aspect_ratio' => '9:16',
            'image_size' => '1K',
            'variations' => '1',
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function openaiSettings(array $override = []): array
    {
        return $override + [
            'environment_key' => 'paint_shed',
            'provider_model' => 'openai:gpt-image-2',
            'quality' => 'low',
            'size' => '1152x2048',
            'variations' => '1',
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function geminiSpec(array $override = []): array
    {
        return array_replace([
            'operation' => 'environment_plate',
            'spec_version' => EnvironmentPlatePrompt::VERSION,
            'environment_key' => 'paint_shed',
            'prompt' => EnvironmentPlatePrompt::text('A sealed white paint shed.'),
            'provider' => 'gemini',
            'model' => 'gemini-3.1-flash-lite-image',
            'pricing' => 'unpriced',
            'api_version' => 'v1',
            'shape' => 'image_config',
            'aspect_ratio' => '9:16',
            'image_size' => '1K',
            'size' => '9:16@1K',
            'quality' => null,
            'variations' => 1,
        ], $override);
    }

    /** @param array<string, mixed> $spec */
    private function cell(array $spec): VideoDesignImage
    {
        return VideoDesignImage::create([
            'project_id' => $this->project->id,
            'image_code' => 'env_'.uniqid(),
            'image_type' => DesignImageStore::ENVIRONMENT_TYPE,
            'environment_key' => 'paint_shed',
            'prompt_spec_json' => $spec + [
                'spec_version' => EnvironmentPlatePrompt::VERSION,
                'environment_key' => 'paint_shed',
                'prompt' => EnvironmentPlatePrompt::text('A sealed white paint shed.'),
            ],
            'prompt_sha256' => hash('sha256', uniqid('', true)),
            'status' => DesignImageStatus::CANDIDATE->value,
            'revision' => 1,
        ]);
    }

    private function url(): string
    {
        return route('video-projects.environment', $this->project->id);
    }

    /** @return \Illuminate\Database\Eloquent\Builder<VideoDesignImage> */
    private function plates()
    {
        return VideoDesignImage::query()
            ->where('project_id', $this->project->id)
            ->where('image_type', DesignImageStore::ENVIRONMENT_TYPE);
    }

    private function fakeProviders(): void
    {
        Http::fake([
            'gemini.test/*' => Http::response([
                'responseId' => 'resp-1',
                'usageMetadata' => ['totalTokenCount' => 10],
                'candidates' => [[
                    'content' => ['parts' => [[
                        'inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($this->png(9, 16))],
                    ]]],
                    'finishReason' => 'STOP',
                ]],
            ], 200),
            '*/v1/images/generations' => Http::response([
                'created' => 1,
                'size' => '1152x2048',
                'quality' => 'low',
                'output_format' => 'png',
                'usage' => ['total_tokens' => 10],
                'data' => [['b64_json' => base64_encode($this->png(9, 16))]],
            ], 200),
        ]);
    }

    private function png(int $width, int $height): string
    {
        $canvas = imagecreatetruecolor($width, $height);

        ob_start();
        imagepng($canvas);
        $bytes = (string) ob_get_clean();
        imagedestroy($canvas);

        return $bytes;
    }

    /** @return array{0: string, 1: string} */
    private function category(): array
    {
        $id = (string) Str::uuid();
        $slug = 'test-env-gemini-'.uniqid();

        DB::table('categories')->insert([
            'id' => $id,
            'name' => 'TEST environment gemini category '.uniqid(),
            'slug' => $slug,
        ]);

        return [$id, $slug];
    }

    private function article(string $categoryId): string
    {
        $keywordId = (string) Str::uuid();

        DB::table('keywords')->insert([
            'id' => $keywordId,
            'name' => 'TEST environment gemini keyword '.uniqid(),
            'category_id' => $categoryId,
        ]);

        return (string) Article::create([
            'keyword_id' => $keywordId,
            'category_id' => $categoryId,
            'source_url' => 'https://example.com/'.uniqid(),
            'source_url_hash' => md5(uniqid('', true)),
            'source_title' => 'TEST environment gemini source',
            'title' => 'TEST environment gemini article '.uniqid(),
            'slug' => 'test-environment-gemini-'.uniqid(),
            'content' => 'A yard begins a new steel motor yacht.',
            'status' => 'pending',
        ])->id;
    }

    private function admin(): Admin
    {
        $roleId = (string) Str::uuid();

        DB::table('roles')->insert(['id' => $roleId, 'name' => 'member']);

        return Admin::create([
            'name' => 'TEST environment gemini admin '.uniqid(),
            'email' => 'test_environment_gemini_'.uniqid().'@example.com',
            'password' => bcrypt('secret'),
            'role_id' => $roleId,
        ]);
    }
}
