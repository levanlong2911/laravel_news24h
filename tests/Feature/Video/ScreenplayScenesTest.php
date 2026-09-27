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
use App\Video\Screenplay\ScreenplayText;
use App\Video\Screenplay\ScreenplayValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ScreenplayScenesTest extends TestCase
{
    /** @var list<string> */
    private const REQUIRED_TABLES = [
        'keywords', 'categories', 'articles', 'video_projects', 'video_planning_stages',
    ];

    /** @var list<string> */
    private const FOUNDATION_SECTIONS = [
        'logline', 'design_thesis', 'principal_dimensions', 'premise',
        'synopsis', 'stage_treatments', 'ending',
    ];

    private VideoProject $project;

    private bool $inTransaction = false;

    protected function setUp(): void
    {
        parent::setUp();

        $application = (string) DB::connection('mysql')
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
            'name' => 'TEST scenes '.uniqid(),
            'search_keyword' => 'test scenes',
            'category_id' => $category->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $article = Article::create([
            'keyword_id' => $keyword,
            'category_id' => $category->id,
            'source_url' => 'https://example.com/'.uniqid(),
            'source_url_hash' => md5(uniqid('x', true)),
            'source_title' => 'TEST scenes source',
            'title' => 'TEST scenes article '.uniqid(),
            'slug' => 'test-scenes-'.uniqid(),
            'content' => 'noi dung test',
            'status' => 'pending',
        ]);

        $this->project = VideoProject::create([
            'title' => 'TEST scenes '.uniqid(),
            'article_id' => $article->id,
        ]);

        $this->stage(PlanningStageName::INSPIRATION, [
            'source_insights' => [
                ['aspect' => 'design', 'summary' => 'A brief line the scene step must never see.'],
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

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $columns
     */
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
            'output_hash' => hash('sha256', uniqid('raw', true)),
            'finished_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function foundationOutput(?string $logline = null): array
    {
        $foundation = $this->readJson(resource_path('ai/screenplay/foundation_v2/04_worked_example.json'));
        $foundation['principal_dimensions'] = [
            'length_m' => 140,
            'beam_m' => 23.5,
            'rationale' => 'Length follows from the sequence of spaces the design strings along the hull, and beam from the widest of them.',
        ];

        if ($logline !== null) {
            $foundation['logline'] = $logline;
        }

        return $foundation + ['schema_version' => 'screenplay_foundation_v2', 'author_model' => 'claude-sonnet-5'];
    }

    private function storeFoundation(int $revision = 2, ?array $output = null): VideoPlanningStage
    {
        return $this->stage(PlanningStageName::SCREENPLAY_FOUNDATION, $output ?? $this->foundationOutput(), [
            'planning_revision' => $revision,
        ]);
    }

    /** @return array<string, mixed> */
    private function expansion(): array
    {
        $screenplay = $this->readJson(base_path('tests/Fixtures/screenplay/v3_screenplay.json'));
        $recast = [
            'cov_fin_snag' => [['sc_05', 'sc_06'], 'Between the fit-out and the signature spaces, outstanding work is closed out off camera.'],
            'cov_comp_trial' => [['sc_06', 'sc_07'], 'Between the finished spaces and the handover, the vessel goes out and comes back off camera.'],
        ];

        foreach ($screenplay['coverage'] as $index => $item) {
            if (array_key_exists($item['coverage_id'], $recast)) {
                [$sceneIds, $evidence] = $recast[$item['coverage_id']];
                $screenplay['coverage'][$index] = [
                    'coverage_id' => $item['coverage_id'],
                    'mode' => 'transition',
                    'scene_ids' => $sceneIds,
                    'evidence' => $evidence,
                ];
            }
        }

        return array_intersect_key($screenplay, array_flip(['characters', 'locations', 'scenes', 'coverage']));
    }

    private function authorUsing(StructuredOutputLlmClient $client): ScreenplayAuthor
    {
        return new ScreenplayAuthor(
            client: $client,
            promptDir: (string) config('video.screenplay.scenes.prompt_dir'),
            schemaPath: (string) config('video.screenplay.scenes.schema_path'),
            promptVersion: (string) config('video.screenplay.scenes.prompt_version'),
            model: (string) config('video.screenplay.model'),
            maxTokens: (int) config('video.screenplay.scenes.max_tokens'),
            contractVersion: (string) config('video.screenplay.scenes.contract_version'),
            sourceKey: 'foundation',
        );
    }

    /**
     * @param  array<string, mixed>  $answer
     * @param  array<string, mixed>|null  $seen
     */
    private function serviceAnswering(array $answer, ?array &$seen = null): VideoProjectService
    {
        $client = Mockery::mock(StructuredOutputLlmClient::class);
        $client->shouldReceive('create')->once()->andReturnUsing(
            static function (string $model, string $system, array $messages) use ($answer, &$seen) {
                $seen = ['system' => $system, 'user' => (string) ($messages[0]['content'] ?? '')];

                return new AnthropicStructuredOutputResponse(
                    rawText: json_encode($answer, JSON_THROW_ON_ERROR),
                    model: 'claude-sonnet-5',
                    stopReason: 'end_turn',
                    inputTokens: 9000,
                    outputTokens: 12000,
                );
            },
        );

        return $this->serviceWith($this->authorUsing($client));
    }

    private function serviceWith(ScreenplayAuthor $author): VideoProjectService
    {
        $this->app->instance('video.screenplay.scene_author', $author);
        $this->app->forgetInstance(VideoProjectService::class);

        return $this->app->make(VideoProjectService::class);
    }

    private function silentService(): VideoProjectService
    {
        $client = Mockery::mock(StructuredOutputLlmClient::class);
        $client->shouldNotReceive('create');

        return $this->serviceWith($this->authorUsing($client));
    }

    private function sceneRows(): \Illuminate\Support\Collection
    {
        return VideoPlanningStage::query()
            ->where('project_id', $this->project->id)
            ->where('stage', PlanningStageName::SCREENPLAY->value)
            ->orderBy('planning_revision')
            ->get();
    }

    private function actAsAdmin(): void
    {
        $admin = new Admin(['name' => 'Scenes test admin']);
        $admin->id = (string) Str::uuid();
        $admin->setRelation('role', new Role(['name' => 'admin']));
        $this->actingAs($admin);
    }

    public function test_without_a_selected_foundation_nothing_is_claimed_or_called(): void
    {
        $this->storeFoundation();

        foreach ([null, '', (string) Str::uuid()] as $choice) {
            $this->assertSame(
                [null, 'screenplay_foundation_not_selectable'],
                $this->silentService()->authorScreenplayScenes($this->project->id, $choice),
            );
        }

        $this->assertCount(0, $this->sceneRows());
    }

    public function test_a_frozen_v1_foundation_or_another_projects_foundation_cannot_be_selected(): void
    {
        $v1 = $this->readJson(resource_path('ai/screenplay/foundation_v1/04_worked_example.json'))
            + ['schema_version' => 'screenplay_foundation_v1'];
        $old = $this->storeFoundation(1, $v1);

        $source = Article::query()->findOrFail($this->project->article_id);
        $otherArticle = Article::create([
            'keyword_id' => $source->keyword_id,
            'category_id' => $source->category_id,
            'source_url' => 'https://example.com/'.uniqid(),
            'source_url_hash' => md5(uniqid('y', true)),
            'source_title' => 'TEST other source',
            'title' => 'TEST other article '.uniqid(),
            'slug' => 'test-other-'.uniqid(),
            'content' => 'noi dung test',
            'status' => 'pending',
        ]);
        $other = VideoProject::create(['title' => 'TEST other '.uniqid(), 'article_id' => $otherArticle->id]);
        $foreign = VideoPlanningStage::create([
            'project_id' => $other->id,
            'planning_revision' => 1,
            'stage' => PlanningStageName::SCREENPLAY_FOUNDATION->value,
            'status' => VideoPlanningStageStatus::SUCCEEDED->value,
            'input_json' => [],
            'input_hash' => hash('sha256', uniqid()),
            'output_json' => $this->foundationOutput(),
        ]);

        foreach ([$old->id, $foreign->id] as $choice) {
            $this->assertSame(
                [null, 'screenplay_foundation_not_selectable'],
                $this->silentService()->authorScreenplayScenes($this->project->id, $choice),
            );
        }

        $this->assertCount(0, $this->sceneRows());
    }

    public function test_the_model_sees_only_the_selected_foundation_and_the_result_is_assembled_as_v4(): void
    {
        $foundationStage = $this->storeFoundation();

        [$stored, $reason] = $this->serviceAnswering($this->expansion(), $seen)
            ->authorScreenplayScenes($this->project->id, $foundationStage->id);

        $this->assertSame('ok', $reason);

        $message = json_decode($seen['user'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['foundation', 'profile', 'film_requirements'], array_keys($message));
        $this->assertSame(self::FOUNDATION_SECTIONS, array_keys($message['foundation']));
        $this->assertStringNotContainsString('A brief line the scene step must never see.', $seen['user']);
        $this->assertStringContainsString('THE FOUNDATION IS FIXED', $seen['system']);

        foreach (self::FOUNDATION_SECTIONS as $section) {
            $this->assertSame($foundationStage->output_json[$section], $stored[$section], $section);
        }

        $this->assertSame('screenplay_v4', $stored['schema_version']);
        $this->assertSame($foundationStage->id, $stored['source_foundation']['stage_id']);
        $this->assertSame($this->expansion()['scenes'], $stored['scenes']);

        $row = $this->sceneRows()->last();
        $this->assertSame(VideoPlanningStageStatus::SUCCEEDED->value, $row->status);
        $this->assertSame('screenplay_scene_expansion_v1', $row->input_json['contract_version']);
        $this->assertSame('screenplay_v4', $row->input_json['target_schema_version']);
        $this->assertSame($foundationStage->id, $row->input_json['_meta']['foundation_stage_id']);
        $this->assertSame($foundationStage->output_hash, $row->input_json['_meta']['foundation_output_hash']);
        $this->assertSame(64, strlen($row->input_json['foundation_content_hash']));
        $this->assertNotSame($foundationStage->output_hash, $row->input_json['foundation_content_hash']);
    }

    public function test_the_page_offers_the_button_only_for_a_current_foundation_and_shows_v4(): void
    {
        $this->actAsAdmin();
        $page = route('video-projects.anchor', $this->project->id);

        $this->get($page)->assertOk()
            ->assertSee('Tạo phân cảnh')
            ->assertSee('Cần nội dung kịch bản bản mới', false)
            ->assertDontSee('name="foundation_stage_id"', false);

        $foundationStage = $this->storeFoundation();

        $this->get($page)->assertOk()
            ->assertSee('name="foundation_stage_id" value="'.$foundationStage->id.'"', false)
            ->assertSee('chỉ tạo nhân vật, địa điểm, scene, coverage và build state')
            ->assertDontSee('Tạo lại phân cảnh');

        $this->serviceAnswering($this->expansion());
        $this->from($page)->post(route('video-projects.screenplay', $this->project->id), [
            'foundation_stage_id' => $foundationStage->id,
        ])->assertRedirect($page)->assertSessionHas('success');

        $response = $this->get($page)->assertOk()
            ->assertSee('screenplay_v4')
            ->assertSee('từ nội dung rev 2')
            ->assertSee('CHARACTERS')
            ->assertSee('COVERAGE')
            ->assertSee('Tạo lại phân cảnh')
            ->assertDontSee('không phải bản nội dung đang hiển thị');

        $html = (string) $response->getContent();

        foreach (['An operator wants less of each crossing spent at the stops',
            'A harbour ferry is designed with two identical working ends'] as $foundationLine) {
            $this->assertSame(1, substr_count($html, $foundationLine), 'the scene panel repeats: '.$foundationLine);
        }
    }

    public function test_the_scene_panel_text_carries_the_scenes_and_leaves_the_foundation_to_the_panel_above(): void
    {
        $dir = resource_path('ai/screenplay/scene_expansion_v1/');
        $input = $this->readJson($dir.'04_worked_example.input.json');
        $v4 = $input['foundation'] + $this->readJson($dir.'04_worked_example.json') + ['schema_version' => 'screenplay_v4'];

        $text = ScreenplayText::renderScenes($v4);

        foreach (['CHARACTERS', 'LOCATIONS', 'SC_01', 'SC_08', 'Build state:', 'COVERAGE'] as $expected) {
            $this->assertStringContainsString($expected, $text, $expected);
        }

        foreach (['DESIGN THESIS', 'PRINCIPAL DIMENSIONS', 'PREMISE', 'SYNOPSIS', 'ENDING', $v4['logline']] as $foundation) {
            $this->assertStringNotContainsString($foundation, $text, $foundation);
        }

        $this->assertStringContainsString('DESIGN THESIS', ScreenplayText::render($v4));
    }

    public function test_the_page_surfaces_approval_for_the_exact_scene_stage(): void
    {
        $this->actAsAdmin();
        $foundation = $this->storeFoundation();
        $service = $this->serviceAnswering($this->expansion());
        $service->authorScreenplayScenes($this->project->id, $foundation->id);
        $stage = $this->sceneRows()->last();
        $page = route('video-projects.anchor', $this->project->id);

        $this->get($page)->assertOk()
            ->assertSee('Duyệt phân cảnh')
            ->assertSee('name="stage_id" value="'.$stage->id.'"', false);

        [$done, $reason] = $service->approveScreenplay(
            $this->project->id, $stage->id, null, (string) Str::uuid(),
        );

        $this->assertTrue($done);
        $this->assertSame('approved', $reason);
        $this->get($page)->assertOk()
            ->assertSee('Đã duyệt')
            ->assertDontSee('Duyệt phân cảnh')
            ->assertSee('Chọn cho production')
            ->assertSee('name="expected_selection_version" value="0"', false);

        [$selected, $selectionReason] = $service->selectScreenplayForProduction(
            $this->project->id, $stage->id, 0,
        );

        $this->assertTrue($selected);
        $this->assertSame('selected', $selectionReason);
        $this->get($page)->assertOk()
            ->assertSee('Đang dùng cho production')
            ->assertDontSee('Chọn cho production');
    }

    public function test_an_expansion_that_returns_foundation_fields_is_refused_with_its_cost_kept(): void
    {
        $foundationStage = $this->storeFoundation();
        $answer = $this->expansion() + ['logline' => 'A rewritten logline.', 'premise' => []];

        [$returned, $reason] = $this->serviceAnswering($answer)
            ->authorScreenplayScenes($this->project->id, $foundationStage->id);

        $this->assertNull($returned);
        $this->assertSame('screenplay_invalid', $reason);

        $row = $this->sceneRows()->last();
        $this->assertSame(VideoPlanningStageStatus::FAILED->value, $row->status);
        $this->assertStringContainsString('logline: this step does not produce it', (string) $row->error_message);
        $this->assertSame(json_encode($answer, JSON_THROW_ON_ERROR), $row->raw_response);
        $this->assertGreaterThan(0, (float) $row->cost_usd);
    }

    public function test_scene_rules_still_apply_to_the_expansion(): void
    {
        $foundationStage = $this->storeFoundation();
        $cases = [
            'build state on an unknown subject' => [
                static function (array $answer): array {
                    $answer['scenes'][1]['build_state'] = ['subject_id' => 'ch_nobody', 'state' => 'Frames up.'];

                    return $answer;
                },
                'build_state.subject_id: must name a declared character whose kind is object',
            ],
            'a missing coverage item' => [
                static function (array $answer): array {
                    array_pop($answer['coverage']);

                    return $answer;
                },
                'is declared by the profile but absent from the screenplay',
            ],
            'a measurement in an action' => [
                static function (array $answer): array {
                    $answer['scenes'][0]['action'] .= ' The hull runs to 140 metres.';

                    return $answer;
                },
                'carries a measurement',
            ],
            'an excluded name' => [
                static function (array $answer): array {
                    $answer['scenes'][0]['action'] .= ' Vale Engineering signs the drawing.';

                    return $answer;
                },
                'carries the excluded name Vale Engineering',
            ],
        ];

        foreach ($cases as $label => [$change, $expected]) {
            [, $reason] = $this->serviceAnswering($change($this->expansion()))
                ->authorScreenplayScenes($this->project->id, $foundationStage->id, force: true);

            $this->assertSame('screenplay_invalid', $reason, $label);
            $this->assertStringContainsString($expected, (string) $this->sceneRows()->last()->error_message, $label);
        }
    }

    public function test_the_same_foundation_is_not_paid_for_twice_and_force_asks_again(): void
    {
        $foundationStage = $this->storeFoundation();

        $this->serviceAnswering($this->expansion())->authorScreenplayScenes($this->project->id, $foundationStage->id);

        [$cached, $reason] = $this->silentService()->authorScreenplayScenes($this->project->id, $foundationStage->id);
        $this->assertSame('cached', $reason);
        $this->assertSame('screenplay_v4', $cached['schema_version']);

        [, $again] = $this->serviceAnswering($this->expansion())
            ->authorScreenplayScenes($this->project->id, $foundationStage->id, force: true);
        $this->assertSame('ok', $again);
        $this->assertCount(2, $this->sceneRows()->where('status', VideoPlanningStageStatus::SUCCEEDED->value));
    }

    public function test_a_new_foundation_changes_the_fingerprint_and_calls_the_model(): void
    {
        $first = $this->storeFoundation(2);
        $this->serviceAnswering($this->expansion())->authorScreenplayScenes($this->project->id, $first->id);

        $second = $this->storeFoundation(3, $this->foundationOutput('A different logline for a different foundation.'));
        [, $reason] = $this->serviceAnswering($this->expansion())
            ->authorScreenplayScenes($this->project->id, $second->id);

        $this->assertSame('ok', $reason);

        $rows = $this->sceneRows();
        $this->assertCount(2, $rows);
        $this->assertNotSame($rows[0]->input_json['fingerprint'], $rows[1]->input_json['fingerprint']);
        $this->assertNotSame($rows[0]->input_json['foundation_content_hash'], $rows[1]->input_json['foundation_content_hash']);
    }

    public function test_the_page_warns_when_the_scenes_belong_to_an_older_foundation(): void
    {
        $this->actAsAdmin();
        $first = $this->storeFoundation(2);
        $this->serviceAnswering($this->expansion())->authorScreenplayScenes($this->project->id, $first->id);
        $this->storeFoundation(3, $this->foundationOutput('A newer foundation.'));

        $this->get(route('video-projects.anchor', $this->project->id))->assertOk()
            ->assertSee('Phân cảnh này tạo từ nội dung rev 2, không phải bản nội dung đang hiển thị phía trên.');
    }

    public function test_a_timeout_sends_one_request_and_shows_in_the_scene_panel(): void
    {
        $foundationStage = $this->storeFoundation();
        $sent = 0;
        $http = new \Illuminate\Http\Client\Factory;
        $http->preventStrayRequests();
        $http->fake(['https://screenplay.test/*' => static function () use (&$sent) {
            $sent++;

            throw new \Illuminate\Http\Client\ConnectionException(
                'cURL error 28: Operation timed out after 900000 milliseconds with 0 bytes received',
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
            (int) config('video.screenplay.scenes.timeout_seconds'),
            (int) config('video.screenplay.scenes.retry_times'),
            0,
        );

        [$returned, $reason] = $this->serviceWith($this->authorUsing($client))
            ->authorScreenplayScenes($this->project->id, $foundationStage->id);

        $this->assertNull($returned);
        $this->assertSame('screenplay_timeout', $reason);
        $this->assertSame(1, $sent);

        $panel = $this->app->make(VideoProjectService::class)->latestScreenplayScenes($this->project->id);
        $this->assertStringContainsString('cURL error 28', (string) $panel['error']);
        $this->assertNull($panel['screenplay']);
        $this->assertNull($panel['foundation_stage_id'], 'lineage describes the shown screenplay, never a failed attempt');
    }

    public function test_the_scene_step_never_builds_the_foundation_or_the_v3_author(): void
    {
        $foundationStage = $this->storeFoundation();

        foreach (['video.screenplay.foundation_author', ScreenplayAuthor::class] as $key) {
            $this->app->forgetInstance($key);
            $this->app->bind($key, static function () use ($key) {
                throw new \LogicException("{$key} must not be built for scenes");
            });
        }

        [, $reason] = $this->serviceAnswering($this->expansion())
            ->authorScreenplayScenes($this->project->id, $foundationStage->id);

        $this->assertSame('ok', $reason);
    }

    public function test_the_production_scene_author_uses_its_own_limits_and_the_foundation_key(): void
    {
        $this->app->forgetInstance('video.screenplay.scene_author');
        $author = $this->app->make('video.screenplay.scene_author');

        $read = static function (object $object, string $name): mixed {
            $property = (new \ReflectionClass($object))->getProperty($name);
            $property->setAccessible(true);

            return $property->getValue($object);
        };

        $this->assertSame('screenplay_scene_expansion_v1', $author->contractVersion());
        $this->assertSame('foundation', $read($author, 'sourceKey'));
        $this->assertSame(32000, $read($author, 'maxTokens'));
        $this->assertSame(900, $read($author, 'client')->timeoutSeconds());
        $this->assertSame(1, $read($author, 'client')->attempts());
    }

    public function test_the_worked_example_is_built_on_the_foundation_example_and_passes_as_v4(): void
    {
        $dir = resource_path('ai/screenplay/scene_expansion_v1/');
        $input = $this->readJson($dir.'04_worked_example.input.json');
        $output = $this->readJson($dir.'04_worked_example.json');
        $foundation = $this->readJson(resource_path('ai/screenplay/foundation_v2/04_worked_example.json'));
        $validator = new ScreenplayValidator;

        foreach (self::FOUNDATION_SECTIONS as $section) {
            $this->assertSame($foundation[$section], $input['foundation'][$section], $section);
            $this->assertArrayNotHasKey($section, $output, $section);
        }

        $this->assertSame([], $validator->profileViolations($input['profile'], 'screenplay_scene_expansion_v1'));
        $this->assertSame([], $validator->structural($output, $input['profile'], 'screenplay_scene_expansion_v1'));

        $assembledProfile = ['contract_version' => 'screenplay_v4',
            'dimension_bounds' => ['length_m' => ['min' => 30, 'max' => 60]]] + $input['profile'];

        $this->assertSame(
            [],
            $validator->structural($input['foundation'] + $output, $assembledProfile, 'screenplay_v4'),
        );
    }

    public function test_the_prompt_expands_a_selected_foundation_and_keeps_scale_open(): void
    {
        $rules = (new \ReflectionClass(ScreenplayAuthor::class))->getMethod('rules');
        $rules->setAccessible(true);
        $prompt = (string) $rules->invoke($this->authorUsing(Mockery::mock(StructuredOutputLlmClient::class)));

        $this->assertStringContainsString('THE FOUNDATION IS FIXED', $prompt);
        $this->assertStringContainsString('There is no source article in this step.', $prompt);
        $this->assertStringContainsString('LARGE SUBJECT — SCENE INVENTORY ONLY', $prompt);
        $this->assertStringContainsString('It is a scale for comparison, not a count to aim for', $prompt);
        $this->assertStringNotContainsString('approved', mb_strtolower($prompt));

        foreach (['THE CENTRAL ASSIGNMENT', 'DESIGN THESIS', 'THE PREMISE', 'ORDER OF WORK'] as $heading) {
            $this->assertDoesNotMatchRegularExpression('/^'.preg_quote($heading, '/').'/m', $prompt, $heading);
        }
    }

    public function test_the_example_output_is_free_of_measurements_camera_terms_and_contrast(): void
    {
        $text = mb_strtolower(json_encode(
            $this->readJson(resource_path('ai/screenplay/scene_expansion_v1/04_worked_example.json')),
            JSON_THROW_ON_ERROR,
        ));

        foreach (['/\bshots?\b/', '/\bangle\b/', '/close-up/', '/\btracking\b/', '/\bcuts?\b/',
            '/\bmontage\b/', '/\btakes?\b/', '/\bcamera\b/', '/instead of/', '/rather than/'] as $pattern) {
            $this->assertDoesNotMatchRegularExpression($pattern, $text, $pattern);
        }
    }

    public function test_a_v4_screenplay_renders_its_foundation_and_its_scenes(): void
    {
        $dir = resource_path('ai/screenplay/scene_expansion_v1/');
        $input = $this->readJson($dir.'04_worked_example.input.json');
        $text = ScreenplayText::render(
            $input['foundation'] + $this->readJson($dir.'04_worked_example.json') + ['schema_version' => 'screenplay_v4'],
        );

        foreach (['DESIGN THESIS', 'PRINCIPAL DIMENSIONS', '48 m × 14.5 m', 'SYNOPSIS', 'ENDING',
            'CHARACTERS', 'SC_08', 'Build state:', 'COVERAGE'] as $expected) {
            $this->assertStringContainsString($expected, $text, $expected);
        }

        $this->assertStringNotContainsString('No scenes yet.', $text);
        $this->assertStringNotContainsString('not supported by this view', $text);
    }

    public function test_the_stage_chain_runs_through_the_foundation(): void
    {
        $this->assertSame(PlanningStageName::SCREENPLAY_FOUNDATION, PlanningStageName::ANCHOR_PROMPT->next());
        $this->assertSame(PlanningStageName::SCREENPLAY, PlanningStageName::SCREENPLAY_FOUNDATION->next());
        $this->assertSame(PlanningStageName::SCENE_PLAN, PlanningStageName::SCREENPLAY->next());

        foreach (PlanningStageName::cases() as $case) {
            $case->next();
        }
    }

    public function test_a_streamed_expansion_from_the_real_client_is_assembled_and_stored_as_v4(): void
    {
        $foundationStage = $this->storeFoundation();
        $text = json_encode($this->expansion(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $half = intdiv(strlen($text), 2);
        $events = [
            ['type' => 'message_start', 'message' => ['id' => 'msg_s', 'model' => 'claude-sonnet-5',
                'usage' => ['input_tokens' => 24680, 'output_tokens' => 1]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => substr($text, 0, $half)]],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => substr($text, $half)]],
            ['type' => 'content_block_stop', 'index' => 1],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 21000]],
            ['type' => 'message_stop'],
        ];
        $body = implode('', array_map(
            static fn (array $e): string => 'event: '.$e['type']."\n".'data: '.json_encode($e)."\n\n",
            $events,
        ));

        $http = new \Illuminate\Http\Client\Factory;
        $http->preventStrayRequests();
        $http->fake(['https://screenplay.test/*' => $http->response($body, 200, ['content-type' => 'text/event-stream'])]);

        $client = new AnthropicStructuredOutputClient(
            $http, 'fake-key', 'https://screenplay.test', '2023-06-01',
            (int) config('video.screenplay.scenes.timeout_seconds'),
            (int) config('video.screenplay.scenes.retry_times'),
            0,
            (bool) config('video.screenplay.scenes.stream'),
            config('video.screenplay.scenes.effort'),
        );

        [$stored, $reason] = $this->serviceWith($this->authorUsing($client))
            ->authorScreenplayScenes($this->project->id, $foundationStage->id);

        $this->assertSame('ok', $reason);
        $this->assertSame('screenplay_v4', $stored['schema_version']);
        $this->assertSame($this->expansion()['scenes'], $stored['scenes']);

        foreach (self::FOUNDATION_SECTIONS as $section) {
            $this->assertSame($foundationStage->output_json[$section], $stored[$section], $section);
        }

        $row = $this->sceneRows()->last();
        $this->assertSame(24680, $row->tokens_in);
        $this->assertSame(21000, $row->tokens_out);
        $this->assertGreaterThan(0, (float) $row->cost_usd);

        $http->assertSent(static fn (\Illuminate\Http\Client\Request $request): bool => $request['stream'] === true
            && $request['output_config']['effort'] === 'medium'
            && $request['max_tokens'] === 32000);
    }
}
