<?php

namespace Tests\Feature\Video;

use App\Services\Video\GeminiImageClient;
use App\Video\Media\MediaModelRegistry;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GeminiImageClientTest extends TestCase
{
    /** @var list<string> */
    public const PNG_FUNCTIONS = [
        'imagecreatefromstring', 'imagepng', 'imagepalettetotruecolor', 'imagesavealpha',
    ];

    private const KEY = 'test-gemini-key-never-real';

    private const ENDPOINT = 'https://gemini.test/v1/models/gemini-3.1-flash-lite-image:generateContent';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('gemini_test');
    }

    public function test_a_missing_key_sends_nothing(): void
    {
        Http::fake();

        $result = $this->client('')->generate($this->spec(), 30);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('GEMINI_API_KEY', $result['error']);
        Http::assertNothingSent();
    }

    public function test_a_machine_that_cannot_write_png_never_pays_for_an_image(): void
    {
        Http::fake();

        $result = $this->client(pngFunctions: ['imagepng', 'imagecreatefromnothing'])
            ->generate($this->spec(), 30);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('thieu ham GD imagecreatefromnothing', $result['error']);
        Http::assertNothingSent();
    }

    public function test_the_png_policy_names_the_gd_functions_this_machine_really_has(): void
    {
        $required = (new \ReflectionClass(GeminiImageClient::class))->getConstant('PNG_FUNCTIONS');

        $this->assertSame(self::PNG_FUNCTIONS, $required, 'yeu cau cua client va cua test da lech nhau');

        foreach ($required as $function) {
            $this->assertTrue(function_exists($function), 'GD thieu '.$function);
        }
    }

    public function test_a_model_outside_the_registry_sends_nothing(): void
    {
        $this->assertRefusedBeforeHttp(['model' => 'gemini-2.5-flash-image'], 'khong co trong registry');
    }

    public function test_a_broken_registry_fails_the_render_without_throwing(): void
    {
        Http::fake();

        $entries = config('video.media_models.image.environment_plate');
        $entries[1]['default'] = true;
        config(['video.media_models.image.environment_plate' => $entries]);

        $result = $this->client()->generate($this->spec(), 30);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Registry model hong', $result['error']);
        Http::assertNothingSent();
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function refusedSpecs(): iterable
    {
        yield 'shape' => [['shape' => 'response_format_image'], 'shape'];
        yield 'api version' => [['api_version' => 'v1beta'], 'api_version'];
        yield 'aspect ratio' => [['aspect_ratio' => '16:9'], 'aspect_ratio'];
        yield 'image size' => [['image_size' => '2K'], 'image_size'];
        yield 'variations' => [['variations' => 2], '1 anh moi luot'];
        yield 'empty prompt' => [['prompt' => '  '], 'prompt rong'];
    }

    /** @param array<string, mixed> $override */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedSpecs')]
    public function test_a_spec_outside_the_registry_sends_nothing(array $override, string $reason): void
    {
        $this->assertRefusedBeforeHttp($override, $reason);
    }

    public function test_the_request_carries_image_config_to_the_v1_endpoint(): void
    {
        $this->fakeImages([$this->png(9, 16)]);

        $this->client()->generate($this->spec(), 30);

        Http::assertSent(function ($request): bool {
            $this->assertSame(self::ENDPOINT, $request->url());
            $this->assertSame('An empty location plate.', $request->data()['contents'][0]['parts'][0]['text']);
            $this->assertSame(['IMAGE'], $request->data()['generationConfig']['responseModalities']);
            $this->assertSame(
                ['aspectRatio' => '9:16', 'imageSize' => '1K'],
                $request->data()['generationConfig']['imageConfig'],
            );

            return true;
        });
    }

    public function test_the_key_travels_in_the_header_and_never_in_the_url(): void
    {
        $this->fakeImages([$this->png(9, 16)]);

        $this->client()->generate($this->spec(), 30);

        Http::assertSent(function ($request): bool {
            $this->assertSame(self::KEY, $request->header('x-goog-api-key')[0] ?? null);
            $this->assertStringNotContainsString(self::KEY, $request->url());

            return true;
        });
    }

    public function test_one_valid_image_becomes_one_render_the_ledger_can_record(): void
    {
        $png = $this->png(9, 16);
        $this->fakeImages([$png]);

        $result = $this->client()->generate($this->spec(), 30);

        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertCount(1, $result['renders']);

        $render = $result['renders'][0];

        $this->assertSame('c1:0', $render['idempotency_key']);
        $this->assertSame('gemini_test', $render['storage_disk']);
        $this->assertSame('p1/i1/renders/c1/output_000.png', $render['storage_path']);
        $this->assertSame(hash('sha256', $png), $render['artifact_sha256']);
        $this->assertSame('image/png', $render['mime_type']);
        $this->assertSame([9, 16], [$render['width'], $render['height']]);
        $this->assertNull($render['cost']);
        $this->assertSame('unpriced', $render['pricing']);

        foreach (['provider', 'model', 'render_kind', 'sent_prompt'] as $key) {
            $this->assertNotSame('', (string) ($render['render'][$key] ?? ''), 'ledger requires render.'.$key);
        }

        $this->assertSame('gemini', $render['render']['provider']);
        $this->assertSame('environment_plate', $render['render']['render_kind']);
        $this->assertFalse($render['render']['request_json']['aspect_mismatch']);

        Storage::disk('gemini_test')->assertExists('p1/i1/renders/c1/output_000.png');
    }

    public function test_snake_case_inline_data_is_read_too(): void
    {
        $this->fakeParts([['inline_data' => ['mime_type' => 'image/png', 'data' => base64_encode($this->png(9, 16))]]]);

        $this->assertTrue($this->client()->generate($this->spec(), 30)['ok']);
    }

    public function test_the_mime_comes_from_the_bytes_not_from_what_gemini_declares(): void
    {
        $this->fakeParts([['inlineData' => ['mimeType' => 'image/jpeg', 'data' => base64_encode($this->png(9, 16))]]]);

        $render = $this->client()->generate($this->spec(), 30)['renders'][0];

        $this->assertSame('image/png', $render['mime_type']);
        $this->assertStringEndsWith('.png', $render['storage_path']);
        $this->assertSame('image/jpeg', $render['render']['request_json']['mime_declared']);
        $this->assertSame('image/png', $render['render']['request_json']['mime_detected']);
    }

    public function test_a_jpeg_answer_is_stored_as_png(): void
    {
        $jpeg = $this->jpeg(9, 16);
        $this->fakeParts([['inlineData' => ['mimeType' => 'image/jpeg', 'data' => base64_encode($jpeg)]]]);

        $render = $this->client()->generate($this->spec(), 30)['renders'][0];
        $stored = Storage::disk('gemini_test')->get('p1/i1/renders/c1/output_000.png');

        $this->assertSame('p1/i1/renders/c1/output_000.png', $render['storage_path']);
        $this->assertSame('image/png', $render['mime_type']);
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($stored, 0, 8));
        $this->assertSame(hash('sha256', $stored), $render['artifact_sha256']);
        $this->assertSame(strlen($stored), $render['bytes']);
        $this->assertSame([9, 16], [$render['width'], $render['height']]);
        $this->assertSame([9, 16], array_slice((array) getimagesizefromstring($stored), 0, 2));
    }

    public function test_the_conversion_keeps_what_gemini_really_sent_on_the_record(): void
    {
        $jpeg = $this->jpeg(9, 16);
        $this->fakeParts([['inlineData' => ['mimeType' => 'image/jpeg', 'data' => base64_encode($jpeg)]]]);

        $seen = $this->client()->generate($this->spec(), 30)['renders'][0]['render']['request_json'];

        $this->assertSame('image/jpeg', $seen['mime_declared']);
        $this->assertSame('image/jpeg', $seen['mime_detected']);
        $this->assertTrue($seen['converted_to_png']);
        $this->assertSame(strlen($jpeg), $seen['bytes_received']);
    }

    public function test_a_png_answer_is_stored_byte_for_byte(): void
    {
        $png = $this->png(9, 16);
        $this->fakeImages([$png]);

        $render = $this->client()->generate($this->spec(), 30)['renders'][0];

        $this->assertSame($png, Storage::disk('gemini_test')->get('p1/i1/renders/c1/output_000.png'));
        $this->assertSame(hash('sha256', $png), $render['artifact_sha256']);
        $this->assertFalse($render['render']['request_json']['converted_to_png']);
    }

    public function test_a_webp_answer_is_stored_as_png(): void
    {
        $this->fakeParts([['inlineData' => ['mimeType' => 'image/webp', 'data' => base64_encode($this->webp(9, 16))]]]);

        $render = $this->client()->generate($this->spec(), 30)['renders'][0];

        $this->assertSame('image/png', $render['mime_type']);
        $this->assertStringEndsWith('.png', $render['storage_path']);
        $this->assertTrue($render['render']['request_json']['converted_to_png']);
    }

    public function test_an_image_that_cannot_be_re_encoded_writes_nothing(): void
    {
        $broken = $this->undecodableJpeg();
        $this->fakeParts([['inlineData' => ['mimeType' => 'image/jpeg', 'data' => base64_encode($broken)]]]);

        $result = $this->client()->generate($this->spec(), 30);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('sang PNG', $result['error']);
        $this->assertSame([], Storage::disk('gemini_test')->allFiles());
    }

    public function test_bytes_that_are_not_an_image_never_reach_storage(): void
    {
        $this->fakeParts([['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode('hello')]]]);

        $result = $this->client()->generate($this->spec(), 30);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('khong phai anh cho phep', $result['error']);
        $this->assertSame([], Storage::disk('gemini_test')->allFiles());
    }

    public function test_an_image_type_outside_the_allow_list_never_reaches_storage(): void
    {
        $this->fakeParts([['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($this->gif())]]]);

        $result = $this->client()->generate($this->spec(), 30);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('image/gif', $result['error']);
        $this->assertSame([], Storage::disk('gemini_test')->allFiles());
    }

    public function test_broken_base64_never_reaches_storage(): void
    {
        $this->fakeParts([['inlineData' => ['mimeType' => 'image/png', 'data' => '%%%not base64%%%']]]);

        $result = $this->client()->generate($this->spec(), 30);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('base64 hong', $result['error']);
        $this->assertSame([], Storage::disk('gemini_test')->allFiles());
    }

    public function test_two_valid_images_fail_and_write_nothing(): void
    {
        $this->fakeImages([$this->png(9, 16), $this->png(9, 16)]);

        $result = $this->client()->generate($this->spec(), 30);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('tra 2 anh', $result['error']);
        $this->assertSame([], Storage::disk('gemini_test')->allFiles());
    }

    public function test_one_valid_image_beside_a_broken_part_still_renders_once(): void
    {
        $this->fakeParts([
            ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode('hello')]],
            ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($this->png(9, 16))]],
        ]);

        $result = $this->client()->generate($this->spec(), 30);

        $this->assertTrue($result['ok']);
        $this->assertCount(1, Storage::disk('gemini_test')->allFiles());
    }

    public function test_a_blocked_prompt_fails_with_its_reason(): void
    {
        Http::fake(['*' => Http::response(['promptFeedback' => ['blockReason' => 'SAFETY']], 200)]);

        $result = $this->client()->generate($this->spec(), 30);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('SAFETY', $result['error']);
    }

    public function test_a_success_without_an_image_fails_with_the_finish_reason(): void
    {
        Http::fake(['*' => Http::response(['candidates' => [[
            'finishReason' => 'NO_IMAGE',
            'content' => ['parts' => [['text' => 'I cannot draw that.']]],
        ]]], 200)]);

        $result = $this->client()->generate($this->spec(), 30);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('NO_IMAGE', $result['error']);
    }

    public function test_an_http_error_fails_with_the_provider_message(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Model not allowed']], 403)]);

        $result = $this->client()->generate($this->spec(), 30);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('403', $result['error']);
        $this->assertStringContainsString('Model not allowed', $result['error']);
    }

    public function test_a_connection_failure_fails_without_throwing(): void
    {
        Http::fake(fn () => throw new ConnectionException('dns went away'));

        $result = $this->client()->generate($this->spec(), 30);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Khong ket noi duoc Gemini', $result['error']);
    }

    public function test_a_429_is_retried_once_then_succeeds(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['error' => ['message' => 'rate limited']], 429)
            ->push($this->body([['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($this->png(9, 16))]]]), 200)]);

        $result = $this->client()->generate($this->spec(), 30);

        $this->assertTrue($result['ok']);
        Http::assertSentCount(2);
    }

    public function test_a_square_image_for_a_nine_sixteen_request_is_kept_and_flagged(): void
    {
        $this->fakeImages([$this->png(4, 4)]);

        $result = $this->client()->generate($this->spec(), 30);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['renders'][0]['render']['request_json']['aspect_mismatch']);
    }

    public function test_the_container_builds_the_client_without_a_gemini_key(): void
    {
        config(['video.gemini.api_key' => null]);
        $this->app->forgetInstance(GeminiImageClient::class);

        $this->assertInstanceOf(GeminiImageClient::class, app(GeminiImageClient::class));
    }

    /** @param array<string, mixed> $override */
    private function assertRefusedBeforeHttp(array $override, string $reason): void
    {
        Http::fake();

        $result = $this->client()->generate($this->spec($override), 30);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString($reason, $result['error']);
        Http::assertNothingSent();
    }

    /** @param list<string>|null $pngFunctions */
    private function client(string $key = self::KEY, ?array $pngFunctions = null): GeminiImageClient
    {
        return new GeminiImageClient(
            http: app(HttpFactory::class),
            storage: app(FilesystemFactory::class),
            registry: new MediaModelRegistry,
            apiKey: $key,
            baseUrl: 'https://gemini.test',
            disk: 'gemini_test',
            memoryLimit: '512M',
            timeoutSeconds: 30,
            pngFunctions: $pngFunctions ?? self::PNG_FUNCTIONS,
        );
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function spec(array $override = []): array
    {
        return array_replace([
            'image_id' => 'i1',
            'project_id' => 'p1',
            'claim_token' => 'c1',
            'operation' => 'environment_plate',
            'prompt' => 'An empty location plate.',
            'model' => 'gemini-3.1-flash-lite-image',
            'api_version' => 'v1',
            'shape' => 'image_config',
            'aspect_ratio' => '9:16',
            'image_size' => '1K',
            'variations' => 1,
        ], $override);
    }

    /** @param list<string> $images */
    private function fakeImages(array $images): void
    {
        $this->fakeParts(array_map(
            static fn (string $bytes): array => ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($bytes)]],
            $images,
        ));
    }

    /** @param list<array<string, mixed>> $parts */
    private function fakeParts(array $parts): void
    {
        Http::fake(['*' => Http::response($this->body($parts), 200)]);
    }

    /**
     * @param  list<array<string, mixed>>  $parts
     * @return array<string, mixed>
     */
    private function body(array $parts): array
    {
        return [
            'responseId' => 'resp-1',
            'usageMetadata' => ['totalTokenCount' => 10],
            'candidates' => [['content' => ['parts' => $parts], 'finishReason' => 'STOP']],
        ];
    }

    private function png(int $width, int $height): string
    {
        $canvas = imagecreatetruecolor($width, $height);

        ob_start();
        imagepng($canvas);
        $bytes = (string) ob_get_clean();

        return $bytes;
    }

    private function jpeg(int $width, int $height): string
    {
        $canvas = imagecreatetruecolor($width, $height);

        ob_start();
        imagejpeg($canvas, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($canvas);

        return $bytes;
    }

    private function webp(int $width, int $height): string
    {
        $canvas = imagecreatetruecolor($width, $height);

        ob_start();
        imagewebp($canvas);
        $bytes = (string) ob_get_clean();
        imagedestroy($canvas);

        return $bytes;
    }

    /**
     * Mot tam JPEG cat doi: doc duoc kich thuoc nhung khong giai ma duoc. Do dai
     * cat do GD cua may nay quyet dinh, nen tim bang thu that thay vi hang so.
     */
    private function undecodableJpeg(): string
    {
        $jpeg = $this->jpeg(64, 64);

        for ($length = 200; $length < strlen($jpeg); $length += 20) {
            $cut = substr($jpeg, 0, $length);
            $canvas = @imagecreatefromstring($cut);

            if ($canvas !== false) {
                imagedestroy($canvas);

                continue;
            }

            if (is_array(@getimagesizefromstring($cut))) {
                return $cut;
            }
        }

        $this->markTestSkipped('GD cua may nay khong tao ra duoc tam JPEG hong kieu do');
    }

    private function gif(): string
    {
        $canvas = imagecreatetruecolor(2, 2);

        ob_start();
        imagegif($canvas);

        return (string) ob_get_clean();
    }
}
