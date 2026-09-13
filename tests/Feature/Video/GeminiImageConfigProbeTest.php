<?php

namespace Tests\Feature\Video;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiImageConfigProbeTest extends TestCase
{
    private const KEY = 'test-gemini-key-never-real';

    private const SENTINEL = '__probe_unknown_field__';

    private const REAL_FIELD_REJECTED = "Invalid JSON payload received. Unknown name \"aspectRatio\" at 'generation_config.response_format': Cannot find field.\n"
        ."Invalid JSON payload received. Unknown name \"imageSize\" at 'generation_config.response_format': Cannot find field.\n"
        ."Invalid JSON payload received. Unknown name \"__probe_unknown_field__\" at 'generation_config': Cannot find field.";

    private const REAL_VALUE_REJECTED = "Invalid value at 'generation_config.response_format.image.aspect_ratio' (type.googleapis.com/google.ai.generativelanguage.v1.ImageResponseFormat.AspectRatio), \"9:16\"\n"
        ."Invalid value at 'generation_config.response_format.image.image_size' (type.googleapis.com/google.ai.generativelanguage.v1.ImageResponseFormat.ImageSize), \"1K\"\n"
        ."Invalid JSON payload received. Unknown name \"__probe_unknown_field__\" at 'generation_config': Cannot find field.";

    private const REAL_ACCEPTED = "Invalid JSON payload received. Unknown name \"__probe_unknown_field__\" at 'generation_config': Cannot find field.";

    private const NO_UNKNOWN_NAME = 'Request contains an invalid argument.';

    /** @var list<string> */
    private const VARIANTS = ['responseFormat_flat', 'responseFormat_image', 'imageConfig'];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'video.gemini.api_key' => self::KEY,
            'video.gemini.base_url' => 'https://generativelanguage.googleapis.com',
            'video.gemini.timeout' => 5,
        ]);
    }

    public function test_a_missing_key_never_sends_a_request(): void
    {
        Http::fake();
        config(['video.gemini.api_key' => '  ']);

        $this->artisan('video:probe-gemini-image-config')
            ->expectsOutputToContain('khong goi gi ca')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_walks_both_versions_against_every_variant(): void
    {
        $this->fakeAccepting();

        $this->artisan('video:probe-gemini-image-config')->assertExitCode(0);

        Http::assertSentCount(6);

        $this->assertSame(
            [
                'v1:responseFormat_flat',
                'v1:responseFormat_image',
                'v1:imageConfig',
                'v1beta:responseFormat_flat',
                'v1beta:responseFormat_image',
                'v1beta:imageConfig',
            ],
            $this->sentPairs(),
        );
    }

    public function test_the_sentinel_is_present_in_every_body(): void
    {
        $this->fakeAccepting();

        $this->artisan('video:probe-gemini-image-config')->assertExitCode(0);

        Http::assertSent(function ($request): bool {
            $this->assertArrayHasKey(
                self::SENTINEL,
                $request->data()['generationConfig'],
                'without the sentinel a valid body could generate a paid image',
            );

            return true;
        });
    }

    public function test_a_rejected_subfield_is_not_mistaken_for_acceptance(): void
    {
        $this->fakeWith(self::REAL_FIELD_REJECTED);

        $this->assertSame(1, Artisan::call('video:probe-gemini-image-config'));

        $output = Artisan::output();

        $this->assertStringContainsString('field_rejected', $output);
        $this->assertStringNotContainsString('field_accepted', $output);
    }

    public function test_an_error_naming_only_the_sentinel_means_the_field_was_accepted(): void
    {
        $this->fakeAccepting();

        $this->artisan('video:probe-gemini-image-config')
            ->expectsOutputToContain('field_accepted')
            ->assertExitCode(0);
    }

    public function test_real_answers_reject_the_flat_shape_and_the_enum_values_and_accept_image_config(): void
    {
        $answers = fn () => Http::sequence()
            ->push($this->error(self::REAL_FIELD_REJECTED), 400)
            ->push($this->error(self::REAL_VALUE_REJECTED), 400)
            ->push($this->error(self::REAL_ACCEPTED), 400);

        Http::fake([
            '*/v1/models/*' => $answers(),
            '*/v1beta/models/*' => $answers(),
        ]);

        $path = storage_path('framework/testing/probe_real_'.uniqid().'.json');

        $this->artisan('video:probe-gemini-image-config', ['--json' => $path])->assertExitCode(0);

        $results = json_decode((string) file_get_contents($path), true)['results'];

        foreach (['v1', 'v1beta'] as $version) {
            $this->assertSame('field_rejected', $results[$version]['responseFormat_flat']['verdict']);
            $this->assertSame('value_rejected', $results[$version]['responseFormat_image']['verdict']);
            $this->assertSame('field_accepted', $results[$version]['imageConfig']['verdict']);
        }

        unlink($path);
    }

    public function test_an_invalid_value_is_not_mistaken_for_acceptance(): void
    {
        $this->fakeWith(self::REAL_VALUE_REJECTED);

        $this->assertSame(1, Artisan::call('video:probe-gemini-image-config'));

        $output = Artisan::output();

        $this->assertStringContainsString('value_rejected', $output);
        $this->assertStringNotContainsString('field_accepted', $output);
    }

    public function test_an_invalid_value_without_any_unknown_name_is_a_value_rejection(): void
    {
        $this->fakeWith(
            "Invalid value at 'generation_config.response_format.image.aspect_ratio' "
            ."(type.googleapis.com/google.ai.generativelanguage.v1.ImageResponseFormat.AspectRatio), \"9:16\""
        );

        $this->assertSame(1, Artisan::call('video:probe-gemini-image-config'));
        $this->assertStringContainsString('value_rejected', Artisan::output());
    }

    public function test_a_wrong_field_outranks_a_wrong_value(): void
    {
        $this->fakeWith(
            "Invalid value at 'generation_config.response_format.image.aspect_ratio' "
            ."(type.googleapis.com/google.ai.generativelanguage.v1.ImageResponseFormat.AspectRatio), \"9:16\"\n"
            ."Invalid JSON payload received. Unknown name \"imageSizee\" at 'generation_config.response_format.image': Cannot find field.\n"
            ."Invalid JSON payload received. Unknown name \"__probe_unknown_field__\" at 'generation_config': Cannot find field."
        );

        $this->assertSame(1, Artisan::call('video:probe-gemini-image-config'));

        $output = Artisan::output();

        $this->assertStringContainsString('field_rejected', $output);
        $this->assertStringNotContainsString('value_rejected', $output);
    }

    public function test_the_evidence_records_each_invalid_value_its_type_and_where(): void
    {
        $answers = fn () => Http::sequence()
            ->push($this->error(self::REAL_FIELD_REJECTED), 400)
            ->push($this->error(self::REAL_VALUE_REJECTED), 400)
            ->push($this->error(self::REAL_ACCEPTED), 400);

        Http::fake([
            '*/v1/models/*' => $answers(),
            '*/v1beta/models/*' => $answers(),
        ]);

        $path = storage_path('framework/testing/probe_invalid_'.uniqid().'.json');

        $this->artisan('video:probe-gemini-image-config', ['--json' => $path])->assertExitCode(0);

        $results = json_decode((string) file_get_contents($path), true)['results'];

        $this->assertSame([], $results['v1']['responseFormat_flat']['invalid']);
        $this->assertSame([], $results['v1']['imageConfig']['invalid']);
        $this->assertSame([
            [
                'at' => 'generation_config.response_format.image.aspect_ratio',
                'type' => 'type.googleapis.com/google.ai.generativelanguage.v1.ImageResponseFormat.AspectRatio',
                'value' => '9:16',
            ],
            [
                'at' => 'generation_config.response_format.image.image_size',
                'type' => 'type.googleapis.com/google.ai.generativelanguage.v1.ImageResponseFormat.ImageSize',
                'value' => '1K',
            ],
        ], $results['v1']['responseFormat_image']['invalid']);

        unlink($path);
    }

    public function test_six_inconclusive_answers_fail_the_command(): void
    {
        $this->fakeWith(self::NO_UNKNOWN_NAME);

        $this->artisan('video:probe-gemini-image-config')
            ->expectsOutputToContain('Khong cap nao duoc chap nhan')
            ->assertExitCode(1);

        Http::assertSentCount(6);
    }

    public function test_six_rejected_answers_fail_the_command(): void
    {
        $this->fakeWith(self::REAL_FIELD_REJECTED);

        $this->artisan('video:probe-gemini-image-config')->assertExitCode(1);

        Http::assertSentCount(6);
    }

    public function test_a_failed_probe_still_writes_its_evidence(): void
    {
        $this->fakeWith(self::NO_UNKNOWN_NAME);

        $path = storage_path('framework/testing/probe_failed_'.uniqid().'.json');

        $this->artisan('video:probe-gemini-image-config', ['--json' => $path])->assertExitCode(1);

        $this->assertFileExists($path);

        $data = json_decode((string) file_get_contents($path), true);

        foreach (['v1', 'v1beta'] as $version) {
            foreach (self::VARIANTS as $variant) {
                $this->assertSame('inconclusive', $data['results'][$version][$variant]['verdict']);
                $this->assertSame(self::NO_UNKNOWN_NAME, $data['results'][$version][$variant]['message']);
            }
        }

        unlink($path);
    }

    public function test_a_success_stops_the_matrix_after_one_request(): void
    {
        Http::fake(['*' => Http::response(['candidates' => []], 200)]);

        $this->artisan('video:probe-gemini-image-config')
            ->expectsOutputToContain('CHO DOI 400, NHAN 200')
            ->expectsOutputToContain('MOT ANH CO THE DA DUOC SINH')
            ->assertExitCode(1);

        Http::assertSentCount(1);
    }

    public function test_any_other_status_also_stops_the_matrix(): void
    {
        Http::fake(['*' => Http::response($this->error('API key not valid'), 403)]);

        $this->artisan('video:probe-gemini-image-config')->assertExitCode(1);

        Http::assertSentCount(1);
    }

    public function test_a_connection_failure_stops_without_throwing(): void
    {
        Http::fake(fn () => throw new ConnectionException('dns went away'));

        $this->artisan('video:probe-gemini-image-config')
            ->expectsOutputToContain('Khong noi duoc Gemini')
            ->assertExitCode(1);
    }

    public function test_the_key_travels_in_the_header_and_never_in_the_url(): void
    {
        $this->fakeAccepting();

        $this->artisan('video:probe-gemini-image-config')->assertExitCode(0);

        Http::assertSent(function ($request): bool {
            $this->assertSame(self::KEY, $request->header('x-goog-api-key')[0] ?? null);
            $this->assertStringNotContainsString(self::KEY, $request->url());

            return true;
        });
    }

    public function test_it_probes_with_the_cheapest_model(): void
    {
        $this->fakeAccepting();

        $this->artisan('video:probe-gemini-image-config')->assertExitCode(0);

        Http::assertSent(fn ($request) => str_contains(
            $request->url(), 'gemini-3.1-flash-lite-image:generateContent',
        ));
    }

    public function test_it_never_prints_the_api_key(): void
    {
        $this->fakeAccepting();

        $this->assertSame(0, Artisan::call('video:probe-gemini-image-config'));

        $this->assertStringNotContainsString(self::KEY, Artisan::output());
    }

    public function test_the_evidence_records_each_unknown_name_and_where(): void
    {
        $this->fakeWith(self::REAL_FIELD_REJECTED);

        $path = storage_path('framework/testing/probe_'.uniqid().'.json');

        $this->artisan('video:probe-gemini-image-config', ['--json' => $path])->assertExitCode(1);

        $written = (string) file_get_contents($path);
        $data = json_decode($written, true);

        $this->assertStringNotContainsString(self::KEY, $written);
        $this->assertSame('gemini-3.1-flash-lite-image', $data['probe_model']);
        $this->assertSame(self::VARIANTS, array_keys($data['results']['v1']));
        $this->assertSame(
            [
                ['name' => 'aspectRatio', 'at' => 'generation_config.response_format'],
                ['name' => 'imageSize', 'at' => 'generation_config.response_format'],
                ['name' => self::SENTINEL, 'at' => 'generation_config'],
            ],
            $data['results']['v1']['responseFormat_flat']['unknown'],
        );

        unlink($path);
    }

    /** @return list<string> */
    private function sentPairs(): array
    {
        return collect(Http::recorded())
            ->map(function (array $pair): string {
                $version = str_contains($pair[0]->url(), '/v1beta/') ? 'v1beta' : 'v1';
                $config = $pair[0]->data()['generationConfig'];

                $variant = match (true) {
                    array_key_exists('imageConfig', $config) => 'imageConfig',
                    array_key_exists('image', $config['responseFormat'] ?? []) => 'responseFormat_image',
                    default => 'responseFormat_flat',
                };

                return $version.':'.$variant;
            })
            ->all();
    }

    private function fakeAccepting(): void
    {
        $this->fakeWith(self::REAL_ACCEPTED);
    }

    private function fakeWith(string $message): void
    {
        Http::fake(['*' => Http::response($this->error($message), 400)]);
    }

    /** @return array<string, mixed> */
    private function error(string $message): array
    {
        return ['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => $message]];
    }
}
