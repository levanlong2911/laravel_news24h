<?php

namespace Tests\Feature\Video;

use App\Enums\PlanningStageName;
use App\Models\Article;
use App\Models\Category;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Services\Video\PlanningStageStore;
use App\Services\VideoProjectService;
use App\Video\Concept\Claude\AnthropicStructuredOutputResponse;
use App\Video\Concept\Contracts\StructuredOutputLlmClient;
use App\Video\Screenplay\ScreenplayAuthor;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ScreenplayPostResponseTest extends TestCase
{
    private const STAGE_ID = 'stage-post-response';

    private ?string $profileDirectory = null;

    protected function setUp(): void
    {
        parent::setUp();

        // These regression cases supply v2 profiles and model responses.
        config([
            'video.screenplay.contract_version' => 'screenplay_v2',
            'video.screenplay.prompt_dir' => resource_path('ai/screenplay/v2'),
            'video.screenplay.schema_path' => resource_path('ai/screenplay/schemas/screenplay_v2.json'),
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->profileDirectory !== null) {
            unlink($this->profileDirectory.'/guard.json');
            rmdir($this->profileDirectory);
            $this->profileDirectory = null;
        }

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function example(): array
    {
        return json_decode(
            (string) file_get_contents(resource_path('ai/screenplay/v2/04_worked_example.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @return array{0: VideoProjectService, 1: \Mockery\MockInterface}
     */
    private function serviceReturning(array $screenplay, mixed $excludedContext = []): array
    {
        $profile = json_decode(
            (string) file_get_contents(resource_path('ai/profiles/screenplay/superyacht_v1.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->profileDirectory = sys_get_temp_dir().'/screenplay-post-'.Str::uuid();
        mkdir($this->profileDirectory);
        file_put_contents($this->profileDirectory.'/guard.json', json_encode($profile, JSON_THROW_ON_ERROR));
        config([
            'video.screenplay.profile_dir' => $this->profileDirectory,
            'video.screenplay.profiles.yacht' => 'guard',
        ]);

        $project = new VideoProject;
        $project->setRelation(
            'article',
            (new Article)->setRelation('category', new Category(['slug' => 'yacht'])),
        );

        $repository = Mockery::mock(VideoProjectRepositoryInterface::class);
        $repository->shouldReceive('getById')->once()->with('post-project')->andReturn($project);

        $stage = (new VideoPlanningStage)->forceFill(['id' => self::STAGE_ID]);
        $store = Mockery::mock(PlanningStageStore::class);
        $store->shouldReceive('latestOutputForProject')->once()
            ->with('post-project', PlanningStageName::INSPIRATION)
            ->andReturn([
                'source_insights' => [[
                    'aspect' => 'design',
                    'summary' => 'Open spaces connect the vessel.',
                ]],
                'excluded_context' => $excludedContext,
            ]);
        $store->shouldReceive('claimProjectStage')->once()->andReturn([$stage, 'claim-token', 'claimed']);

        $client = Mockery::mock(StructuredOutputLlmClient::class);
        $client->shouldReceive('create')->once()->andReturn(new AnthropicStructuredOutputResponse(
            rawText: json_encode($screenplay, JSON_THROW_ON_ERROR),
            model: 'claude-sonnet-5',
            stopReason: 'end_turn',
            inputTokens: 1200,
            outputTokens: 3400,
        ));

        $this->app->instance(VideoProjectRepositoryInterface::class, $repository);
        $this->app->instance(PlanningStageStore::class, $store);
        $this->app->forgetInstance(ScreenplayAuthor::class);
        $this->app->singleton(ScreenplayAuthor::class, static fn (): ScreenplayAuthor => new ScreenplayAuthor(
            client: $client,
            promptDir: (string) config('video.screenplay.prompt_dir'),
            schemaPath: (string) config('video.screenplay.schema_path'),
            promptVersion: (string) config('video.screenplay.prompt_version'),
            model: (string) config('video.screenplay.model'),
            maxTokens: (int) config('video.screenplay.max_tokens'),
            contractVersion: (string) config('video.screenplay.contract_version'),
        ));
        $this->app->forgetInstance(VideoProjectService::class);

        return [$this->app->make(VideoProjectService::class), $store];
    }

    public function test_an_unexpected_failure_after_the_response_still_records_raw_and_usage(): void
    {
        $screenplay = $this->example();
        [$service, $store] = $this->serviceReturning($screenplay, ['a term that is not an object']);

        $store->shouldNotReceive('finishSucceeded');
        $store->shouldReceive('finishFailed')->once()
            ->with(
                self::STAGE_ID,
                'claim-token',
                Mockery::type('string'),
                Mockery::on(static fn (array $usage): bool => ($usage['tokens_in'] ?? null) === 1200
                    && ($usage['tokens_out'] ?? null) === 3400
                    && ($usage['cost_usd'] ?? 0) > 0),
                json_encode($screenplay, JSON_THROW_ON_ERROR),
            )
            ->andReturnTrue();

        $this->assertSame(
            [null, 'screenplay_after_response_failed'],
            $service->authorScreenplay('post-project'),
        );
    }

    public function test_a_lost_claim_after_the_response_is_reported_as_a_lost_claim(): void
    {
        [$service, $store] = $this->serviceReturning($this->example(), ["a term that is not an object"]);

        $store->shouldNotReceive('finishSucceeded');
        $store->shouldReceive('finishFailed')->once()->andReturnFalse();

        $this->assertSame(
            [null, 'screenplay_claim_lost'],
            $service->authorScreenplay('post-project'),
        );
    }

    public function test_a_write_that_throws_is_never_reported_as_a_stored_failure(): void
    {
        [$service, $store] = $this->serviceReturning($this->example(), ["a term that is not an object"]);

        $store->shouldNotReceive('finishSucceeded');
        $store->shouldReceive('finishFailed')->once()
            ->andThrow(new \RuntimeException('the connection went away mid-write'));

        $this->assertSame(
            [null, 'screenplay_result_not_stored'],
            $service->authorScreenplay('post-project'),
        );
    }

    public function test_a_failed_validation_is_still_reported_as_an_invalid_screenplay(): void
    {
        $screenplay = $this->example();
        $screenplay['scenes'][0]['location_id'] = 'lo_nowhere';
        [$service, $store] = $this->serviceReturning($screenplay);

        $store->shouldNotReceive('finishSucceeded');
        $store->shouldReceive('finishFailed')->once()
            ->with(
                self::STAGE_ID,
                'claim-token',
                Mockery::on(static fn (string $error): bool => str_contains($error, 'lo_nowhere')),
                Mockery::type('array'),
                json_encode($screenplay, JSON_THROW_ON_ERROR),
            )
            ->andReturnTrue();

        $this->assertSame(
            [null, 'screenplay_invalid'],
            $service->authorScreenplay('post-project'),
        );
    }

    public function test_the_success_path_is_unchanged_and_stores_the_paid_result(): void
    {
        $screenplay = $this->example();
        [$service, $store] = $this->serviceReturning($screenplay);

        $store->shouldNotReceive('finishFailed');
        $store->shouldReceive('finishSucceeded')->once()
            ->with(
                self::STAGE_ID,
                'claim-token',
                json_encode($screenplay, JSON_THROW_ON_ERROR),
                Mockery::on(static fn (array $output): bool => ($output['schema_version'] ?? null) === (string) config('video.screenplay.contract_version')
                    && ($output['author_model'] ?? null) === 'claude-sonnet-5'
                    && ($output['warnings'] ?? null) === []),
                Mockery::type('array'),
            )
            ->andReturnTrue();

        [$stored, $reason] = $service->authorScreenplay('post-project');

        $this->assertSame('ok', $reason);
        $this->assertSame($screenplay, $stored);
    }

    public function test_a_claim_lost_on_the_success_path_keeps_its_own_reason(): void
    {
        [$service, $store] = $this->serviceReturning($this->example());

        $store->shouldNotReceive('finishFailed');
        $store->shouldReceive('finishSucceeded')->once()->andReturnFalse();

        $this->assertSame(
            [null, 'screenplay_claim_lost'],
            $service->authorScreenplay('post-project'),
        );
    }

    public function test_a_success_write_that_throws_never_claims_to_know_what_was_stored(): void
    {
        [$service, $store] = $this->serviceReturning($this->example());

        $store->shouldReceive('finishSucceeded')->once()
            ->andThrow(new \RuntimeException('the connection went away mid-write'));
        $store->shouldNotReceive('finishFailed');

        $this->assertSame(
            [null, 'screenplay_result_not_stored'],
            $service->authorScreenplay('post-project'),
        );
    }
}
