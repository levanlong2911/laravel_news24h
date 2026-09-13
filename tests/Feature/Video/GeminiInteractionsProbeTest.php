<?php

namespace Tests\Feature\Video;

use App\Video\Gemini\GeminiErrorVerdict;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiInteractionsProbeTest extends TestCase
{
    private const KEY = 'test-gemini-key-never-real';

    private const SENTINEL_AT_TOP = "Invalid JSON payload received. Unknown name \"__probe_unknown_field__\" at '': Cannot find field.";

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/gemini_evidence_'.uniqid());
        mkdir($this->dir, 0775, true);

        config([
            'video.gemini.api_key' => self::KEY,
            'video.gemini.base_url' => 'https://generativelanguage.googleapis.com',
            'video.gemini.timeout' => 5,
            'video.gemini.evidence_dir' => $this->dir,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->dir);

        parent::tearDown();
    }

    public function test_a_missing_key_never_sends_a_request(): void
    {
        Http::fake();
        config(['video.gemini.api_key' => '']);

        $this->artisan('video:probe-gemini-interactions')
            ->expectsOutputToContain('khong goi gi ca')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_the_default_run_sends_one_request_with_the_cheapest_model(): void
    {
        $this->fakeAnswer(self::SENTINEL_AT_TOP);

        $this->artisan('video:probe-gemini-interactions')->assertExitCode(0);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/interactions'
            && $request->data()['model'] === 'gemini-3.1-flash-lite-image');
    }

    public function test_the_body_follows_the_documented_shape_with_the_sentinel_on_top(): void
    {
        $this->fakeAnswer(self::SENTINEL_AT_TOP);

        $this->artisan('video:probe-gemini-interactions')->assertExitCode(0);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            $this->assertSame('x', $body['input']);
            $this->assertSame(
                ['type' => 'image', 'aspect_ratio' => '9:16', 'image_size' => '1K'],
                $body['response_format'],
            );
            $this->assertArrayHasKey(GeminiErrorVerdict::SENTINEL, $body);

            return true;
        });
    }

    public function test_the_key_travels_in_the_header_and_never_in_the_url(): void
    {
        $this->fakeAnswer(self::SENTINEL_AT_TOP);

        $this->artisan('video:probe-gemini-interactions')->assertExitCode(0);

        Http::assertSent(function ($request): bool {
            $this->assertSame(self::KEY, $request->header('x-goog-api-key')[0] ?? null);
            $this->assertStringNotContainsString(self::KEY, $request->url());

            return true;
        });
    }

    public function test_a_success_stops_after_one_request_and_warns_about_money(): void
    {
        Http::fake(['*' => Http::response(['outputs' => []], 200)]);

        $this->artisan('video:probe-gemini-interactions')
            ->expectsOutputToContain('CHO DOI 400, NHAN 200')
            ->expectsOutputToContain('MOT ANH CO THE DA DUOC SINH')
            ->assertExitCode(1);

        Http::assertSentCount(1);
    }

    public function test_the_first_run_writes_its_own_evidence_file(): void
    {
        $this->fakeAnswer(self::SENTINEL_AT_TOP);

        $this->artisan('video:probe-gemini-interactions')->assertExitCode(0);

        $files = glob($this->dir.'/gemini_interactions_probe_first_*.json');

        $this->assertCount(1, $files);
        $this->assertSame([], glob($this->dir.'/gemini_interactions_probe_all_*.json'));

        $written = (string) file_get_contents($files[0]);
        $data = json_decode($written, true);

        $this->assertSame('interactions', $data['endpoint']);
        $this->assertSame('v1beta', $data['api_version']);
        $this->assertSame('first', $data['run']);
        $this->assertSame(['gemini-3.1-flash-lite-image'], array_keys($data['results']));
        $this->assertStringNotContainsString(self::KEY, $written);
    }

    public function test_an_unreadable_error_is_inconclusive_fails_and_keeps_the_message(): void
    {
        $this->fakeAnswer('response_format.aspect_ratio must be one of the documented ratios');

        $this->artisan('video:probe-gemini-interactions')
            ->expectsOutputToContain('Khong doc duoc thong diep')
            ->assertExitCode(1);

        $data = json_decode((string) file_get_contents(glob($this->dir.'/gemini_interactions_probe_first_*.json')[0]), true);

        $this->assertSame('inconclusive', $data['results']['gemini-3.1-flash-lite-image']['verdict']);
        $this->assertSame(
            'response_format.aspect_ratio must be one of the documented ratios',
            $data['results']['gemini-3.1-flash-lite-image']['message'],
        );
    }

    public function test_all_without_a_first_run_sends_nothing(): void
    {
        Http::fake();

        $this->artisan('video:probe-gemini-interactions', ['--all' => true])
            ->expectsOutputToContain('Chua co bang chung luot dau')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_all_refuses_the_evidence_of_the_generate_content_probe(): void
    {
        Http::fake();

        $this->writeEvidence('gemini_interactions_probe_first_2026_09_13.json', [
            'endpoint' => 'generateContent',
        ]);

        $this->artisan('video:probe-gemini-interactions', ['--all' => true])->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_all_refuses_a_first_file_for_another_api_version(): void
    {
        Http::fake();

        $this->writeEvidence('gemini_interactions_probe_first_2026_09_13.json', ['api_version' => 'v1']);

        $this->artisan('video:probe-gemini-interactions', ['--all' => true])->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_all_refuses_when_only_an_all_run_exists(): void
    {
        Http::fake();

        $this->writeEvidence('gemini_interactions_probe_all_2026_09_13.json', ['run' => 'all']);

        $this->artisan('video:probe-gemini-interactions', ['--all' => true])->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_all_refuses_a_first_run_that_was_inconclusive(): void
    {
        Http::fake();

        $this->writeEvidence('gemini_interactions_probe_first_2026_09_13.json', [], 'inconclusive');

        $this->artisan('video:probe-gemini-interactions', ['--all' => true])->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_all_after_a_safe_first_run_walks_the_three_models_in_order(): void
    {
        $this->writeEvidence('gemini_interactions_probe_first_2026_09_13.json');
        $this->fakeAnswer(self::SENTINEL_AT_TOP);

        $this->artisan('video:probe-gemini-interactions', ['--all' => true])->assertExitCode(0);

        Http::assertSentCount(3);

        $this->assertSame(
            ['gemini-3.1-flash-lite-image', 'gemini-3.1-flash-image', 'gemini-3-pro-image'],
            collect(Http::recorded())->map(fn (array $pair) => $pair[0]->data()['model'])->all(),
        );

        $this->assertCount(1, glob($this->dir.'/gemini_interactions_probe_all_*.json'));
    }

    public function test_all_uses_the_newest_first_run_and_says_which(): void
    {
        $this->writeEvidence('gemini_interactions_probe_first_2026_09_10.json', [], 'inconclusive');
        $this->writeEvidence('gemini_interactions_probe_first_2026_09_13.json');
        $this->fakeAnswer(self::SENTINEL_AT_TOP);

        $this->assertSame(0, Artisan::call('video:probe-gemini-interactions', ['--all' => true]));
        $this->assertStringContainsString('gemini_interactions_probe_first_2026_09_13.json', Artisan::output());
    }

    public function test_all_stops_at_the_first_answer_that_is_not_400(): void
    {
        $this->writeEvidence('gemini_interactions_probe_first_2026_09_13.json');

        Http::fake(['*' => Http::sequence()
            ->push($this->error(self::SENTINEL_AT_TOP), 400)
            ->push(['outputs' => []], 200)
            ->push($this->error(self::SENTINEL_AT_TOP), 400)]);

        $this->artisan('video:probe-gemini-interactions', ['--all' => true])->assertExitCode(1);

        Http::assertSentCount(2);
    }

    /** @param array<string, mixed> $override */
    private function writeEvidence(string $name, array $override = [], string $verdict = 'field_accepted'): void
    {
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.$name, json_encode($override + [
            'endpoint' => 'interactions',
            'api_version' => 'v1beta',
            'run' => 'first',
            'sentinel' => GeminiErrorVerdict::SENTINEL,
            'results' => [
                'gemini-3.1-flash-lite-image' => ['status' => 400, 'verdict' => $verdict],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    private function fakeAnswer(string $message): void
    {
        Http::fake(['*' => Http::response($this->error($message), 400)]);
    }

    /** @return array<string, mixed> */
    private function error(string $message): array
    {
        return ['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => $message]];
    }
}
