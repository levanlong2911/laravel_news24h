<?php

namespace Tests\Feature\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\Article;
use App\Models\Admin;
use App\Models\Role;
use App\Models\Category;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Services\VideoProjectService;
use App\Video\Concept\Claude\AnthropicStructuredOutputResponse;
use App\Video\Concept\Claude\AnthropicStructuredOutputClient;
use App\Video\Concept\Contracts\StructuredOutputLlmClient;
use App\Video\Screenplay\ScreenplayAuthor;
use App\Video\Screenplay\ScreenplayText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ScreenplayV3IntegrationTest extends TestCase
{
    /** @var list<string> */
    private const REQUIRED_TABLES = [
        'keywords', 'categories', 'articles', 'video_projects', 'video_planning_stages',
    ];

    private VideoProject $project;

    private bool $inTransaction = false;

    private string $applicationDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useIsolatedDatabase();

        $category = Category::create([
            'name' => 'TEST yacht '.uniqid(),
            'slug' => 'yacht',
        ]);

        $keyword = (string) Str::uuid();

        DB::table('keywords')->insert([
            'id' => $keyword,
            'name' => 'TEST screenplay v3 '.uniqid(),
            'search_keyword' => 'test screenplay v3',
            'category_id' => $category->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $article = $this->article($keyword, $category->id);

        $this->project = VideoProject::create([
            'title' => 'TEST screenplay v3 '.uniqid(),
            'article_id' => $article->id,
        ]);

        $this->seedInspiration();

        config([
            'video.screenplay.contract_version' => 'screenplay_v3',
            'video.screenplay.prompt_dir' => resource_path('ai/screenplay/v3'),
            'video.screenplay.schema_path' => resource_path('ai/screenplay/schemas/screenplay_v3.json'),
            'video.screenplay.profiles.yacht' => 'yacht_v1',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->inTransaction) {
            DB::rollBack();
            $this->inTransaction = false;
        }

        parent::tearDown();
    }

    private function isolationFailure(string $application, string $isolated): ?string
    {
        if ($isolated === '' || $isolated === $application) {
            return 'This test writes to the database. It refuses to run against the application'
                ." database ({$application}). Point DB_TEST_DATABASE, or DB_TEST_DATABASE_URL,"
                .' at a separate database.';
        }

        return null;
    }

    private function useIsolatedDatabase(): void
    {
        $application = (string) DB::connection(config('database.default'))
            ->selectOne('SELECT DATABASE() AS db')->db;
        $this->applicationDatabase = $application;
        DB::purge('testing');
        $isolated = (string) DB::connection('testing')->selectOne('SELECT DATABASE() AS db')->db;
        $failure = $this->isolationFailure($application, $isolated);

        if ($failure !== null) {
            $this->markTestSkipped($failure);
        }

        config(['database.default' => 'testing']);

        $missing = array_values(array_filter(
            self::REQUIRED_TABLES,
            static fn (string $table): bool => ! Schema::hasTable($table),
        ));

        if ($missing !== []) {
            $this->markTestSkipped(
                "The isolated database {$isolated} is missing ".implode(', ', $missing)
                .". Run: php artisan migrate --database=testing"
            );
        }

        $this->assertNotSame($application, DB::connection()->getDatabaseName());

        DB::beginTransaction();
        $this->inTransaction = true;
    }

    public function test_the_isolated_connection_does_not_inherit_the_application_database_url(): void
    {
        $repository = \Illuminate\Support\Env::getRepository();
        $previous = env('DATABASE_URL');
        try {
            $repository->set('DATABASE_URL', 'mysql://test:test@localhost/application_url_override');
            $database = require base_path('config/database.php');
            $this->assertSame(env('DB_TEST_DATABASE_URL'), $database['connections']['testing']['url']);
            $this->assertNotSame($database['connections']['mysql']['url'], $database['connections']['testing']['url']);
        } finally {
            $previous === null ? $repository->clear('DATABASE_URL') : $repository->set('DATABASE_URL', $previous);
        }
    }

    public function test_a_url_override_is_caught_by_the_guard_even_when_the_name_looks_isolated(): void
    {
        $application = $this->applicationDatabase;

        config(['database.connections.hijacked' => array_merge(
            (array) config('database.connections.testing'),
            ['database' => 'isolated_name_before_url', 'url' => "mysql://user:secret@127.0.0.1:3306/{$application}"],
        )]);
        DB::purge('hijacked');

        $this->assertSame(
            'isolated_name_before_url',
            config('database.connections.hijacked.database'),
            'the unresolved configuration still reports the isolated name',
        );

        $resolved = DB::connection('hijacked')->getDatabaseName();

        $this->assertSame($application, $resolved, 'but the url wins, and it points at the application');
        $this->assertNotNull(
            $this->isolationFailure($application, $resolved),
            'the guard must compare resolved names, or a url override slips past it',
        );
        $this->assertNull($this->isolationFailure($application, $application.'_isolated'));
    }

    private function article(int|string $keywordId, int|string $categoryId): Article
    {
        return Article::create([
            'keyword_id' => $keywordId,
            'category_id' => $categoryId,
            'source_url' => 'https://example.com/'.uniqid(),
            'source_url_hash' => md5(uniqid('x', true)),
            'source_title' => 'TEST screenplay v3 source',
            'title' => 'TEST screenplay v3 article '.uniqid(),
            'slug' => 'test-screenplay-v3-'.uniqid(),
            'content' => 'noi dung test',
            'status' => 'pending',
        ]);
    }

    private function seedInspiration(): void
    {
        VideoPlanningStage::create([
            'project_id' => $this->project->id,
            'planning_revision' => 1,
            'stage' => PlanningStageName::INSPIRATION->value,
            'status' => VideoPlanningStageStatus::SUCCEEDED->value,
            'input_json' => [],
            'input_hash' => hash('sha256', 'inspiration'),
            'output_json' => [
                'source_insights' => [
                    ['aspect' => 'design', 'summary' => 'Open spaces connect the whole vessel.'],
                    ['aspect' => 'structure', 'summary' => 'One unbroken walking surface runs its length.'],
                ],
                'excluded_context' => [['type' => 'contractor', 'value' => 'Vale Engineering']],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function screenplay(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__.'/../../Fixtures/screenplay/v3_screenplay.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string, mixed> */
    private function screenplayWithOmissionsDeclaredAsTransitions(): array
    {
        $screenplay = $this->screenplay();
        $recast = [
            'cov_fin_snag' => [
                ['sc_05', 'sc_06'],
                'Between the fit-out and the signature spaces, any outstanding work is closed out off camera.',
            ],
            'cov_comp_trial' => [
                ['sc_06', 'sc_07'],
                'Between the finished spaces and the handover, the vessel goes out and comes back alongside off camera.',
            ],
        ];

        foreach ($screenplay['coverage'] as $index => $item) {
            if (! array_key_exists($item['coverage_id'], $recast)) {
                continue;
            }

            [$sceneIds, $evidence] = $recast[$item['coverage_id']];
            $screenplay['coverage'][$index]['mode'] = 'transition';
            $screenplay['coverage'][$index]['scene_ids'] = $sceneIds;
            $screenplay['coverage'][$index]['evidence'] = $evidence;
        }

        return $screenplay;
    }

    /** @param array<string, mixed> $screenplay */
    private function serviceAnswering(array $screenplay, ?string $rawOverride = null): VideoProjectService
    {
        $client = Mockery::mock(StructuredOutputLlmClient::class);
        $client->shouldReceive('create')->once()->andReturn(new AnthropicStructuredOutputResponse(
            rawText: $rawOverride ?? json_encode($screenplay, JSON_THROW_ON_ERROR),
            model: 'claude-sonnet-5',
            stopReason: 'end_turn',
            inputTokens: 4100,
            outputTokens: 7300,
        ));

        $this->app->forgetInstance(ScreenplayAuthor::class);
        $this->app->instance('video.screenplay.llm_client', $client);
        $this->app->forgetInstance(VideoProjectService::class);

        return $this->app->make(VideoProjectService::class);
    }

    private function storedStage(): ?VideoPlanningStage
    {
        return VideoPlanningStage::query()
            ->where('project_id', $this->project->id)
            ->where('stage', PlanningStageName::SCREENPLAY->value)
            ->orderByDesc('planning_revision')
            ->first();
    }

    public function test_production_configuration_selects_the_complete_v3_bundle(): void
    {
        $video = require config_path('video.php');
        $this->assertSame('screenplay_v3', $video['screenplay']['contract_version']);
        $this->assertSame(resource_path('ai/screenplay/v3'), $video['screenplay']['prompt_dir']);
        $this->assertSame(resource_path('ai/screenplay/schemas/screenplay_v3.json'), $video['screenplay']['schema_path']);
        $this->assertSame('yacht_v1', $video['screenplay']['profiles']['yacht']);
    }

    public function test_the_http_action_writes_v3_and_the_page_displays_it(): void
    {
        $admin = new Admin(['name' => 'Screenplay test admin']);
        $admin->id = (string) Str::uuid();
        $admin->setRelation('role', new Role(['name' => 'admin']));
        $this->actingAs($admin);
        $this->serviceAnswering($this->screenplayWithOmissionsDeclaredAsTransitions());
        $page = route('video-projects.anchor', $this->project->id);

        $this->from($page)->post(route('video-projects.screenplay', $this->project->id))
            ->assertRedirect($page)
            ->assertSessionHas('success');
        $this->assertSame('screenplay_v3', $this->storedStage()->output_json['schema_version']);
        $this->get($page)->assertOk()
            ->assertSee('screenplay_v3')
            ->assertSee('COVERAGE')
            ->assertSee('Build state:');
    }

    public function test_a_v3_screenplay_runs_through_the_service_into_the_database_and_reads_back(): void
    {
        $screenplay = $this->screenplayWithOmissionsDeclaredAsTransitions();

        [$returned, $reason] = $this->serviceAnswering($screenplay)->authorScreenplay($this->project->id);

        $this->assertSame('ok', $reason);
        $this->assertSame($screenplay, $returned);

        $stage = $this->storedStage();

        $this->assertNotNull($stage);
        $this->assertSame(VideoPlanningStageStatus::SUCCEEDED->value, $stage->status);
        $this->assertSame(json_encode($screenplay, JSON_THROW_ON_ERROR), $stage->raw_response);
        $this->assertSame('screenplay_v3', $stage->output_json['schema_version']);
        $this->assertSame('claude-sonnet-5', $stage->output_json['author_model']);
        $this->assertSame([], $stage->output_json['warnings']);
        $this->assertSame(4100, $stage->tokens_in);
        $this->assertSame(7300, $stage->tokens_out);
        $this->assertGreaterThan(0, (float) $stage->cost_usd);
    }

    public function test_what_the_screen_reads_back_is_what_the_database_holds(): void
    {
        $service = $this->serviceAnswering($this->screenplayWithOmissionsDeclaredAsTransitions());
        $service->authorScreenplay($this->project->id);

        $latest = $service->latestScreenplay($this->project->id);

        $this->assertNotNull($latest['screenplay']);
        $this->assertSame([], $latest['warnings']);
        $this->assertNull($latest['error']);
        $this->assertFalse($latest['running']);

        $text = ScreenplayText::render($latest['screenplay']);

        $this->assertStringContainsString('COVERAGE', $text);
        $this->assertStringContainsString('Build state: ch_vessel — ', $text);
        $this->assertStringContainsString('· group]', $text);
        $this->assertStringNotContainsString('is not supported by this view', $text);
    }

    public function test_a_rejected_screenplay_keeps_its_raw_response_and_its_cost_in_the_database(): void
    {
        $screenplay = $this->screenplay();
        $screenplay['scenes'][0]['location_id'] = 'lo_nowhere';

        [$returned, $reason] = $this->serviceAnswering($screenplay)->authorScreenplay($this->project->id);

        $this->assertNull($returned);
        $this->assertSame('screenplay_invalid', $reason);

        $stage = $this->storedStage();

        $this->assertNotNull($stage);
        $this->assertSame(VideoPlanningStageStatus::FAILED->value, $stage->status);
        $this->assertStringContainsString('lo_nowhere', (string) $stage->error_message);
        $this->assertSame(json_encode($screenplay, JSON_THROW_ON_ERROR), $stage->raw_response);
        $this->assertSame(4100, $stage->tokens_in);
        $this->assertGreaterThan(0, (float) $stage->cost_usd);
    }

    public function test_an_unparsable_answer_is_stored_as_a_failure_with_its_raw_text(): void
    {
        [$returned, $reason] = $this->serviceAnswering([], 'this is not json')
            ->authorScreenplay($this->project->id);

        $this->assertNull($returned);
        $this->assertSame('screenplay_author_failed', $reason);

        $stage = $this->storedStage();

        $this->assertNotNull($stage);
        $this->assertSame(VideoPlanningStageStatus::FAILED->value, $stage->status);
        $this->assertSame('this is not json', $stage->raw_response);
        $this->assertGreaterThan(0, (float) $stage->cost_usd);
    }

    /** @dataProvider paidProviderFailures */
    public function test_real_client_failure_preserves_the_paid_response(string $stopReason, array $content): void
    {
        $body = json_encode([
            'model' => 'claude-sonnet-5',
            'stop_reason' => $stopReason,
            'content' => $content,
            'usage' => ['input_tokens' => 4100, 'output_tokens' => 7300],
        ], JSON_THROW_ON_ERROR);
        $http = new \Illuminate\Http\Client\Factory;
        $http->preventStrayRequests();
        $http->fake(['https://screenplay.test/v1/messages' => $http->response($body, 200)]);
        $this->app->instance('video.screenplay.llm_client', new AnthropicStructuredOutputClient(
            $http, 'fake-key', 'https://screenplay.test', '2023-06-01', 5, 0, 0,
        ));
        $this->app->forgetInstance(ScreenplayAuthor::class);
        $this->app->forgetInstance(VideoProjectService::class);

        $result = $this->app->make(VideoProjectService::class)->authorScreenplay($this->project->id);

        $this->assertSame([null, 'screenplay_author_failed'], $result);
        $stage = $this->storedStage();
        $this->assertSame(VideoPlanningStageStatus::FAILED->value, $stage->status);
        $this->assertSame($body, $stage->raw_response);
        $this->assertSame(4100, $stage->tokens_in);
        $this->assertSame(7300, $stage->tokens_out);
        $this->assertGreaterThan(0, (float) $stage->cost_usd);
        $this->assertNull($stage->output_json);
        $http->assertSentCount(1);
    }

    public static function paidProviderFailures(): array
    {
        return [
            'truncated JSON' => ['max_tokens', [['type' => 'text', 'text' => '{"scenes":']]],
            'refusal without text' => ['refusal', []],
        ];
    }

    public function test_an_editorial_warning_is_stored_and_surfaced_without_blocking_the_run(): void
    {
        [$returned, $reason] = $this->serviceAnswering($this->screenplay())->authorScreenplay($this->project->id);

        $this->assertNotNull($returned);
        $this->assertSame('ok_needs_review', $reason);

        $latest = $this->app->make(VideoProjectService::class)->latestScreenplay($this->project->id);

        $this->assertNotSame([], $latest['warnings']);
        $this->assertStringContainsString('cov_fin_snag', implode(' ', $latest['warnings']));
        $this->assertArrayNotHasKey('warnings', $latest['screenplay']);

        $this->assertStringContainsString(
            'SET ASIDE — needs editorial review',
            ScreenplayText::render($latest['screenplay']),
        );
    }

    public function test_the_same_input_is_not_paid_for_twice(): void
    {
        $screenplay = $this->screenplay();
        $this->serviceAnswering($screenplay)->authorScreenplay($this->project->id);

        $client = Mockery::mock(StructuredOutputLlmClient::class);
        $client->shouldNotReceive('create');
        $this->app->forgetInstance(ScreenplayAuthor::class);
        $this->app->instance('video.screenplay.llm_client', $client);
        $this->app->forgetInstance(VideoProjectService::class);

        [$cached, $reason] = $this->app->make(VideoProjectService::class)
            ->authorScreenplay($this->project->id);

        $this->assertSame('cached', $reason);
        $this->assertSame('screenplay_v3', $cached['schema_version']);
    }

    public function test_a_v2_row_written_before_the_switch_still_reads_under_a_v3_configuration(): void
    {
        $legacy = json_decode(
            (string) file_get_contents(resource_path('ai/screenplay/v2/04_worked_example.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        VideoPlanningStage::create([
            'project_id' => $this->project->id,
            'planning_revision' => 9,
            'stage' => PlanningStageName::SCREENPLAY->value,
            'status' => VideoPlanningStageStatus::SUCCEEDED->value,
            'input_json' => [],
            'input_hash' => hash('sha256', 'old screenplay'),
            'output_json' => $legacy + ['schema_version' => 'screenplay_v2', 'warnings' => []],
        ]);

        $latest = $this->app->make(VideoProjectService::class)->latestScreenplay($this->project->id);

        $this->assertSame('screenplay_v2', $latest['screenplay']['schema_version']);

        $text = ScreenplayText::render($latest['screenplay']);

        $this->assertStringNotContainsString('is not supported by this view', $text);
        $this->assertStringNotContainsString('COVERAGE', $text);
        $this->assertStringNotContainsString('Build state', $text);
        $this->assertStringContainsString('ESTIMATED TOTAL', $text);
    }
    private function timingOutClient(string $message, int $errno, int &$sent): AnthropicStructuredOutputClient
    {
        $http = new \Illuminate\Http\Client\Factory;
        $http->preventStrayRequests();
        $http->fake(['https://screenplay.test/*' => static function () use ($message, $errno, &$sent) {
            $sent++;

            throw new \Illuminate\Http\Client\ConnectionException(
                $message,
                0,
                new \GuzzleHttp\Exception\ConnectException(
                    $message,
                    new \GuzzleHttp\Psr7\Request('POST', 'https://screenplay.test/v1/messages'),
                    null,
                    ['errno' => $errno],
                ),
            );
        }]);

        return new AnthropicStructuredOutputClient(
            $http,
            'fake-key',
            'https://screenplay.test',
            '2023-06-01',
            (int) config('video.screenplay.timeout_seconds'),
            (int) config('video.screenplay.retry_times'),
            0,
        );
    }

    private function serviceUsing(AnthropicStructuredOutputClient $client): VideoProjectService
    {
        $this->app->instance('video.screenplay.llm_client', $client);
        $this->app->forgetInstance(ScreenplayAuthor::class);
        $this->app->forgetInstance(VideoProjectService::class);

        return $this->app->make(VideoProjectService::class);
    }

    /** @dataProvider executionBudgets */
    public function test_php_is_given_time_for_the_attempts_that_will_actually_be_made(
        int $timeout,
        int $attempts,
        int $sleepMs,
        int $expected,
    ): void {
        $client = new AnthropicStructuredOutputClient(
            new \Illuminate\Http\Client\Factory,
            'fake-key',
            'https://screenplay.test',
            '2023-06-01',
            $timeout,
            $attempts,
            $sleepMs,
        );

        $extend = (new \ReflectionClass($client))->getMethod('extendPhpExecutionTime');
        $extend->setAccessible(true);

        $before = ini_get('max_execution_time');

        try {
            $extend->invoke($client);

            $this->assertSame($expected, (int) ini_get('max_execution_time'));
        } finally {
            @ini_set('max_execution_time', (string) $before);
        }
    }

    /** @return array<string, array{0: int, 1: int, 2: int, 3: int}> */
    public static function executionBudgets(): array
    {
        return [
            'screenplay: one attempt of 1800s' => [1800, 1, 0, 1830],
            'concept: two attempts of 360s with a 500ms gap' => [360, 2, 500, 751],
            'a zero attempt count still gets one attempt' => [600, 0, 0, 630],
        ];
    }

    public function test_a_timeout_sends_exactly_one_request_and_records_the_failure(): void
    {
        $sent = 0;
        $client = $this->timingOutClient(
            'cURL error 28: Operation timed out after 1800000 milliseconds with 0 bytes received',
            28,
            $sent,
        );

        $result = $this->serviceUsing($client)->authorScreenplay($this->project->id);

        $this->assertSame([null, 'screenplay_timeout'], $result);
        $this->assertSame(1, $sent, 'the production configuration must send exactly one request');

        $stage = $this->storedStage();

        $this->assertNotNull($stage);
        $this->assertSame(VideoPlanningStageStatus::FAILED->value, $stage->status);
        $this->assertStringContainsString('cURL error 28', (string) $stage->error_message);
        $this->assertNull($stage->claim_token, 'the claim must be released');
        $this->assertNull($stage->lease_expires_at);
        $this->assertNull($stage->raw_response, 'no response arrived, so there is no raw to keep');
    }

    public function test_a_connection_error_that_is_not_a_timeout_is_labelled_differently(): void
    {
        $sent = 0;
        $client = $this->timingOutClient('cURL error 6: Could not resolve host: screenplay.test', 6, $sent);

        $result = $this->serviceUsing($client)->authorScreenplay($this->project->id);

        $this->assertSame([null, 'screenplay_connection_failed'], $result);
        $this->assertSame(1, $sent);
        $this->assertSame(VideoPlanningStageStatus::FAILED->value, $this->storedStage()->status);
    }

    public function test_the_screenplay_client_is_its_own_and_leaves_the_concept_client_alone(): void
    {
        $screenplay = $this->app->make('video.screenplay.llm_client');
        $concept = $this->app->make(AnthropicStructuredOutputClient::class);

        $this->assertNotSame($screenplay, $concept);

        $read = static function (object $client, string $name): mixed {
            $property = (new \ReflectionClass($client))->getProperty($name);
            $property->setAccessible(true);

            return $property->getValue($client);
        };

        $this->assertSame((int) config('video.screenplay.timeout_seconds'), $read($screenplay, 'timeoutSeconds'));
        $this->assertSame(1, $read($screenplay, 'retryTimes'));
        $this->assertSame((int) config('canonical_concept.anthropic.timeout'), $read($concept, 'timeoutSeconds'));
        $this->assertSame(
            (int) config('canonical_concept.anthropic.http_retry_times'),
            $read($concept, 'retryTimes'),
        );

        $author = $this->app->make(ScreenplayAuthor::class);
        $property = (new \ReflectionClass($author))->getProperty('client');
        $property->setAccessible(true);

        $this->assertSame($screenplay, $property->getValue($author));
    }
}
