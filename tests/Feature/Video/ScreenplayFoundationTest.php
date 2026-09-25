<?php

namespace Tests\Feature\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Category;
use App\Models\Role;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Services\VideoProjectService;
use App\Video\Concept\Claude\AnthropicStructuredOutputClient;
use App\Video\Concept\Claude\AnthropicStructuredOutputResponse;
use App\Video\Concept\Contracts\StructuredOutputLlmClient;
use App\Video\Screenplay\ScreenplayAuthor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ScreenplayFoundationTest extends TestCase
{
    /** @var list<string> */
    private const REQUIRED_TABLES = [
        'keywords', 'categories', 'articles', 'video_projects', 'video_planning_stages',
    ];

    private VideoProject $project;

    private bool $inTransaction = false;

    protected function setUp(): void
    {
        parent::setUp();

        $application = (string) DB::connection(config('database.default'))
            ->selectOne('SELECT DATABASE() AS db')->db;
        DB::purge('testing');
        $isolated = (string) DB::connection('testing')->selectOne('SELECT DATABASE() AS db')->db;

        if ($isolated === '' || $isolated === $application) {
            $this->markTestSkipped("Refusing to write to the application database ({$application}).");
        }

        config(['database.default' => 'testing']);

        foreach (self::REQUIRED_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                $this->markTestSkipped("The isolated database is missing {$table}. Run: php artisan migrate --database=testing");
            }
        }

        DB::beginTransaction();
        $this->inTransaction = true;

        $category = Category::create(['name' => 'TEST yacht '.uniqid(), 'slug' => 'yacht']);
        $keyword = (string) Str::uuid();

        DB::table('keywords')->insert([
            'id' => $keyword,
            'name' => 'TEST foundation '.uniqid(),
            'search_keyword' => 'test foundation',
            'category_id' => $category->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $article = Article::create([
            'keyword_id' => $keyword,
            'category_id' => $category->id,
            'source_url' => 'https://example.com/'.uniqid(),
            'source_url_hash' => md5(uniqid('x', true)),
            'source_title' => 'TEST foundation source',
            'title' => 'TEST foundation article '.uniqid(),
            'slug' => 'test-foundation-'.uniqid(),
            'content' => 'noi dung test',
            'status' => 'pending',
        ]);

        $this->project = VideoProject::create([
            'title' => 'TEST foundation '.uniqid(),
            'article_id' => $article->id,
        ]);

        $this->stage(PlanningStageName::INSPIRATION, [
            'source_insights' => [
                ['aspect' => 'design', 'summary' => 'Open spaces connect the whole vessel.'],
                ['aspect' => 'structure', 'summary' => 'One unbroken walking surface runs its length.'],
            ],
            'excluded_context' => [['type' => 'contractor', 'value' => 'Vale Engineering']],
        ]);

        config(['video.screenplay.profiles.yacht' => 'yacht_v1']);
    }

    protected function tearDown(): void
    {
        if ($this->inTransaction) {
            DB::rollBack();
            $this->inTransaction = false;
        }

        parent::tearDown();
    }

    /** @param array<string, mixed> $output */
    private function stage(PlanningStageName $name, array $output, array $columns = []): VideoPlanningStage
    {
        return VideoPlanningStage::create($columns + [
            'project_id' => $this->project->id,
            'planning_revision' => 1,
            'stage' => $name->value,
            'status' => VideoPlanningStageStatus::SUCCEEDED->value,
            'input_json' => [],
            'input_hash' => hash('sha256', $name->value.uniqid()),
            'output_json' => $output,
        ]);
    }

    /** @return array<string, mixed> */
    private function read(string $name): array
    {
        return json_decode(
            (string) file_get_contents(resource_path('ai/screenplay/foundation_v2/'.$name)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<string, mixed> */
    private function foundation(): array
    {
        $answer = $this->read('04_worked_example.json');
        $answer['principal_dimensions'] = [
            'length_m' => 140,
            'beam_m' => 23.5,
            'rationale' => 'Length follows from the sequence of spaces the design strings along the hull, and beam from the widest of them.',
        ];

        return $answer;
    }

    /** @return array<string, mixed> */
    private function yachtFoundationProfile(): array
    {
        $shape = new \ReflectionMethod(VideoProjectService::class, 'foundationProfile');
        $shape->setAccessible(true);

        return $shape->invoke(null, $this->read('../../profiles/screenplay/yacht_v1.json'));
    }

    /**
     * @param  array<string, mixed>  $answer
     * @return list<string>
     */
    private function violations(array $answer): array
    {
        return (new \App\Video\Screenplay\ScreenplayValidator)
            ->structural($answer, $this->yachtFoundationProfile(), 'screenplay_foundation_v2');
    }

    private function authorUsing(StructuredOutputLlmClient $client): ScreenplayAuthor
    {
        return new ScreenplayAuthor(
            client: $client,
            promptDir: (string) config('video.screenplay.foundation.prompt_dir'),
            schemaPath: (string) config('video.screenplay.foundation.schema_path'),
            promptVersion: (string) config('video.screenplay.foundation.prompt_version'),
            model: (string) config('video.screenplay.model'),
            maxTokens: (int) config('video.screenplay.foundation.max_tokens'),
            contractVersion: (string) config('video.screenplay.foundation.contract_version'),
        );
    }

    /** @param array<string, mixed> $answer */
    private function serviceAnswering(array $answer): VideoProjectService
    {
        $client = Mockery::mock(StructuredOutputLlmClient::class);
        $client->shouldReceive('create')->once()->andReturn(new AnthropicStructuredOutputResponse(
            rawText: json_encode($answer, JSON_THROW_ON_ERROR),
            model: 'claude-sonnet-5',
            stopReason: 'end_turn',
            inputTokens: 5200,
            outputTokens: 1100,
        ));

        return $this->serviceWith($this->authorUsing($client));
    }

    private function serviceWith(ScreenplayAuthor $foundationAuthor): VideoProjectService
    {
        $this->app->instance('video.screenplay.foundation_author', $foundationAuthor);
        $this->app->forgetInstance(VideoProjectService::class);

        return $this->app->make(VideoProjectService::class);
    }

    private function storedFoundation(): ?VideoPlanningStage
    {
        return VideoPlanningStage::query()
            ->where('project_id', $this->project->id)
            ->where('stage', PlanningStageName::SCREENPLAY_FOUNDATION->value)
            ->orderByDesc('planning_revision')
            ->first();
    }

    public function test_a_foundation_is_written_stored_and_shown_in_panel_three(): void
    {
        $admin = new Admin(['name' => 'Foundation test admin']);
        $admin->id = (string) Str::uuid();
        $admin->setRelation('role', new Role(['name' => 'admin']));
        $this->actingAs($admin);

        $this->serviceAnswering($this->foundation());
        $page = route('video-projects.anchor', $this->project->id);

        $this->from($page)->post(route('video-projects.screenplay-foundation', $this->project->id))
            ->assertRedirect($page)
            ->assertSessionHas('success');

        $stage = $this->storedFoundation();

        $this->assertNotNull($stage);
        $this->assertSame(VideoPlanningStageStatus::SUCCEEDED->value, $stage->status);
        $this->assertSame('screenplay_foundation_v2', $stage->output_json['schema_version']);
        $this->assertSame(5200, $stage->tokens_in);
        $this->assertSame(1100, $stage->tokens_out);
        $this->assertGreaterThan(0, (float) $stage->cost_usd);
        $this->assertArrayNotHasKey('scenes', $stage->output_json);

        $this->get($page)->assertOk()
            ->assertSee('Đã có nội dung')
            ->assertSee('screenplay_foundation_v2')
            ->assertSee('SYNOPSIS')
            ->assertSee('PRINCIPAL DIMENSIONS')
            ->assertSee('140 m × 23.5 m')
            ->assertSee('Carries into the next')
            ->assertSee('No scenes yet.');
    }

    public function test_an_answer_that_carries_scenes_is_refused_and_its_cost_is_kept(): void
    {
        $answer = $this->foundation() + ['scenes' => [['id' => 'sc_01']]];

        [$returned, $reason] = $this->serviceAnswering($answer)
            ->authorScreenplayFoundation($this->project->id);

        $this->assertNull($returned);
        $this->assertSame('screenplay_invalid', $reason);

        $stage = $this->storedFoundation();

        $this->assertSame(VideoPlanningStageStatus::FAILED->value, $stage->status);
        $this->assertStringContainsString('scenes: this step does not produce it', (string) $stage->error_message);
        $this->assertSame(json_encode($answer, JSON_THROW_ON_ERROR), $stage->raw_response);
        $this->assertGreaterThan(0, (float) $stage->cost_usd);
    }

    public function test_a_foundation_with_a_stage_missing_or_out_of_order_is_refused(): void
    {
        $answer = $this->foundation();
        [$answer['stage_treatments'][0], $answer['stage_treatments'][1]]
            = [$answer['stage_treatments'][1], $answer['stage_treatments'][0]];

        [, $reason] = $this->serviceAnswering($answer)->authorScreenplayFoundation($this->project->id);

        $this->assertSame('screenplay_invalid', $reason);
        $this->assertStringContainsString(
            'must carry every arc_stage exactly once, in order',
            (string) $this->storedFoundation()->error_message,
        );
    }

    public function test_a_timeout_sends_one_request_and_is_recorded_on_the_foundation_stage(): void
    {
        $sent = 0;
        $http = new \Illuminate\Http\Client\Factory;
        $http->preventStrayRequests();
        $http->fake(['https://screenplay.test/*' => static function () use (&$sent) {
            $sent++;

            throw new \Illuminate\Http\Client\ConnectionException(
                'cURL error 28: Operation timed out after 600000 milliseconds with 0 bytes received',
                0,
                new \GuzzleHttp\Exception\ConnectException(
                    'cURL error 28: Operation timed out',
                    new \GuzzleHttp\Psr7\Request('POST', 'https://screenplay.test/v1/messages'),
                    null,
                    ['errno' => 28],
                ),
            );
        }]);

        $client = new AnthropicStructuredOutputClient(
            $http, 'fake-key', 'https://screenplay.test', '2023-06-01',
            (int) config('video.screenplay.foundation.timeout_seconds'),
            (int) config('video.screenplay.foundation.retry_times'),
            0,
        );

        [$returned, $reason] = $this->serviceWith($this->authorUsing($client))
            ->authorScreenplayFoundation($this->project->id);

        $this->assertNull($returned);
        $this->assertSame('screenplay_timeout', $reason);
        $this->assertSame(1, $sent);

        $stage = $this->storedFoundation();

        $this->assertSame(VideoPlanningStageStatus::FAILED->value, $stage->status);
        $this->assertNull($stage->claim_token);
        $this->assertStringContainsString('cURL error 28', (string) $stage->error_message);
        $this->assertSame(
            0,
            VideoPlanningStage::query()
                ->where('project_id', $this->project->id)
                ->where('stage', PlanningStageName::SCREENPLAY->value)
                ->count(),
            'a foundation failure must never write a screenplay row',
        );
    }

    public function test_resetting_the_foundation_leaves_the_full_screenplay_alone(): void
    {
        $screenplay = $this->stage(PlanningStageName::SCREENPLAY, ['logline' => 'Stored v3 screenplay'], [
            'planning_revision' => 3,
        ]);
        $running = $this->stage(PlanningStageName::SCREENPLAY_FOUNDATION, [], [
            'planning_revision' => 4,
            'status' => VideoPlanningStageStatus::RUNNING->value,
            'claim_token' => (string) Str::uuid(),
            'lease_expires_at' => now()->addMinutes(10),
        ]);

        [$done] = $this->app->make(VideoProjectService::class)
            ->resetScreenplayFoundation($this->project->id);

        $this->assertTrue($done);

        $running->refresh();
        $screenplay->refresh();

        $this->assertSame(VideoPlanningStageStatus::FAILED->value, $running->status);
        $this->assertNull($running->claim_token);
        $this->assertSame(VideoPlanningStageStatus::SUCCEEDED->value, $screenplay->status);
        $this->assertSame(['logline' => 'Stored v3 screenplay'], $screenplay->output_json);
    }

    public function test_writing_a_foundation_never_touches_the_full_screenplay_author(): void
    {
        $v3Client = Mockery::mock(StructuredOutputLlmClient::class);
        $v3Client->shouldNotReceive('create');
        $this->app->instance('video.screenplay.llm_client', $v3Client);

        $resolved = false;
        $this->app->forgetInstance(ScreenplayAuthor::class);
        $this->app->bind(ScreenplayAuthor::class, static function () use (&$resolved) {
            $resolved = true;

            throw new \LogicException('the full screenplay author must not be built for a foundation');
        });

        [$returned, $reason] = $this->serviceAnswering($this->foundation())
            ->authorScreenplayFoundation($this->project->id);

        $this->assertSame('ok', $reason);
        $this->assertNotNull($returned);
        $this->assertFalse($resolved);
    }

    public function test_the_production_foundation_author_has_its_own_client_and_limits(): void
    {
        $this->app->forgetInstance('video.screenplay.foundation_author');
        $this->app->forgetInstance(ScreenplayAuthor::class);

        $foundation = $this->app->make('video.screenplay.foundation_author');
        $full = $this->app->make(ScreenplayAuthor::class);

        $read = static function (object $object, string $name): mixed {
            $property = (new \ReflectionClass($object))->getProperty($name);
            $property->setAccessible(true);

            return $property->getValue($object);
        };

        $this->assertSame('screenplay_foundation_v2', $foundation->contractVersion());
        $this->assertSame((int) config('video.screenplay.foundation.max_tokens'), $read($foundation, 'maxTokens'));
        $this->assertNotSame($read($full, 'client'), $read($foundation, 'client'));
        $this->assertNotSame($this->app->make('video.screenplay.llm_client'), $read($foundation, 'client'));

        $rules = (new \ReflectionClass($foundation))->getMethod('rules');
        $rules->setAccessible(true);
        $prompt = (string) $rules->invoke($foundation);

        $this->assertStringNotContainsString('scene boundaries are drawn', $prompt);
        $this->assertDoesNotMatchRegularExpression('/^COVERAGE$/m', $prompt);
        $this->assertStringNotContainsString('BUILD STATE', $prompt);
        $this->assertStringContainsString('Do not write scenes.', $prompt);
        $this->assertStringContainsString('SOURCE DISTANCE', $prompt);
        $this->assertStringContainsString('PRINCIPAL DIMENSIONS', $prompt);
        $this->assertStringContainsString('FINAL CHECK', $prompt);
        $this->assertStringNotContainsString('open mesh', $prompt);
    }

    public function test_the_worked_example_satisfies_the_contract_it_teaches(): void
    {
        $input = $this->read('04_worked_example.input.json');

        $this->assertSame([], (new \App\Video\Screenplay\ScreenplayValidator)->structural(
            $this->read('04_worked_example.json'),
            $input['profile'],
            'screenplay_foundation_v2',
        ));
        $this->assertSame([], (new \App\Video\Screenplay\ScreenplayValidator)
            ->profileViolations($input['profile'], 'screenplay_foundation_v2'));
    }

    public function test_the_worked_example_carries_nothing_concrete_from_its_inspiration(): void
    {
        $example = $this->read('04_worked_example.json');
        $text = mb_strtolower(json_encode($example, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        foreach (['/catamaran/', '/aluminium/', '/saloon/', '/\bbar\b/', '/upper deck/', '/\bcity\b/'] as $sourceFeature) {
            $this->assertDoesNotMatchRegularExpression($sourceFeature, $text, $sourceFeature);
        }

        $this->assertNotSame(42, $example['principal_dimensions']['length_m']);
        $this->assertNotEquals(10, $example['principal_dimensions']['beam_m']);
    }

    public function test_the_worked_example_input_never_names_a_mechanism_the_answer_is_built_on(): void
    {
        $input = mb_strtolower(json_encode($this->read('04_worked_example.input.json')['inspiration'], JSON_THROW_ON_ERROR));

        foreach (['/\bturn/', '/\bturnaround/', '/\bramps?\b/', '/\bentrances?\b/', '/\bwheelhouse/',
            '/\bhelm/', '/bow loading/', '/single access/'] as $mechanism) {
            $this->assertDoesNotMatchRegularExpression($mechanism, $input, $mechanism);
        }

        $this->assertStringContainsString('each stop to take less of the crossing', $input);
    }

    public function test_the_worked_example_output_describes_what_exists_not_a_counterfactual(): void
    {
        $text = mb_strtolower(json_encode($this->read('04_worked_example.json'), JSON_THROW_ON_ERROR));

        foreach (['instead of', 'rather than', 'single door', 'one door', 'pointed front', 'raised steering',
            'falls back', 'nobody walks', 'never along', 'no front', 'no bow'] as $contrast) {
            $this->assertStringNotContainsString($contrast, $text, $contrast);
        }
    }

    /** @return array<string, mixed> */
    private function frozenManifest(): array
    {
        return json_decode(
            (string) file_get_contents(resource_path('ai/screenplay/foundation_v1/manifest.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    public function test_every_frozen_v1_artifact_keeps_its_recorded_checksum(): void
    {
        $manifest = $this->frozenManifest();

        $this->assertSame('frozen', $manifest['status']);
        $this->assertCount(5, $manifest['file_hashes']);

        foreach ($manifest['file_hashes'] as $path => $expected) {
            $this->assertSame(
                $expected,
                hash_file('sha256', resource_path('ai/screenplay/'.$path)),
                "{$path} changed after v1 was frozen",
            );
        }
    }

    public function test_the_frozen_v1_set_rebuilds_the_fingerprint_recorded_for_revision_four(): void
    {
        $manifest = $this->frozenManifest();
        $recorded = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/screenplay/foundation_v1_rev4.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $author = new ScreenplayAuthor(
            client: Mockery::mock(StructuredOutputLlmClient::class),
            promptDir: resource_path('ai/screenplay/foundation_v1'),
            schemaPath: resource_path('ai/screenplay/'.$manifest['schema']),
            promptVersion: $manifest['prompt_version'],
            model: $recorded['model'],
            maxTokens: 4000,
            contractVersion: $manifest['contract_version'],
            exampleGuidance: $manifest['example_guidance'],
        );

        $this->assertSame(
            $recorded['expected_fingerprint'],
            $author->fingerprint($recorded['inspiration'], $recorded['profile'], $recorded['requirements']),
        );
    }

    public function test_the_prompt_rules_out_building_a_design_by_reversing_the_source(): void
    {
        $contract = (string) file_get_contents(resource_path('ai/screenplay/foundation_v2/00_writer_contract.md'));

        $this->assertStringContainsString('A direct opposite is still derived from the source.', $contract);
        $this->assertStringContainsString('Do not use source features as axes to invert.', $contract);
        $this->assertStringNotContainsString('instead of', (string) file_get_contents(
            resource_path('ai/screenplay/foundation_v2/04_worked_example.md'),
        ));
    }

    public function test_the_frozen_v1_contract_stays_valid_without_dimensions(): void
    {
        $dir = resource_path('ai/screenplay/foundation_v1');
        $input = json_decode((string) file_get_contents($dir.'/04_worked_example.input.json'), true, 512, JSON_THROW_ON_ERROR);
        $example = json_decode((string) file_get_contents($dir.'/04_worked_example.json'), true, 512, JSON_THROW_ON_ERROR);
        $validator = new \App\Video\Screenplay\ScreenplayValidator;

        $this->assertArrayNotHasKey('principal_dimensions', $example);
        $this->assertSame([], $validator->profileViolations($input['profile'], 'screenplay_foundation_v1'));
        $this->assertSame([], $validator->structural($example, $input['profile'], 'screenplay_foundation_v1'));

        $schema = json_decode((string) file_get_contents(
            resource_path('ai/screenplay/schemas/screenplay_foundation_v1.json'),
        ), true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayNotHasKey('principal_dimensions', $schema['properties']);
    }

    public function test_a_v2_answer_without_dimensions_is_refused(): void
    {
        $answer = $this->foundation();
        unset($answer['principal_dimensions']);

        [$returned, $reason] = $this->serviceAnswering($answer)->authorScreenplayFoundation($this->project->id);

        $this->assertNull($returned);
        $this->assertSame('screenplay_invalid', $reason);
        $this->assertStringContainsString('principal_dimensions: must be an object', (string) $this->storedFoundation()->error_message);
    }

    public function test_the_v1_author_rebuilt_from_its_manifest_never_shares_a_fingerprint_with_v2(): void
    {
        $dir = resource_path('ai/screenplay/foundation_v1');
        $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $client = Mockery::mock(StructuredOutputLlmClient::class);

        $v1 = new ScreenplayAuthor(
            client: $client,
            promptDir: $dir,
            schemaPath: resource_path('ai/screenplay/'.$manifest['schema']),
            promptVersion: $manifest['prompt_version'],
            model: (string) config('video.screenplay.model'),
            maxTokens: 4000,
            contractVersion: $manifest['contract_version'],
            exampleGuidance: $manifest['example_guidance'],
        );
        $v2 = $this->authorUsing($client);

        $v1->assertSchemaMatchesContract();
        $this->assertSame('screenplay_foundation_v1', $v1->contractVersion());
        $this->assertSame('foundation-v2-r1', config('video.screenplay.foundation.prompt_version'));

        $inspiration = ['ideas' => [['aspect' => 'design', 'idea' => 'Open spaces connect the whole vessel.']]];
        $requirements = ['aspect_ratio' => '9:16'];
        $profile = $this->yachtFoundationProfile();

        $this->assertNotSame(
            $v1->fingerprint($inspiration, $profile, $requirements),
            $v2->fingerprint($inspiration, $profile, $requirements),
        );
    }

    public function test_a_stored_v1_foundation_still_shows_in_panel_three(): void
    {
        $admin = new Admin(['name' => 'Foundation test admin']);
        $admin->id = (string) Str::uuid();
        $admin->setRelation('role', new Role(['name' => 'admin']));
        $this->actingAs($admin);

        $v1 = json_decode((string) file_get_contents(
            resource_path('ai/screenplay/foundation_v1/04_worked_example.json'),
        ), true, 512, JSON_THROW_ON_ERROR);
        $this->stage(PlanningStageName::SCREENPLAY_FOUNDATION, $v1 + ['schema_version' => 'screenplay_foundation_v1'], [
            'finished_at' => now(),
        ]);

        $this->get(route('video-projects.anchor', $this->project->id))->assertOk()
            ->assertSee('screenplay_foundation_v1')
            ->assertSee('SYNOPSIS')
            ->assertDontSee('PRINCIPAL DIMENSIONS')
            ->assertDontSee('is not supported by this view');
    }

    public function test_no_output_field_carries_camera_or_editing_instructions(): void
    {
        $text = mb_strtolower(json_encode($this->read('04_worked_example.json'), JSON_THROW_ON_ERROR));

        foreach (['/\bshots?\b/', '/\bangle\b/', '/close-up/', '/\btracking\b/', '/\bcuts?\b/', '/\bmontage\b/', '/\btakes?\b/', '/\bcamera\b/'] as $pattern) {
            $this->assertDoesNotMatchRegularExpression($pattern, $text, $pattern);
        }
    }

    public function test_the_length_must_fall_within_the_profile_bounds(): void
    {
        foreach ([99 => false, 100 => true, 180 => true, 181 => false] as $length => $accepted) {
            $answer = $this->foundation();
            $answer['principal_dimensions']['length_m'] = $length;
            $answer['principal_dimensions']['beam_m'] = 20;
            $outside = array_filter(
                $this->violations($answer),
                static fn (string $v): bool => str_contains($v, 'principal_dimensions.length_m'),
            );

            $this->assertSame($accepted, $outside === [], "length {$length}");
        }
    }

    public function test_an_answer_outside_the_bounds_is_refused_and_its_cost_is_kept(): void
    {
        $answer = $this->foundation();
        $answer['principal_dimensions']['length_m'] = 190;

        [$returned, $reason] = $this->serviceAnswering($answer)->authorScreenplayFoundation($this->project->id);

        $this->assertNull($returned);
        $this->assertSame('screenplay_invalid', $reason);
        $this->assertStringContainsString('190 is outside 100–180', (string) $this->storedFoundation()->error_message);
        $this->assertGreaterThan(0, (float) $this->storedFoundation()->cost_usd);
    }

    public function test_broken_dimensions_are_refused(): void
    {
        $cases = [
            'beam equal to length' => [['beam_m' => 140], 'beam_m: must be less than length_m'],
            'beam of zero' => [['beam_m' => 0], 'beam_m: must be greater than zero'],
            'beam as text' => [['beam_m' => '23'], 'beam_m: must be a number'],
            'length as a fraction' => [['length_m' => 140.5], 'length_m: must be an integer'],
            'empty rationale' => [['rationale' => '  '], 'rationale: must be a nonempty string'],
            'measurement in the rationale' => [
                ['rationale' => 'The hull runs to 140 metres so the gallery can hold every station.'],
                'principal_dimensions.rationale carries a measurement',
            ],
        ];

        foreach ($cases as $label => [$change, $expected]) {
            $answer = $this->foundation();
            $answer['principal_dimensions'] = $change + $answer['principal_dimensions'];

            $this->assertStringContainsString($expected, implode('; ', $this->violations($answer)), $label);
        }

        $missing = $this->foundation();
        unset($missing['principal_dimensions']);

        $this->assertContains('principal_dimensions: must be an object', $this->violations($missing));
    }

    public function test_the_bounds_reach_the_foundation_profile_but_not_the_full_screenplay_profile(): void
    {
        $this->assertSame(['length_m' => ['min' => 100, 'max' => 180]], $this->yachtFoundationProfile()['dimension_bounds']);
        $this->assertArrayNotHasKey('dimension_bounds', $this->read('../../profiles/screenplay/yacht_v1.json'));
    }

    public function test_missing_bounds_refuse_the_foundation_before_any_claim_or_call(): void
    {
        config(['video.screenplay.foundation.dimension_bounds' => null]);

        $client = Mockery::mock(StructuredOutputLlmClient::class);
        $client->shouldNotReceive('create');

        [$returned, $reason] = $this->serviceWith($this->authorUsing($client))
            ->authorScreenplayFoundation($this->project->id);

        $this->assertNull($returned);
        $this->assertSame('screenplay_profile_invalid', $reason);
        $this->assertNull($this->storedFoundation());
    }

    public function test_an_earlier_foundation_without_dimensions_still_renders(): void
    {
        $earlier = $this->foundation();
        unset($earlier['principal_dimensions']);

        $text = \App\Video\Screenplay\ScreenplayFoundationText::render($earlier + ['schema_version' => 'screenplay_foundation_v1']);

        $this->assertStringNotContainsString('PRINCIPAL DIMENSIONS', $text);
        $this->assertStringContainsString('SYNOPSIS', $text);
    }

    public function test_the_same_brief_is_not_paid_for_twice(): void
    {
        $this->serviceAnswering($this->foundation())->authorScreenplayFoundation($this->project->id);

        $client = Mockery::mock(StructuredOutputLlmClient::class);
        $client->shouldNotReceive('create');

        [$cached, $reason] = $this->serviceWith($this->authorUsing($client))
            ->authorScreenplayFoundation($this->project->id);

        $this->assertSame('cached', $reason);
        $this->assertSame('screenplay_foundation_v2', $cached['schema_version']);
    }

    public function test_asking_for_another_version_calls_the_model_again_and_keeps_the_first(): void
    {
        $this->serviceAnswering($this->foundation())->authorScreenplayFoundation($this->project->id);
        $first = $this->storedFoundation();

        [$again, $reason] = $this->serviceAnswering($this->foundation())
            ->authorScreenplayFoundation($this->project->id, force: true);

        $this->assertSame('ok', $reason);
        $this->assertNotNull($again);

        $rows = VideoPlanningStage::query()
            ->where('project_id', $this->project->id)
            ->where('stage', PlanningStageName::SCREENPLAY_FOUNDATION->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->count();

        $this->assertSame(2, $rows, 'the earlier version stays in history');
        $this->assertGreaterThan($first->planning_revision, $this->storedFoundation()->planning_revision);
    }

    public function test_the_http_button_sends_force_only_once_a_foundation_exists(): void
    {
        $admin = new Admin(['name' => 'Foundation test admin']);
        $admin->id = (string) Str::uuid();
        $admin->setRelation('role', new Role(['name' => 'admin']));
        $this->actingAs($admin);
        $page = route('video-projects.anchor', $this->project->id);

        $this->get($page)->assertOk()
            ->assertSee('Viết nội dung kịch bản')
            ->assertDontSee('Tạo bản khác (tính phí)');

        $this->serviceAnswering($this->foundation())->authorScreenplayFoundation($this->project->id);

        $this->get($page)->assertOk()
            ->assertSee('Tạo bản khác (tính phí)')
            ->assertSee('name="force" value="1"', false);
    }

    public function test_the_start_log_reports_the_limits_of_the_client_actually_used(): void
    {
        \Illuminate\Support\Facades\Log::spy();

        $http = new \Illuminate\Http\Client\Factory;
        $http->preventStrayRequests();
        $http->fake(['https://screenplay.test/*' => $http->response([
            'model' => 'claude-sonnet-5',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode($this->foundation(), JSON_THROW_ON_ERROR)]],
            'usage' => ['input_tokens' => 5200, 'output_tokens' => 1100],
        ], 200)]);

        $client = new AnthropicStructuredOutputClient($http, 'fake-key', 'https://screenplay.test', '2023-06-01', 600, 1, 0);

        $this->serviceWith($this->authorUsing($client))->authorScreenplayFoundation($this->project->id);

        \Illuminate\Support\Facades\Log::shouldHaveReceived('info')
            ->withArgs(static fn (string $message, array $context = []): bool => $message === 'screenplay: request started'
                && ($context['contract'] ?? null) === 'screenplay_foundation_v2'
                && ($context['timeout_seconds'] ?? null) === 600
                && ($context['attempts'] ?? null) === 1)
            ->once();
    }

    public function test_a_broken_foundation_profile_is_refused_before_any_claim_or_call(): void
    {
        $directory = sys_get_temp_dir().'/foundation-profile-'.Str::uuid();
        mkdir($directory);
        $profile = json_decode(
            (string) file_get_contents(resource_path('ai/profiles/screenplay/yacht_v1.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $profile['arc_stages'] = ['design', 'design'];
        file_put_contents($directory.'/broken.json', json_encode($profile, JSON_THROW_ON_ERROR));
        config([
            'video.screenplay.profile_dir' => $directory,
            'video.screenplay.profiles.yacht' => 'broken',
        ]);

        $client = Mockery::mock(StructuredOutputLlmClient::class);
        $client->shouldNotReceive('create');

        try {
            [$returned, $reason] = $this->serviceWith($this->authorUsing($client))
                ->authorScreenplayFoundation($this->project->id);
        } finally {
            unlink($directory.'/broken.json');
            rmdir($directory);
        }

        $this->assertNull($returned);
        $this->assertSame('screenplay_profile_invalid', $reason);
        $this->assertNull($this->storedFoundation(), 'no claim may be taken for a broken profile');
    }

    public function test_an_answer_that_carries_durations_or_dialogue_is_refused(): void
    {
        foreach (['duration_estimate_ms' => 12000, 'dialogue' => [['line' => 'Hello.']]] as $key => $value) {
            $answer = $this->foundation() + [$key => $value];

            [, $reason] = $this->serviceAnswering($answer)
                ->authorScreenplayFoundation($this->project->id, force: true);

            $this->assertSame('screenplay_invalid', $reason, $key);
            $this->assertStringContainsString(
                "{$key}: this step does not produce it",
                (string) $this->storedFoundation()->error_message,
            );
        }
    }
}
