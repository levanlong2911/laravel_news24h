<?php

namespace Tests\Feature\Video;

use App\Services\Video\OpenAiImageClient;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OpenAiImageClientTest extends TestCase
{
    private const PNG_3X5 = 'iVBORw0KGgoAAAANSUhEUgAAAAMAAAAFCAIAAAAPE8H1AAAACXBIWXMAAA7EAAAOxAGVKw4b'
        .'AAAAF0lEQVQImWPkEpFjYGBgYGBgYoAB/CwADFgARjw2UTkAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function client(): OpenAiImageClient
    {
        return new OpenAiImageClient(
            http: app(HttpFactory::class),
            storage: app(FilesystemFactory::class),
            apiKey: 'test-key',
            baseUrl: 'https://api.openai.test',
            disk: 'local',
            memoryLimit: '512M',
            timeoutSeconds: 30,
        );
    }

    /** @return array<string, mixed> */
    private function spec(array $override = []): array
    {
        return $override + [
            'prompt' => 'Change only the hull.',
            'model' => 'gpt-image-2',
            'quality' => 'low',
            'size' => '720x1280',
            'variations' => 1,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function parts(Request $request): array
    {
        return array_values(array_filter(
            $request->data(),
            static fn (array $part): bool => isset($part['filename']),
        ));
    }

    private function fakeRefusal(): void
    {
        Http::fake([
            '*' => Http::response(['error' => ['message' => 'stopped in test']], 400),
        ]);
    }

    public function test_a_single_image_edit_still_uses_the_image_field(): void
    {
        $this->fakeRefusal();

        $this->client()->edit($this->spec(), 'anchor-bytes', 'anchor.png', 30);

        Http::assertSent(function (Request $request): bool {
            $parts = $this->parts($request);

            $this->assertCount(1, $parts);
            $this->assertSame('image', $parts[0]['name']);
            $this->assertSame('anchor.png', $parts[0]['filename']);
            $this->assertSame('anchor-bytes', $parts[0]['contents']);

            return true;
        });
    }

    public function test_many_sources_go_out_as_an_ordered_image_array(): void
    {
        $this->fakeRefusal();

        $this->client()->editWithSources($this->spec(), [
            ['bytes' => 'source-bytes', 'filename' => 'source.png'],
            ['bytes' => 'first-support', 'filename' => 'one.png'],
            ['bytes' => 'second-support', 'filename' => 'two.png'],
        ], 30);

        Http::assertSent(function (Request $request): bool {
            $parts = $this->parts($request);

            $this->assertCount(3, $parts);
            $this->assertSame(['image[]', 'image[]', 'image[]'], array_column($parts, 'name'));
            $this->assertSame(
                ['source.png', 'one.png', 'two.png'],
                array_column($parts, 'filename'),
            );
            $this->assertSame(
                ['source-bytes', 'first-support', 'second-support'],
                array_column($parts, 'contents'),
            );

            return true;
        });
    }

    public function test_the_prompt_and_settings_ride_along_with_many_sources(): void
    {
        $this->fakeRefusal();

        $this->client()->editWithSources($this->spec(['size' => '1152x2048']), [
            ['bytes' => 'source-bytes', 'filename' => 'source.png'],
        ], 30);

        Http::assertSent(function (Request $request): bool {
            $fields = collect($request->data())
                ->filter(static fn (array $part): bool => ! isset($part['filename']))
                ->mapWithKeys(static fn (array $part): array => [$part['name'] => $part['contents']]);

            $this->assertSame('Change only the hull.', $fields['prompt']);
            $this->assertSame('gpt-image-2', $fields['model']);
            $this->assertSame('1152x2048', $fields['size']);
            $this->assertSame('low', $fields['quality']);
            $this->assertSame('1', $fields['n']);

            return true;
        });
    }

    public function test_an_empty_source_list_never_reaches_the_provider(): void
    {
        Http::fake();

        $result = $this->client()->editWithSources($this->spec(), [], 30);

        $this->assertFalse($result['ok']);
        Http::assertNothingSent();
    }

    public function test_a_source_beyond_the_contract_cap_never_reaches_the_provider(): void
    {
        Http::fake();

        $images = array_fill(0, OpenAiImageClient::MAX_SOURCE_IMAGES + 1, [
            'bytes' => 'x', 'filename' => 'x.png',
        ]);

        $result = $this->client()->editWithSources($this->spec(), $images, 30);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('vuot tran', (string) $result['error']);
        Http::assertNothingSent();
    }

    public function test_an_empty_image_anywhere_in_the_list_never_reaches_the_provider(): void
    {
        Http::fake();

        $result = $this->client()->editWithSources($this->spec(), [
            ['bytes' => 'source-bytes', 'filename' => 'source.png'],
            ['bytes' => '', 'filename' => 'blank.png'],
        ], 30);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('vi tri 1', (string) $result['error']);
        Http::assertNothingSent();
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function malformedSourceProvider(): iterable
    {
        yield 'khong phai array' => ['not-an-array'];
        yield 'bytes khong phai chuoi' => [['bytes' => 123, 'filename' => 'a.png']];
        yield 'bytes thieu' => [['filename' => 'a.png']];
        yield 'filename thieu' => [['bytes' => 'x']];
        yield 'filename khong phai chuoi' => [['bytes' => 'x', 'filename' => ['a.png']]];
        yield 'filename rong' => [['bytes' => 'x', 'filename' => '   ']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedSourceProvider')]
    public function test_a_malformed_source_entry_never_reaches_the_provider(mixed $entry): void
    {
        Http::fake();

        $result = $this->client()->editWithSources($this->spec(), [
            ['bytes' => 'source-bytes', 'filename' => 'source.png'],
            $entry,
        ], 30);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('vi tri 1', (string) $result['error']);
        Http::assertNothingSent();
    }

    public function test_a_successful_response_writes_every_image_it_returns(): void
    {
        Storage::fake('local');

        $png = self::PNG_3X5;
        $bytes = base64_decode($png, true);

        Http::fake([
            '*' => Http::response([
                'created' => 1,
                'data' => [['b64_json' => $png], ['b64_json' => $png]],
            ], 200),
        ]);

        $result = $this->client()->editWithSources($this->spec([
            'variations' => 2,
            'operation' => 'edit',
            'project_id' => 'proj-1',
            'image_id' => 'img-1',
            'claim_token' => 'claim-1',
        ]), [
            ['bytes' => 'source-bytes', 'filename' => 'source.png'],
        ], 30);

        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertCount(2, $result['renders']);

        foreach ($result['renders'] as $render) {
            Storage::disk('local')->assertExists($render['storage_path']);
            $this->assertSame($bytes, Storage::disk('local')->get($render['storage_path']));
            $this->assertSame(hash('sha256', $bytes), $render['artifact_sha256']);
            $this->assertSame('image/png', $render['mime_type']);
            $this->assertSame(strlen($bytes), $render['bytes']);
            $this->assertSame(3, $render['width']);
            $this->assertSame(5, $render['height']);
            $this->assertSame('edit', $render['render']['render_kind']);
            $this->assertSame('Change only the hull.', $render['render']['sent_prompt']);
        }

        $this->assertSame(
            ['proj-1/img-1/renders/claim-1/output_000.png', 'proj-1/img-1/renders/claim-1/output_001.png'],
            array_column($result['renders'], 'storage_path'),
        );

        $this->assertSame(
            ['claim-1:0', 'claim-1:1'],
            array_column($result['renders'], 'idempotency_key'),
        );
    }

    public function test_an_unpriced_render_reaches_the_ledger_as_unpriced_not_as_free(): void
    {
        Storage::fake('local');

        Http::fake([
            '*' => Http::response(['created' => 1, 'data' => [['b64_json' => self::PNG_3X5]]], 200),
        ]);

        $result = $this->client()->editWithSources($this->spec([
            'operation' => 'scene_keyframe',
            'pricing' => 'unpriced',
            'cost_estimate' => null,
            'project_id' => 'proj-1',
            'image_id' => 'img-1',
            'claim_token' => 'claim-1',
        ]), [['bytes' => 'source-bytes', 'filename' => 'source.png']], 30);

        $this->assertTrue($result['ok'], (string) $result['error']);
        $this->assertNull($result['renders'][0]['cost']);
        $this->assertSame('unpriced', $result['renders'][0]['pricing']);
        $this->assertSame('image', $result['renders'][0]['render']['source_kind']);
    }

    public function test_a_priced_render_still_carries_its_estimate(): void
    {
        Storage::fake('local');

        Http::fake([
            '*' => Http::response(['created' => 1, 'data' => [['b64_json' => self::PNG_3X5]]], 200),
        ]);

        $result = $this->client()->edit($this->spec([
            'operation' => 'edit',
            'cost_estimate' => 0.015,
            'project_id' => 'proj-1',
            'image_id' => 'img-1',
            'claim_token' => 'claim-1',
        ]), 'anchor-bytes', 'anchor.png', 30);

        $this->assertSame(0.015, $result['renders'][0]['cost']);
        $this->assertSame('estimated', $result['renders'][0]['pricing']);
    }

    public function test_an_empty_prompt_never_reaches_the_provider_on_either_path(): void
    {
        Http::fake();

        $blank = $this->spec(['prompt' => '   ']);

        $this->assertFalse($this->client()->edit($blank, 'bytes', 'a.png', 30)['ok']);
        $this->assertFalse($this->client()->editWithSources($blank, [
            ['bytes' => 'bytes', 'filename' => 'a.png'],
        ], 30)['ok']);

        Http::assertNothingSent();
    }

    public function test_an_empty_single_image_never_reaches_the_provider(): void
    {
        Http::fake();

        $result = $this->client()->edit($this->spec(), '', 'a.png', 30);

        $this->assertFalse($result['ok']);
        Http::assertNothingSent();
    }
}
