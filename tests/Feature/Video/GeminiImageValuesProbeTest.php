<?php

namespace Tests\Feature\Video;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiImageValuesProbeTest extends TestCase
{
    private const KEY = 'test-gemini-key-never-real';

    private const SENTINEL = '__probe_unknown_field__';

    private const LITE = 'gemini-3.1-flash-lite-image';

    private const ACCEPTED = "Invalid JSON payload received. Unknown name \"__probe_unknown_field__\" at 'generation_config': Cannot find field.";

    private const NOTHING_READABLE = 'Request contains an invalid argument.';

    /** Mot model: 1 cap neo + 13 ti le con lai + 3 kho con lai. */
    private const SWEEP = 17;

    private ?string $scratch = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'video.gemini.api_key' => self::KEY,
            'video.gemini.base_url' => 'https://generativelanguage.googleapis.com',
            'video.gemini.timeout' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->scratch !== null && is_file($this->scratch)) {
            unlink($this->scratch);
        }

        parent::tearDown();
    }

    public function test_a_missing_key_never_sends_a_request(): void
    {
        Http::fake();
        config(['video.gemini.api_key' => '  ']);

        $this->assertSame(1, $this->probe(['--model' => []]));
        $this->assertStringContainsString('khong goi gi ca', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_a_model_outside_the_list_never_becomes_evidence(): void
    {
        Http::fake();

        $this->assertSame(1, $this->probe(['--model' => ['gemini-3.1-flash-lite-imag']]));

        $this->assertStringContainsString('Model ngoai danh sach', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_the_same_model_asked_twice_is_refused_before_any_request(): void
    {
        Http::fake();

        $this->assertSame(1, $this->probe(['--model' => [self::LITE, self::LITE]]));

        $this->assertStringContainsString('Model lap lai', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_an_api_version_outside_the_list_never_becomes_evidence(): void
    {
        Http::fake();

        $this->assertSame(1, $this->probe(['--api-version' => 'v2']));

        $this->assertStringContainsString('api-version chi nhan', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_the_other_known_version_is_allowed(): void
    {
        $this->fakeRejecting([]);

        $this->assertSame(0, $this->probe(['--api-version' => 'v1beta', '--json' => $this->scratchPath()]));

        $this->assertSame('v1beta', $this->evidence()['api_version']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v1beta/models/'));
    }

    public function test_one_model_costs_one_anchor_plus_every_remaining_value(): void
    {
        $this->fakeRejecting([]);

        $this->assertSame(0, $this->probe());

        Http::assertSentCount(self::SWEEP);

        $this->assertSame(
            ['9:16', '1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '16:9', '21:9', '1:4', '4:1', '1:8', '8:1'],
            $this->sentRatios(),
            'the anchor is asked first, then every ratio that is not the anchor',
        );

        $this->assertSame(['0.5K', '2K', '4K'], $this->sentSizes());
    }

    public function test_every_body_carries_the_sentinel_and_the_image_config_shape(): void
    {
        $this->fakeRejecting([]);

        $this->probe();

        Http::assertSent(function (Request $request): bool {
            $config = $request->data()['generationConfig'];

            $this->assertArrayHasKey(
                self::SENTINEL, $config, 'without the sentinel a valid body could generate a paid image',
            );
            $this->assertSame(['aspectRatio', 'imageSize'], array_keys($config['imageConfig']));
            $this->assertSame(['IMAGE'], $config['responseModalities']);

            return true;
        });
    }

    public function test_the_key_travels_in_the_header_and_never_in_the_url(): void
    {
        $this->fakeRejecting([]);

        $this->probe();

        Http::assertSent(function (Request $request): bool {
            $this->assertSame(self::KEY, $request->header('x-goog-api-key')[0] ?? null);
            $this->assertStringNotContainsString(self::KEY, $request->url());

            return true;
        });
    }

    public function test_a_value_the_parser_never_complained_about_is_parse_accepted(): void
    {
        $this->fakeRejecting([]);
        $this->probe(['--json' => $this->scratchPath()]);

        $evidence = $this->evidence();

        $this->assertSame('parse_accepted', $evidence['models'][self::LITE]['aspect_ratios']['21:9']['verdict']);
        $this->assertSame('parse_accepted', $evidence['models'][self::LITE]['image_sizes']['4K']['verdict']);
    }

    public function test_a_value_named_in_an_invalid_value_line_is_value_rejected(): void
    {
        $this->fakeRejecting(['21:9', '4K']);
        $this->probe(['--json' => $this->scratchPath()]);

        $ratios = $this->evidence()['models'][self::LITE]['aspect_ratios'];
        $sizes = $this->evidence()['models'][self::LITE]['image_sizes'];

        $this->assertSame('value_rejected', $ratios['21:9']['verdict']);
        $this->assertSame('parse_accepted', $ratios['16:9']['verdict']);
        $this->assertSame('value_rejected', $sizes['4K']['verdict']);
        $this->assertSame('parse_accepted', $sizes['2K']['verdict']);
        $this->assertSame('21:9', $ratios['21:9']['invalid'][0]['value']);
    }

    public function test_an_error_that_says_neither_is_inconclusive(): void
    {
        Http::fake(function (Request $request) {
            $ratio = $request->data()['generationConfig']['imageConfig']['aspectRatio'];

            return Http::response(
                $this->error($ratio === '21:9' ? self::NOTHING_READABLE : self::ACCEPTED), 400,
            );
        });

        $this->probe(['--json' => $this->scratchPath()]);

        $this->assertSame(
            'inconclusive',
            $this->evidence()['models'][self::LITE]['aspect_ratios']['21:9']['verdict'],
        );
    }

    public function test_a_rejected_anchor_costs_one_request_and_is_written_down_as_skipped(): void
    {
        $this->fakeRejecting(['9:16']);

        $this->assertSame(1, $this->probe(['--json' => $this->scratchPath()]));

        Http::assertSentCount(1);
        $this->assertStringContainsString('cap neo', Artisan::output());

        $entry = $this->evidence()['models'][self::LITE];

        $this->assertSame('skipped_anchor', $entry['status']);
        $this->assertSame('value_rejected', $entry['anchor']['verdict']);
        $this->assertArrayNotHasKey('aspect_ratios', $entry, 'a skipped model has no sweep to show');
    }

    public function test_a_model_that_was_swept_says_so_next_to_its_anchor(): void
    {
        $this->fakeRejecting([]);
        $this->probe(['--json' => $this->scratchPath()]);

        $entry = $this->evidence()['models'][self::LITE];

        $this->assertSame('swept', $entry['status']);
        $this->assertSame('parse_accepted', $entry['anchor']['verdict']);
    }

    public function test_one_skipped_model_fails_the_whole_run_even_when_another_was_swept(): void
    {
        Http::fake(function (Request $request) {
            $rejected = str_contains($request->url(), 'gemini-3-pro-image') ? ['9:16'] : [];

            return $this->answer($request, $rejected);
        });

        $this->assertSame(1, $this->probe(['--model' => [], '--json' => $this->scratchPath()]));

        $models = $this->evidence()['models'];

        $this->assertSame('swept', $models[self::LITE]['status']);
        $this->assertSame('skipped_anchor', $models['gemini-3-pro-image']['status']);
        $this->assertStringContainsString('Ma tran khong day du', Artisan::output());
    }

    public function test_the_evidence_says_out_loud_what_it_does_not_prove(): void
    {
        $this->fakeRejecting([]);
        $this->probe(['--json' => $this->scratchPath()]);

        $evidence = $this->evidence();

        $this->assertSame('parse only', $evidence['proves']);
        $this->assertStringContainsString('khong duoc dung lam cong tac', $evidence['does_not_prove']);
        $this->assertStringContainsString('khong chung minh model render duoc', Artisan::output());
    }

    public function test_a_success_stops_the_whole_sweep_and_says_money_may_have_moved(): void
    {
        Http::fake(['*' => Http::response(['candidates' => []], 200)]);

        $this->assertSame(1, $this->probe());

        Http::assertSentCount(1);

        $output = Artisan::output();

        $this->assertStringContainsString('CHO DOI 400, NHAN 200', $output);
        $this->assertStringContainsString('DA DUOC SINH VA TINH TIEN', $output);
        $this->assertStringContainsString('DUNG TOAN BO MA TRAN', $output);
    }

    public function test_any_other_status_stops_the_sweep_too(): void
    {
        Http::fake(['*' => Http::response($this->error('forbidden'), 403)]);

        $this->assertSame(1, $this->probe());

        Http::assertSentCount(1);
        $this->assertStringContainsString('CHO DOI 400, NHAN 403', Artisan::output());
    }

    public function test_a_rate_limit_is_waited_out_and_asked_once_more(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->error('rate limited'), 429)
            ->whenEmpty(Http::response($this->error(self::ACCEPTED), 400))]);

        $this->assertSame(0, $this->probe());

        Http::assertSentCount(self::SWEEP + 1);
        $this->assertStringContainsString('429 tren', Artisan::output());
    }

    public function test_two_rate_limits_in_a_row_stop_the_sweep(): void
    {
        Http::fake(['*' => Http::response($this->error('rate limited'), 429)]);

        $this->assertSame(1, $this->probe());

        Http::assertSentCount(2);
        $this->assertStringContainsString('CHO DOI 400, NHAN 429', Artisan::output());
    }

    public function test_the_evidence_names_the_version_the_anchor_and_the_model(): void
    {
        $this->fakeRejecting([]);
        $this->probe(['--json' => $this->scratchPath()]);

        $evidence = $this->evidence();

        $this->assertSame('v1', $evidence['api_version']);
        $this->assertSame(self::SENTINEL, $evidence['sentinel']);
        $this->assertSame(['aspect_ratio' => '9:16', 'image_size' => '1K'], $evidence['anchor']);
        $this->assertSame([self::LITE], array_keys($evidence['models']));
        $this->assertStringNotContainsString(self::KEY, (string) file_get_contents($this->scratch));
    }

    public function test_the_sweep_walks_every_model_when_none_is_named(): void
    {
        $this->fakeRejecting([]);

        $this->assertSame(0, $this->probe(['--model' => []]));

        Http::assertSentCount(self::SWEEP * 3);
    }

    /** @param array<string, mixed> $options */
    private function probe(array $options = []): int
    {
        return Artisan::call('video:probe-gemini-image-values', $options + [
            '--model' => [self::LITE],
            '--pause' => 0,
            '--retry-pause' => 0,
        ]);
    }

    /** @param list<string> $rejected */
    private function fakeRejecting(array $rejected): void
    {
        Http::fake(fn (Request $request) => $this->answer($request, $rejected));
    }

    /** @param list<string> $rejected */
    private function answer(Request $request, array $rejected)
    {
        $config = $request->data()['generationConfig']['imageConfig'];
        $lines = [];

        if (in_array($config['aspectRatio'], $rejected, true)) {
            $lines[] = "Invalid value at 'generation_config.image_config.aspect_ratio' "
                .'(type.googleapis.com/google.ai.generativelanguage.v1.ImageConfig.AspectRatio), '
                .'"'.$config['aspectRatio'].'"';
        }

        if (in_array($config['imageSize'], $rejected, true)) {
            $lines[] = "Invalid value at 'generation_config.image_config.image_size' "
                .'(type.googleapis.com/google.ai.generativelanguage.v1.ImageConfig.ImageSize), '
                .'"'.$config['imageSize'].'"';
        }

        $lines[] = self::ACCEPTED;

        return Http::response($this->error(implode("\n", $lines)), 400);
    }

    /** @return array<string, mixed> */
    private function error(string $message): array
    {
        return ['error' => ['code' => 400, 'message' => $message, 'status' => 'INVALID_ARGUMENT']];
    }

    /** @return list<string> */
    private function sentRatios(): array
    {
        return $this->sentValues('aspectRatio', 'imageSize', '1K');
    }

    /** @return list<string> */
    private function sentSizes(): array
    {
        return $this->sentValues('imageSize', 'aspectRatio', '9:16', skipFirst: true);
    }

    /** @return list<string> */
    private function sentValues(string $key, string $other, string $held, bool $skipFirst = false): array
    {
        $values = [];

        foreach (Http::recorded() as [$request]) {
            $config = $request->data()['generationConfig']['imageConfig'];

            if ($config[$other] === $held) {
                $values[] = $config[$key];
            }
        }

        return $skipFirst ? array_values(array_slice($values, 1)) : $values;
    }

    private function scratchPath(): string
    {
        return $this->scratch = storage_path('framework/testing/image_values_'.uniqid().'.json');
    }

    /** @return array<string, mixed> */
    private function evidence(): array
    {
        $this->assertFileExists((string) $this->scratch);

        $data = json_decode((string) file_get_contents((string) $this->scratch), true);

        $this->assertIsArray($data);

        return $data;
    }
}
