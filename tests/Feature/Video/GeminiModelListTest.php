<?php

namespace Tests\Feature\Video;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiModelListTest extends TestCase
{
    private const KEY = 'test-gemini-key-never-real';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'video.gemini.api_key' => self::KEY,
            'video.gemini.base_url' => 'https://generativelanguage.googleapis.com',
            'video.gemini.versions' => ['v1beta', 'v1'],
            'video.gemini.timeout' => 5,
        ]);
    }

    public function test_a_missing_key_never_sends_a_request(): void
    {
        Http::fake();
        config(['video.gemini.api_key' => '   ']);

        $this->artisan('video:list-gemini-models')
            ->expectsOutputToContain('khong goi gi ca')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_the_key_travels_in_the_header_and_never_in_the_url(): void
    {
        $this->fakeModels(['v1beta' => ['gemini-2.5-flash'], 'v1' => ['gemini-2.5-flash']]);

        $this->artisan('video:list-gemini-models')->assertExitCode(0);

        Http::assertSent(function ($request) {
            $this->assertSame(self::KEY, $request->header('x-goog-api-key')[0] ?? null);
            $this->assertStringNotContainsString(self::KEY, $request->url());

            return true;
        });
    }

    public function test_it_follows_every_page_of_the_listing(): void
    {
        Http::fake([
            '*/v1beta/models*' => Http::sequence()
                ->push(['models' => [$this->model('a')], 'nextPageToken' => 'page-2'])
                ->push(['models' => [$this->model('b')]]),
            '*/v1/models*' => Http::response(['models' => []]),
        ]);

        $this->artisan('video:list-gemini-models')
            ->expectsOutputToContain('2 model tren 2 phien ban')
            ->assertExitCode(0);

        Http::assertSentCount(3);
    }

    public function test_it_reads_both_versions_and_says_which_one_has_what(): void
    {
        $this->fakeModels(['v1beta' => ['only-in-beta', 'in-both'], 'v1' => ['in-both']]);

        $this->artisan('video:list-gemini-models')
            ->expectsOutputToContain('only-in-beta')
            ->expectsOutputToContain('in-both')
            ->assertExitCode(0);
    }

    public function test_a_long_running_model_is_flagged_by_its_method_not_its_name(): void
    {
        Http::fake([
            '*/v1beta/models*' => Http::response(['models' => [
                ['name' => 'models/quiet-name', 'supportedGenerationMethods' => ['predictLongRunning']],
            ]]),
            '*/v1/models*' => Http::response(['models' => []]),
        ]);

        $this->artisan('video:list-gemini-models')
            ->expectsOutputToContain('predictLongRunning')
            ->assertExitCode(0);
    }

    public function test_an_api_version_outside_the_pattern_never_sends_a_request(): void
    {
        Http::fake();

        $this->artisan('video:list-gemini-models', ['--api-version' => '../../v1beta'])
            ->expectsOutputToContain('khong hop le')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_a_page_token_of_zero_is_still_sent(): void
    {
        Http::fake([
            '*/v1beta/models*' => Http::sequence()
                ->push(['models' => [$this->model('a')], 'nextPageToken' => '0'])
                ->push(['models' => [$this->model('b')]]),
        ]);

        $this->artisan('video:list-gemini-models', ['--api-version' => 'v1beta'])
            ->expectsOutputToContain('2 model tren 1 phien ban')
            ->assertExitCode(0);

        Http::assertSentCount(2);

        $tokens = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0]->data()['pageToken'] ?? null)
            ->all();

        $this->assertSame([null, '0'], $tokens, 'a falsy page token was dropped from the query');
    }

    public function test_a_model_whose_methods_are_not_a_list_does_not_crash_the_table(): void
    {
        Http::fake([
            '*/v1beta/models*' => Http::response(['models' => [
                ['name' => 'models/odd-shape', 'supportedGenerationMethods' => 'generateContent'],
                ['name' => 'models/sane', 'supportedGenerationMethods' => ['generateContent']],
            ]]),
        ]);

        $this->artisan('video:list-gemini-models', ['--api-version' => 'v1beta'])
            ->expectsOutputToContain('odd-shape')
            ->assertExitCode(0);
    }

    public function test_a_429_is_retried_instead_of_giving_up(): void
    {
        Http::fake([
            '*/v1beta/models*' => Http::sequence()
                ->push(['error' => ['message' => 'rate limited']], 429)
                ->push(['models' => [$this->model('gemini-2.5-flash')]]),
        ]);

        $this->artisan('video:list-gemini-models', ['--api-version' => 'v1beta'])
            ->assertExitCode(0);

        Http::assertSentCount(2);
    }

    public function test_a_status_that_is_not_429_is_never_retried(): void
    {
        Http::fake([
            '*/v1beta/models*' => Http::response(['error' => ['message' => 'nope']], 403),
        ]);

        $this->artisan('video:list-gemini-models', ['--api-version' => 'v1beta'])
            ->assertExitCode(1);

        Http::assertSentCount(1);
    }

    public function test_a_connection_failure_is_reported_not_thrown(): void
    {
        Http::fake(fn () => throw new ConnectionException('dns went away'));

        $this->artisan('video:list-gemini-models')
            ->expectsOutputToContain('Khong noi duoc Gemini')
            ->assertExitCode(1);
    }

    public function test_a_rejected_key_is_reported_with_the_providers_own_message(): void
    {
        Http::fake([
            '*' => Http::response(['error' => ['message' => 'API key not valid']], 403),
        ]);

        $this->assertSame(1, Artisan::call('video:list-gemini-models'));

        $output = Artisan::output();

        $this->assertStringContainsString('403', $output);
        $this->assertStringContainsString('API key not valid', $output);
        $this->assertStringNotContainsString('"error"', $output, 'raw body leaked past error.message');
    }

    public function test_it_never_prints_the_api_key(): void
    {
        $this->fakeModels(['v1beta' => ['gemini-2.5-flash'], 'v1' => []]);

        $this->assertSame(0, Artisan::call('video:list-gemini-models'));

        $this->assertStringNotContainsString(
            self::KEY, Artisan::output(), 'the command leaked the API key',
        );
    }

    public function test_the_json_dump_lands_where_asked_and_carries_no_secret(): void
    {
        $this->fakeModels(['v1beta' => ['gemini-2.5-flash'], 'v1' => []]);

        $path = storage_path('framework/testing/gemini_'.uniqid().'.json');

        $this->artisan('video:list-gemini-models', ['--json' => $path])->assertExitCode(0);

        $this->assertFileExists($path);

        $written = (string) file_get_contents($path);

        $this->assertStringNotContainsString(self::KEY, $written);
        $this->assertArrayHasKey('fetched_at', json_decode($written, true));
        $this->assertSame(
            ['gemini-2.5-flash'],
            array_map(
                fn (array $model) => str_replace('models/', '', $model['name']),
                json_decode($written, true)['versions']['v1beta'],
            ),
        );

        unlink($path);
    }

    public function test_without_the_json_flag_no_file_is_written(): void
    {
        $this->fakeModels(['v1beta' => [], 'v1' => []]);

        $path = resource_path('ai/providers').DIRECTORY_SEPARATOR
            .'gemini_models_'.now()->format('Y_m_d').'.json';
        $existed = is_file($path);

        $this->artisan('video:list-gemini-models')->assertExitCode(0);

        $this->assertSame($existed, is_file($path));
    }

    /** @param array<string, list<string>> $byVersion */
    private function fakeModels(array $byVersion): void
    {
        $fakes = [];

        foreach ($byVersion as $version => $names) {
            $fakes['*/'.$version.'/models*'] = Http::response([
                'models' => array_map(fn (string $name) => $this->model($name), $names),
            ]);
        }

        Http::fake($fakes);
    }

    /** @return array<string, mixed> */
    private function model(string $name): array
    {
        return [
            'name' => 'models/'.$name,
            'displayName' => ucfirst($name),
            'supportedGenerationMethods' => ['generateContent', 'countTokens'],
        ];
    }
}
