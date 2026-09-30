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
use App\Services\Video\ScreenplayExpansionService;
use App\Services\VideoProjectService;
use App\Video\Concept\Claude\AnthropicStructuredOutputClient;
use App\Video\Concept\Claude\AnthropicStructuredOutputResponse;
use App\Video\Concept\Contracts\StructuredOutputLlmClient;
use App\Video\Screenplay\ScreenplayAuthor;
use App\Video\Screenplay\ScreenplayText;
use App\Video\Screenplay\ScreenplayValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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

    /** @var array<string, string> */
    private const AUTHOR_KEYS = [
        'characters' => 'video.screenplay.character_author',
        'locations' => 'video.screenplay.location_author',
        'scenes' => 'video.screenplay.scene_author',
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

    private function foundationHash(VideoPlanningStage $foundation): string
    {
        $content = [];

        foreach (self::FOUNDATION_SECTIONS as $section) {
            $content[$section] = $foundation->output_json[$section] ?? null;
        }

        return hash('sha256', json_encode(
            $content,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    /**
     * @param  list<array<string, mixed>>|null  $rows
     */
    private function storeCastPart(VideoPlanningStage $foundation, string $part, ?array $rows = null): VideoPlanningStage
    {
        $stage = $part === 'characters' ? PlanningStageName::SCREENPLAY_CHARACTERS : PlanningStageName::SCREENPLAY_LOCATIONS;
        $contract = $part === 'characters' ? 'screenplay_characters_v1' : 'screenplay_locations_v1';
        $revision = 1 + (int) $this->rowsOf($stage)->max('planning_revision');

        return $this->stage($stage, [
            $part => $rows ?? $this->expansion()[$part],
            'schema_version' => $contract,
        ], [
            'planning_revision' => $revision,
            'input_json' => [
                'contract_version' => $contract,
                'foundation_content_hash' => $this->foundationHash($foundation),
                'fingerprint' => uniqid('f', true),
                '_meta' => [
                    'foundation_stage_id' => $foundation->id,
                    'foundation_revision' => $foundation->planning_revision,
                ],
            ],
        ]);
    }

    /** @return array{0: VideoPlanningStage, 1: VideoPlanningStage} */
    private function storeCast(VideoPlanningStage $foundation): array
    {
        return [
            $this->storeCastPart($foundation, 'characters'),
            $this->storeCastPart($foundation, 'locations'),
        ];
    }

    /** @return array<string, mixed> */
    private function expansion(bool $spoken = false): array
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

        foreach ($screenplay['scenes'] as $index => $scene) {
            $subject = $scene['build_state']['subject_id'] ?? null;

            if (is_string($subject) && ! in_array($subject, $scene['character_ids'], true)) {
                $screenplay['scenes'][$index]['character_ids'][] = $subject;
            }

            if (! $spoken) {
                $screenplay['scenes'][$index]['dialogue'] = [];
            }
        }

        return array_intersect_key($screenplay, array_flip(['characters', 'locations', 'scenes', 'coverage']));
    }

    /** @return array<string, mixed> */
    private function sceneAnswer(bool $spoken = false): array
    {
        return array_intersect_key($this->expansion($spoken), array_flip(['scenes', 'coverage']));
    }

    /** @param  callable(array<string, mixed>): array<string, mixed>  $change */
    private function useProfile(callable $change): void
    {
        $dir = storage_path('framework/testing/screenplay-profiles-'.Str::random(8));
        File::ensureDirectoryExists($dir);
        File::put($dir.'/yacht_v1.json', json_encode(
            $change($this->readJson(resource_path('ai/profiles/screenplay/yacht_v1.json'))),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
        ));
        config(['video.screenplay.profile_dir' => $dir]);
        $this->beforeApplicationDestroyed(static fn () => File::deleteDirectory($dir));
    }

    private function allowDialogue(): void
    {
        $this->useProfile(static function (array $profile): array {
            $profile['people_policy']['dialogue_allowed'] = true;

            return $profile;
        });
    }

    private function authorUsing(StructuredOutputLlmClient $client, string $step = 'scenes'): ScreenplayAuthor
    {
        return new ScreenplayAuthor(
            client: $client,
            promptDir: (string) config("video.screenplay.{$step}.prompt_dir"),
            schemaPath: (string) config("video.screenplay.{$step}.schema_path"),
            promptVersion: (string) config("video.screenplay.{$step}.prompt_version"),
            model: (string) config('video.screenplay.model'),
            maxTokens: (int) config("video.screenplay.{$step}.max_tokens"),
            contractVersion: (string) config("video.screenplay.{$step}.contract_version"),
            sourceKey: 'foundation',
        );
    }

    /**
     * @param  array<string, mixed>  $answer
     * @param  array<string, mixed>|null  $seen
     */
    private function answering(
        array $answer,
        ?array &$seen = null,
        string $stopReason = 'end_turn',
        ?callable $during = null,
    ): StructuredOutputLlmClient {
        $client = Mockery::mock(StructuredOutputLlmClient::class);
        $client->shouldReceive('create')->once()->andReturnUsing(
            static function (string $model, string $system, array $messages, array $outputSchema = []) use ($answer, &$seen, $stopReason, $during) {
                $seen = ['system' => $system, 'user' => (string) ($messages[0]['content'] ?? ''), 'schema' => $outputSchema];

                if ($during !== null) {
                    $during();
                }

                return new AnthropicStructuredOutputResponse(
                    rawText: json_encode($answer, JSON_THROW_ON_ERROR),
                    model: 'claude-sonnet-5',
                    stopReason: $stopReason,
                    inputTokens: 9000,
                    outputTokens: 12000,
                );
            },
        );

        return $client;
    }

    /**
     * @param  array<string, mixed>  $answer
     * @param  array<string, mixed>|null  $seen
     */
    private function serviceAnswering(array $answer, ?array &$seen = null, string $step = 'scenes'): ScreenplayExpansionService
    {
        return $this->serviceWith($this->authorUsing($this->answering($answer, $seen), $step), $step);
    }

    private function serviceWith(ScreenplayAuthor $author, string $step = 'scenes'): ScreenplayExpansionService
    {
        $this->app->instance(self::AUTHOR_KEYS[$step], $author);
        $this->app->forgetInstance(ScreenplayExpansionService::class);
        $this->app->forgetInstance(VideoProjectService::class);

        return $this->app->make(ScreenplayExpansionService::class);
    }

    private function silentService(string $step = 'scenes'): ScreenplayExpansionService
    {
        $client = Mockery::mock(StructuredOutputLlmClient::class);
        $client->shouldNotReceive('create');

        return $this->serviceWith($this->authorUsing($client, $step), $step);
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: string}
     */
    private function scenes(ScreenplayExpansionService $service, VideoPlanningStage $foundation, bool $force = false): array
    {
        [$characters, $locations] = $this->castFor($foundation);

        return $service->authorScenes($this->project->id, $foundation->id, $characters->id, $locations->id, $force);
    }

    /** @return array{0: VideoPlanningStage, 1: VideoPlanningStage} */
    private function castFor(VideoPlanningStage $foundation): array
    {
        $hash = $this->foundationHash($foundation);
        $rows = static fn (PlanningStageName $name) => VideoPlanningStage::query()
            ->where('stage', $name->value)
            ->where('input_json->foundation_content_hash', $hash)
            ->orderByDesc('planning_revision')
            ->first();

        $characters = $rows(PlanningStageName::SCREENPLAY_CHARACTERS);
        $locations = $rows(PlanningStageName::SCREENPLAY_LOCATIONS);

        return $characters !== null && $locations !== null ? [$characters, $locations] : $this->storeCast($foundation);
    }

    private function rowsOf(PlanningStageName $stage): \Illuminate\Support\Collection
    {
        return VideoPlanningStage::query()
            ->where('project_id', $this->project->id)
            ->where('stage', $stage->value)
            ->orderBy('planning_revision')
            ->get();
    }

    private function sceneRows(): \Illuminate\Support\Collection
    {
        return $this->rowsOf(PlanningStageName::SCREENPLAY);
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
        $foundation = $this->storeFoundation();
        [$characters, $locations] = $this->storeCast($foundation);

        foreach ([null, '', (string) Str::uuid()] as $choice) {
            $this->assertSame(
                [null, 'screenplay_foundation_not_selectable'],
                $this->silentService()->authorScenes($this->project->id, $choice, $characters->id, $locations->id),
            );

            foreach (['characters', 'locations'] as $part) {
                $service = $this->silentService($part);
                $this->assertSame(
                    [null, 'screenplay_foundation_not_selectable'],
                    $part === 'characters'
                        ? $service->authorCharacters($this->project->id, $choice)
                        : $service->authorLocations($this->project->id, $choice),
                );
            }
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
                $this->silentService()->authorScenes($this->project->id, $choice, null, null),
            );
            $this->assertSame(
                [null, 'screenplay_foundation_not_selectable'],
                $this->silentService('characters')->authorCharacters($this->project->id, $choice),
            );
        }

        $this->assertCount(0, $this->sceneRows());
        $this->assertCount(0, $this->rowsOf(PlanningStageName::SCREENPLAY_CHARACTERS));
    }

    public function test_the_characters_step_sees_only_the_foundation_and_stores_only_characters(): void
    {
        $foundation = $this->storeFoundation();
        $answer = ['characters' => $this->expansion()['characters']];

        [$stored, $reason] = $this->serviceAnswering($answer, $seen, 'characters')
            ->authorCharacters($this->project->id, $foundation->id);

        $this->assertSame('ok', $reason);

        $message = json_decode($seen['user'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['foundation', 'profile', 'film_requirements'], array_keys($message));
        $this->assertSame(self::FOUNDATION_SECTIONS, array_keys($message['foundation']));
        $this->assertStringNotContainsString('A brief line the scene step must never see.', $seen['user']);
        $this->assertStringContainsString('THE SCENES CANNOT ADD PARTICIPANTS', $seen['system']);

        $this->assertSame($answer['characters'], $stored['characters']);
        $this->assertSame('screenplay_characters_v1', $stored['schema_version']);
        $this->assertSame($foundation->id, $stored['source_foundation']['stage_id']);
        $this->assertArrayNotHasKey('scenes', $stored);

        $row = $this->rowsOf(PlanningStageName::SCREENPLAY_CHARACTERS)->last();
        $this->assertSame(VideoPlanningStageStatus::SUCCEEDED->value, $row->status);
        $this->assertSame($this->foundationHash($foundation), $row->input_json['foundation_content_hash']);
        $this->assertSame($foundation->id, $row->input_json['_meta']['foundation_stage_id']);
        $this->assertNull($row->thinking_tokens, 'Claude reports no separate thinking figure, so none is invented');

        [, $again] = $this->silentService('characters')->authorCharacters($this->project->id, $foundation->id);
        $this->assertSame('cached', $again);
    }

    public function test_the_locations_step_stores_only_locations_and_ignores_the_characters(): void
    {
        $foundation = $this->storeFoundation();
        $answer = ['locations' => $this->expansion()['locations']];

        [$stored, $reason] = $this->serviceAnswering($answer, $seen, 'locations')
            ->authorLocations($this->project->id, $foundation->id);

        $this->assertSame('ok', $reason);
        $this->assertSame(['foundation', 'profile', 'film_requirements'], array_keys(json_decode($seen['user'], true)));
        $this->assertStringContainsString('THE SCENES CANNOT ADD LOCATIONS', $seen['system']);
        $this->assertSame($answer['locations'], $stored['locations']);
        $this->assertSame('screenplay_locations_v1', $stored['schema_version']);

        $this->storeCastPart($foundation, 'characters');

        [, $cached] = $this->silentService('locations')->authorLocations($this->project->id, $foundation->id);
        $this->assertSame('cached', $cached, 'a new character list does not make the locations stale');
    }

    public function test_a_cast_step_is_checked_on_its_own_before_any_scene_is_paid_for(): void
    {
        $foundation = $this->storeFoundation();
        $characters = $this->expansion()['characters'];
        $characters[1]['role'] = 'protagonist';

        [, $reason] = $this->serviceAnswering(['characters' => $characters], $seen, 'characters')
            ->authorCharacters($this->project->id, $foundation->id);

        $this->assertSame('screenplay_invalid', $reason);
        $row = $this->rowsOf(PlanningStageName::SCREENPLAY_CHARACTERS)->last();
        $this->assertStringContainsString('exactly one protagonist', (string) $row->error_message);
        $this->assertGreaterThan(0, (float) $row->cost_usd);

        $locations = $this->expansion()['locations'];
        $locations[0]['description'] .= ' Vale Engineering built it.';

        [, $named] = $this->serviceAnswering(['locations' => $locations, 'scenes' => []], $seen, 'locations')
            ->authorLocations($this->project->id, $foundation->id);

        $this->assertSame('screenplay_invalid', $named);
        $error = (string) $this->rowsOf(PlanningStageName::SCREENPLAY_LOCATIONS)->last()->error_message;
        $this->assertStringContainsString('scenes: this step does not produce it', $error);
        $this->assertStringContainsString('carries the excluded name Vale Engineering', $error);
    }

    public function test_the_scenes_see_the_foundation_and_the_supplied_cast_and_are_assembled_as_v4(): void
    {
        $foundationStage = $this->storeFoundation();
        [$characters, $locations] = $this->storeCast($foundationStage);

        [$stored, $reason] = $this->serviceAnswering($this->sceneAnswer(), $seen)
            ->authorScenes($this->project->id, $foundationStage->id, $characters->id, $locations->id);

        $this->assertSame('ok', $reason);

        $message = json_decode($seen['user'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['foundation', 'characters', 'locations', 'profile', 'film_requirements'], array_keys($message));
        $this->assertSame(self::FOUNDATION_SECTIONS, array_keys($message['foundation']));
        $this->assertSame($characters->output_json['characters'], $message['characters']);
        $this->assertStringNotContainsString('A brief line the scene step must never see.', $seen['user']);
        $this->assertStringContainsString('THE FOUNDATION IS FIXED', $seen['system']);

        foreach (self::FOUNDATION_SECTIONS as $section) {
            $this->assertSame($foundationStage->output_json[$section], $stored[$section], $section);
        }

        $this->assertSame('screenplay_v4', $stored['schema_version']);
        $this->assertSame($foundationStage->id, $stored['source_foundation']['stage_id']);
        $this->assertSame($characters->id, $stored['source_characters']['stage_id']);
        $this->assertSame($locations->id, $stored['source_locations']['stage_id']);
        $this->assertSame($characters->output_json['characters'], $stored['characters']);
        $this->assertSame($locations->output_json['locations'], $stored['locations']);
        $this->assertSame($this->expansion()['scenes'], $stored['scenes']);

        $row = $this->sceneRows()->last();
        $this->assertSame(VideoPlanningStageStatus::SUCCEEDED->value, $row->status);
        $this->assertSame('screenplay_scene_expansion_v2', $row->input_json['contract_version']);
        $this->assertSame('screenplay_v4', $row->input_json['target_schema_version']);
        $this->assertSame($foundationStage->id, $row->input_json['_meta']['foundation_stage_id']);
        $this->assertSame($characters->id, $row->input_json['_meta']['characters_stage_id']);
        $this->assertSame($locations->id, $row->input_json['_meta']['locations_stage_id']);
        $this->assertSame($foundationStage->output_hash, $row->input_json['_meta']['foundation_output_hash']);
        $this->assertSame(64, strlen($row->input_json['foundation_content_hash']));
        $this->assertSame(64, strlen($row->input_json['characters_content_hash']));
        $this->assertSame(64, strlen($row->input_json['locations_content_hash']));
    }

    public function test_the_scenes_refuse_a_cast_from_another_foundation_without_calling_the_model(): void
    {
        $first = $this->storeFoundation(2);
        [$characters, $locations] = $this->storeCast($first);
        $second = $this->storeFoundation(3, $this->foundationOutput('A different logline for a different foundation.'));
        [, $currentLocations] = $this->storeCast($second);

        $this->assertSame(
            [null, 'screenplay_characters_not_selectable'],
            $this->silentService()->authorScenes($this->project->id, $second->id, $characters->id, $currentLocations->id),
        );
        $this->assertSame(
            [null, 'screenplay_locations_not_selectable'],
            $this->silentService()->authorScenes($this->project->id, $second->id, $this->castFor($second)[0]->id, $locations->id),
        );
        $this->assertCount(0, $this->sceneRows());
    }

    public function test_the_scenes_may_not_return_their_own_cast(): void
    {
        $foundationStage = $this->storeFoundation();
        $answer = $this->sceneAnswer() + ['characters' => $this->expansion()['characters']];

        [$returned, $reason] = $this->scenes($this->serviceAnswering($answer), $foundationStage);

        $this->assertNull($returned);
        $this->assertSame('screenplay_invalid', $reason);
        $this->assertStringContainsString(
            'characters: this step does not produce it',
            (string) $this->sceneRows()->last()->error_message,
        );
    }

    public function test_a_new_character_list_makes_the_scenes_ask_the_model_again(): void
    {
        $foundationStage = $this->storeFoundation();
        [$characters, $locations] = $this->storeCast($foundationStage);

        $this->serviceAnswering($this->sceneAnswer())
            ->authorScenes($this->project->id, $foundationStage->id, $characters->id, $locations->id);

        $renamed = $characters->output_json['characters'];
        $renamed[1]['appearance'] = 'A different description of the same team, long enough to be valid.';
        $newer = $this->storeCastPart($foundationStage, 'characters', $renamed);

        [, $cached] = $this->silentService()
            ->authorScenes($this->project->id, $foundationStage->id, $characters->id, $locations->id);
        $this->assertSame('cached', $cached);

        [, $reason] = $this->serviceAnswering($this->sceneAnswer())
            ->authorScenes($this->project->id, $foundationStage->id, $newer->id, $locations->id);

        $this->assertSame('ok', $reason);
        $rows = $this->sceneRows();
        $this->assertCount(2, $rows);
        $this->assertNotSame($rows[0]->input_json['characters_content_hash'], $rows[1]->input_json['characters_content_hash']);
    }

    public function test_the_page_offers_each_step_only_when_its_sources_exist_and_shows_v4(): void
    {
        $this->actAsAdmin();
        $page = route('video-projects.anchor', $this->project->id);

        $this->get($page)->assertOk()
            ->assertSee('Tạo phân cảnh')
            ->assertSee('Cần nội dung kịch bản bản mới', false)
            ->assertDontSee('name="foundation_stage_id"', false);

        $foundationStage = $this->storeFoundation();

        $this->get($page)->assertOk()
            ->assertSee('id="screenplayCharactersForm"', false)
            ->assertSee('id="screenplayLocationsForm"', false)
            ->assertSee('name="foundation_stage_id" value="'.$foundationStage->id.'"', false)
            ->assertSee('Cần nhân vật và địa điểm tạo từ đúng bản nội dung phía trên', false)
            ->assertDontSee('id="screenplayForm"', false);

        [$characters, $locations] = $this->storeCast($foundationStage);

        $this->get($page)->assertOk()
            ->assertSee('name="characters_stage_id" value="'.$characters->id.'"', false)
            ->assertSee('name="locations_stage_id" value="'.$locations->id.'"', false)
            ->assertSee('chỉ tạo scene, coverage và build state')
            ->assertDontSee('Tạo lại phân cảnh');

        $this->serviceAnswering($this->sceneAnswer());
        $this->from($page)->post(route('video-projects.screenplay', $this->project->id), [
            'foundation_stage_id' => $foundationStage->id,
            'characters_stage_id' => $characters->id,
            'locations_stage_id' => $locations->id,
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

    public function test_the_cast_buttons_post_to_their_own_steps(): void
    {
        $this->actAsAdmin();
        $page = route('video-projects.anchor', $this->project->id);
        $foundationStage = $this->storeFoundation();

        $this->serviceAnswering(['characters' => $this->expansion()['characters']], $seen, 'characters');
        $this->from($page)->post(route('video-projects.screenplay-characters', $this->project->id), [
            'foundation_stage_id' => $foundationStage->id,
        ])->assertRedirect($page)->assertSessionHas('success');

        $this->serviceAnswering(['locations' => $this->expansion()['locations']], $seen, 'locations');
        $this->from($page)->post(route('video-projects.screenplay-locations', $this->project->id), [
            'foundation_stage_id' => $foundationStage->id,
        ])->assertRedirect($page)->assertSessionHas('success');

        $this->get($page)->assertOk()
            ->assertSee('Đã có nhân vật')
            ->assertSee('Đã có địa điểm')
            ->assertSee('Tạo lại nhân vật')
            ->assertSee('id="screenplayForm"', false);
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
        $this->scenes($this->serviceAnswering($this->sceneAnswer()), $foundation);
        $service = $this->app->make(VideoProjectService::class);
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
        $answer = $this->sceneAnswer() + ['logline' => 'A rewritten logline.', 'premise' => []];

        [$returned, $reason] = $this->scenes($this->serviceAnswering($answer), $foundationStage);

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
            'a character the cast never declared' => [
                static function (array $answer): array {
                    $answer['scenes'][0]['character_ids'][] = 'ch_invented_team';

                    return $answer;
                },
                'names character ch_invented_team that is not declared',
            ],
        ];

        foreach ($cases as $label => [$change, $expected]) {
            [, $reason] = $this->scenes($this->serviceAnswering($change($this->sceneAnswer())), $foundationStage, force: true);

            $this->assertSame('screenplay_invalid', $reason, $label);
            $this->assertStringContainsString($expected, (string) $this->sceneRows()->last()->error_message, $label);
        }
    }

    public function test_a_group_that_speaks_is_refused_after_the_split(): void
    {
        $this->allowDialogue();
        $foundationStage = $this->storeFoundation();
        $answer = $this->sceneAnswer();
        $group = collect($this->expansion()['characters'])->firstWhere('kind', 'group')['id'];
        $answer['scenes'][0]['character_ids'][] = $group;
        $answer['scenes'][0]['dialogue'] = [['character_id' => $group, 'line' => 'We are ready.']];

        [, $reason] = $this->scenes($this->serviceAnswering($answer), $foundationStage);

        $this->assertSame('screenplay_invalid', $reason);
        $this->assertStringContainsString(
            "{$group} is not a person and cannot speak",
            (string) $this->sceneRows()->last()->error_message,
        );
    }

    public function test_an_answer_cut_at_the_token_limit_is_never_stored_as_a_screenplay(): void
    {
        $foundationStage = $this->storeFoundation();
        $client = $this->answering($this->sceneAnswer(), $seen, 'max_tokens');

        [$returned, $reason] = $this->scenes($this->serviceWith($this->authorUsing($client)), $foundationStage);

        $this->assertNull($returned);
        $this->assertSame('screenplay_truncated', $reason);

        $row = $this->sceneRows()->last();
        $this->assertSame(VideoPlanningStageStatus::FAILED->value, $row->status);
        $this->assertNotNull($row->raw_response);
        $this->assertSame(12000, (int) $row->tokens_out);
        $this->assertNull($row->thinking_tokens);
    }

    public function test_the_same_foundation_is_not_paid_for_twice_and_force_asks_again(): void
    {
        $foundationStage = $this->storeFoundation();

        $this->scenes($this->serviceAnswering($this->sceneAnswer()), $foundationStage);

        [$cached, $reason] = $this->scenes($this->silentService(), $foundationStage);
        $this->assertSame('cached', $reason);
        $this->assertSame('screenplay_v4', $cached['schema_version']);

        [, $again] = $this->scenes($this->serviceAnswering($this->sceneAnswer()), $foundationStage, force: true);
        $this->assertSame('ok', $again);
        $this->assertCount(2, $this->sceneRows()->where('status', VideoPlanningStageStatus::SUCCEEDED->value));
    }

    public function test_a_new_foundation_changes_the_fingerprint_and_calls_the_model(): void
    {
        $first = $this->storeFoundation(2);
        $this->scenes($this->serviceAnswering($this->sceneAnswer()), $first);

        $second = $this->storeFoundation(3, $this->foundationOutput('A different logline for a different foundation.'));
        [, $reason] = $this->scenes($this->serviceAnswering($this->sceneAnswer()), $second);

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
        $this->scenes($this->serviceAnswering($this->sceneAnswer()), $first);
        $this->storeFoundation(3, $this->foundationOutput('A newer foundation.'));

        $this->get(route('video-projects.anchor', $this->project->id))->assertOk()
            ->assertSee('Phân cảnh này tạo từ nội dung rev 2, không phải bản nội dung đang hiển thị phía trên.')
            ->assertSee('Danh sách nhân vật này tạo từ nội dung rev 2, không phải bản nội dung đang hiển thị phía trên.');
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

        [$returned, $reason] = $this->scenes($this->serviceWith($this->authorUsing($client)), $foundationStage);

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
        $cast = $this->storeCast($foundationStage);

        foreach (['video.screenplay.foundation_author', 'video.screenplay.character_author',
            'video.screenplay.location_author', ScreenplayAuthor::class] as $key) {
            $this->app->forgetInstance($key);
            $this->app->bind($key, static function () use ($key) {
                throw new \LogicException("{$key} must not be built for scenes");
            });
        }

        [, $reason] = $this->serviceAnswering($this->sceneAnswer())
            ->authorScenes($this->project->id, $foundationStage->id, $cast[0]->id, $cast[1]->id);

        $this->assertSame('ok', $reason);
    }

    public function test_the_production_authors_use_their_own_limits_and_the_foundation_key(): void
    {
        $read = static function (object $object, string $name): mixed {
            $property = (new \ReflectionClass($object))->getProperty($name);
            $property->setAccessible(true);

            return $property->getValue($object);
        };

        $expected = [
            'scenes' => ['screenplay_scene_expansion_v2', 64000, 900],
            'characters' => ['screenplay_characters_v1', 8000, 300],
            'locations' => ['screenplay_locations_v1', 8000, 300],
        ];

        foreach ($expected as $step => [$contract, $maxTokens, $timeout]) {
            $this->app->forgetInstance(self::AUTHOR_KEYS[$step]);
            $author = $this->app->make(self::AUTHOR_KEYS[$step]);

            $this->assertSame($contract, $author->contractVersion(), $step);
            $this->assertSame('foundation', $read($author, 'sourceKey'), $step);
            $this->assertSame($maxTokens, $read($author, 'maxTokens'), $step);
            $this->assertSame($timeout, $read($author, 'client')->timeoutSeconds(), $step);
            $this->assertSame(1, $read($author, 'client')->attempts(), $step);
            $author->assertSchemaMatchesContract();
        }
    }

    public function test_the_worked_examples_split_the_old_example_and_pass_as_v4(): void
    {
        $old = $this->readJson(resource_path('ai/screenplay/scene_expansion_v1/04_worked_example.json'));
        $dir = resource_path('ai/screenplay/scene_expansion_v2/');
        $input = $this->readJson($dir.'04_worked_example.input.json');
        $output = $this->readJson($dir.'04_worked_example.json');
        $foundation = $this->readJson(resource_path('ai/screenplay/foundation_v2/04_worked_example.json'));
        $validator = new ScreenplayValidator;

        foreach (['characters' => 'characters_v1', 'locations' => 'locations_v1'] as $part => $folder) {
            $castExample = $this->readJson(resource_path("ai/screenplay/{$folder}/04_worked_example.json"));
            $castInput = $this->readJson(resource_path("ai/screenplay/{$folder}/04_worked_example.input.json"));

            $this->assertSame($castExample[$part], $input[$part], "{$part}: the scene example must use the step's own answer");
            $this->assertSame(array_column($old[$part], 'id'), array_column($castExample[$part], 'id'), $part);
            $this->assertSame([], $validator->structural($castExample, $castInput['profile'], "screenplay_{$folder}"), $part);
        }

        $this->assertSame($old['characters'], $input['characters']);

        $this->assertSame(['scenes', 'coverage'], array_keys($output));
        $this->assertSame($old['scenes'], $output['scenes']);
        $this->assertSame($old['coverage'], $output['coverage']);

        foreach (self::FOUNDATION_SECTIONS as $section) {
            $this->assertSame($foundation[$section], $input['foundation'][$section], $section);
            $this->assertArrayNotHasKey($section, $output, $section);
        }

        $expansion = ['characters' => $input['characters'], 'locations' => $input['locations']] + $output;

        $this->assertSame([], $validator->profileViolations($input['profile'], 'screenplay_scene_expansion_v2'));
        $this->assertSame([], $validator->structural($expansion, $input['profile'], 'screenplay_scene_expansion_v2'));

        $assembledProfile = ['contract_version' => 'screenplay_v4',
            'dimension_bounds' => ['length_m' => ['min' => 30, 'max' => 60]]] + $input['profile'];

        $this->assertSame(
            [],
            $validator->structural($input['foundation'] + $expansion, $assembledProfile, 'screenplay_v4'),
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
        $this->assertStringContainsString('Use only the supplied characters and locations', $prompt);
        $this->assertStringContainsString('do not split scenes merely to vary framing', mb_strtolower($prompt));
        $this->assertStringNotContainsString('approved', mb_strtolower($prompt));
        $this->assertStringNotContainsString('A location may be added', $prompt);

        foreach (['THE CENTRAL ASSIGNMENT', 'DESIGN THESIS', 'THE PREMISE', 'ORDER OF WORK'] as $heading) {
            $this->assertDoesNotMatchRegularExpression('/^'.preg_quote($heading, '/').'/m', $prompt, $heading);
        }
    }

    public function test_the_locations_step_describes_places_and_leaves_what_happens_to_the_scenes(): void
    {
        $rules = (new \ReflectionClass(ScreenplayAuthor::class))->getMethod('rules');
        $rules->setAccessible(true);
        $prompt = (string) $rules->invoke($this->authorUsing(Mockery::mock(StructuredOutputLlmClient::class), 'locations'));

        $this->assertStringContainsString('A PLACE, NOT WHAT HAPPENS IN IT', $prompt);
        $this->assertStringContainsString(
            "Actions, event sequences and the\nvessel's construction or completion state belong to individual scenes",
            $prompt,
        );
        $this->assertStringContainsString('A place away from the vessel is described without the vessel in it.', $prompt);
        $this->assertStringContainsString('A place aboard is described by its shell', $prompt);
        $this->assertStringContainsString("whether it is present\nbelongs to each scene's build state.", $prompt);
        $this->assertSame('locations-v1-r5', config('video.screenplay.locations.prompt_version'));
        $this->assertStringContainsString('Coverage names work to be shown, not rooms to declare.', $prompt);
        $this->assertStringContainsString("Do not relocate interior\nwork to an unrelated place merely to satisfy coverage", $prompt);
        $this->assertStringContainsString("places the main vessel, or any part of that vessel under construction, in\nthe location, including through a phrase of position, access or view.", $prompt);
        $this->assertStringContainsString("Wrong: \"A small open boat holding station on calm water, looking back\ntoward a larger hull.\"", $prompt);
        $this->assertStringContainsString("not added only because coverage names work\n   there?", $prompt);
        $this->assertStringContainsString("leave the main vessel, and every part of it, out of the location", $prompt);

        $hall = collect($this->readJson(resource_path('ai/screenplay/locations_v1/04_worked_example.json'))['locations'])
            ->firstWhere('id', 'lo_ferry_hall');
        $this->assertDoesNotMatchRegularExpression(
            '/\b(gates?|benches?|glazed|fitted|installed|finished)\b/i',
            $hall['description'],
            'the example hall must not carry what the finishing treatment installs',
        );

        $aboard = ['lo_ferry_hall'];

        foreach ($this->readJson(resource_path('ai/screenplay/locations_v1/04_worked_example.json'))['locations'] as $place) {
            if (in_array($place['id'], $aboard, true)) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                '/\b(ferry|hull|crowd|finished|handed over|while|then)\b/i',
                $place['description'],
                "{$place['id']} describes the vessel or what happens there",
            );
        }
    }

    public function test_the_example_output_is_free_of_measurements_camera_terms_and_contrast(): void
    {
        $text = mb_strtolower(json_encode(
            $this->readJson(resource_path('ai/screenplay/scene_expansion_v2/04_worked_example.json')),
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
        $this->assertSame(PlanningStageName::SCREENPLAY, PlanningStageName::SCREENPLAY_CHARACTERS->next());
        $this->assertSame(PlanningStageName::SCREENPLAY, PlanningStageName::SCREENPLAY_LOCATIONS->next());
        $this->assertSame(PlanningStageName::SCENE_PLAN, PlanningStageName::SCREENPLAY->next());

        foreach (PlanningStageName::cases() as $case) {
            $case->next();
            $this->assertLessThanOrEqual(24, strlen($case->value), $case->value);
        }
    }

    public function test_a_streamed_expansion_from_the_real_client_is_assembled_and_stored_as_v4(): void
    {
        $foundationStage = $this->storeFoundation();
        $text = json_encode($this->sceneAnswer(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
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

        [$stored, $reason] = $this->scenes($this->serviceWith($this->authorUsing($client)), $foundationStage);

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
            && $request['max_tokens'] === 64000);
    }

    private function stealClaim(PlanningStageName $stage): callable
    {
        return function () use ($stage): void {
            VideoPlanningStage::query()
                ->where('project_id', $this->project->id)
                ->where('stage', $stage->value)
                ->where('status', VideoPlanningStageStatus::RUNNING->value)
                ->update(['claim_token' => 'taken-by-another-request', 'lease_expires_at' => now()->addHour()]);
        };
    }

    public function test_a_paid_scene_answer_whose_claim_was_lost_is_kept_as_an_orphan(): void
    {
        $foundationStage = $this->storeFoundation();
        $client = $this->answering($this->sceneAnswer(), $seen, 'end_turn', $this->stealClaim(PlanningStageName::SCREENPLAY));

        [$returned, $reason] = $this->scenes($this->serviceWith($this->authorUsing($client)), $foundationStage);

        $this->assertNull($returned);
        $this->assertSame('screenplay_claim_lost', $reason);

        $orphan = $this->sceneRows()->first(static fn (VideoPlanningStage $row): bool => isset($row->input_json['orphan_of_stage_id']));
        $this->assertNotNull($orphan, 'the paid answer must survive the lost claim');
        $this->assertSame(VideoPlanningStageStatus::FAILED->value, $orphan->status);
        $this->assertSame(json_encode($this->sceneAnswer(), JSON_THROW_ON_ERROR), $orphan->raw_response);
        $this->assertSame(12000, (int) $orphan->tokens_out);
        $this->assertGreaterThan(0, (float) $orphan->cost_usd);
        $this->assertSame('screenplay_v4', $orphan->output_json['schema_version']);

        $panel = $this->app->make(VideoProjectService::class)->latestScreenplayScenes($this->project->id);
        $this->assertTrue($panel['running'], 'the orphan must not hide the run that took the claim');
    }

    public function test_a_refused_answer_whose_claim_was_lost_still_keeps_its_cost(): void
    {
        $foundationStage = $this->storeFoundation();
        $answer = $this->sceneAnswer() + ['logline' => 'A rewritten logline.'];
        $client = $this->answering($answer, $seen, 'end_turn', $this->stealClaim(PlanningStageName::SCREENPLAY));

        [, $reason] = $this->scenes($this->serviceWith($this->authorUsing($client)), $foundationStage);

        $this->assertSame('screenplay_claim_lost', $reason);

        $orphan = $this->sceneRows()->first(static fn (VideoPlanningStage $row): bool => isset($row->input_json['orphan_of_stage_id']));
        $this->assertNotNull($orphan);
        $this->assertStringContainsString('logline: this step does not produce it', (string) $orphan->error_message);
        $this->assertSame(json_encode($answer, JSON_THROW_ON_ERROR), $orphan->raw_response);
        $this->assertGreaterThan(0, (float) $orphan->cost_usd);
    }

    public function test_a_cast_answer_whose_claim_was_lost_is_kept_and_the_panel_still_shows_the_live_run(): void
    {
        $foundationStage = $this->storeFoundation();
        $client = $this->answering(
            ['characters' => $this->expansion()['characters']], $seen, 'end_turn',
            $this->stealClaim(PlanningStageName::SCREENPLAY_CHARACTERS),
        );

        [, $reason] = $this->serviceWith($this->authorUsing($client, 'characters'), 'characters')
            ->authorCharacters($this->project->id, $foundationStage->id);

        $this->assertSame('screenplay_claim_lost', $reason);

        $orphan = $this->rowsOf(PlanningStageName::SCREENPLAY_CHARACTERS)
            ->first(static fn (VideoPlanningStage $row): bool => isset($row->input_json['orphan_of_stage_id']));
        $this->assertNotNull($orphan);
        $this->assertSame($this->expansion()['characters'], $orphan->output_json['characters']);

        $panel = $this->app->make(ScreenplayExpansionService::class)
            ->latestCast($this->project->id, 'characters', $foundationStage->id);
        $this->assertTrue($panel['running']);
        $this->assertNull($panel['error']);
    }

    public function test_the_scene_request_allows_only_declared_ids_and_lets_only_people_speak(): void
    {
        $this->allowDialogue();
        $foundationStage = $this->storeFoundation();
        [$characters, $locations] = $this->storeCast($foundationStage);
        $cast = $characters->output_json['characters'];
        $ids = static fn (?string $kind = null): array => array_values(array_map(
            static fn (array $row): string => $row['id'],
            array_filter($cast, static fn (array $row): bool => $kind === null || $row['kind'] === $kind),
        ));

        [, $reason] = $this->serviceAnswering($this->sceneAnswer(), $seen)
            ->authorScenes($this->project->id, $foundationStage->id, $characters->id, $locations->id);

        $this->assertSame('ok', $reason);

        $scene = $seen['schema']['properties']['scenes']['items']['properties'];
        $this->assertSame(array_column($locations->output_json['locations'], 'id'), $scene['location_id']['enum']);
        $this->assertSame($ids(), $scene['character_ids']['items']['enum']);
        $this->assertSame($ids('person'), $scene['dialogue']['items']['properties']['character_id']['enum']);
        $this->assertSame($ids('object'), $scene['build_state']['properties']['subject_id']['enum']);
        $this->assertArrayNotHasKey('pattern', $scene['dialogue']['items']['properties']['character_id']);
        $this->assertNotContains($ids('group')[0], $scene['dialogue']['items']['properties']['character_id']['enum']);

        $canonical = $this->readJson(resource_path('ai/screenplay/schemas/screenplay_scene_expansion_v2.json'));
        $this->assertArrayNotHasKey('enum', $canonical['properties']['scenes']['items']['properties']['location_id']);
        $this->assertSame(64, strlen($this->sceneRows()->last()->input_json['output_schema_hash']));
    }

    public function test_a_cast_without_people_is_not_offered_dialogue_and_stores_silent_scenes(): void
    {
        $foundationStage = $this->storeFoundation();
        [$characters, $locations] = $this->castWithoutPeople($foundationStage);
        $answer = $this->sceneAnswer();
        $answer['scenes'] = array_map(static function (array $scene): array {
            unset($scene['dialogue']);

            return $scene;
        }, $answer['scenes']);

        [$stored, $reason] = $this->serviceAnswering($answer, $seen)
            ->authorScenes($this->project->id, $foundationStage->id, $characters->id, $locations->id);

        $sceneSchema = $seen['schema']['properties']['scenes']['items'];
        $this->assertArrayNotHasKey('dialogue', $sceneSchema['properties']);
        $this->assertNotContains('dialogue', $sceneSchema['required']);
        $this->assertStringNotContainsString('"enum":[]', json_encode($seen['schema']));

        $this->assertSame('ok', $reason);
        $this->assertNotEmpty($stored['scenes']);
        foreach ($stored['scenes'] as $scene) {
            $this->assertSame([], $scene['dialogue'], "{$scene['id']} must be silent");
        }

        $row = $this->sceneRows()->last();
        $this->assertSame(VideoPlanningStageStatus::SUCCEEDED->value, $row->status);
        $this->assertSame([[]], array_values(array_unique(array_column($row->output_json['scenes'], 'dialogue'), SORT_REGULAR)));
    }

    public function test_a_cast_without_people_refuses_an_answer_that_still_speaks(): void
    {
        $this->allowDialogue();
        $foundationStage = $this->storeFoundation();
        [$characters, $locations] = $this->castWithoutPeople($foundationStage);
        $answer = $this->sceneAnswer(spoken: true);
        $speaking = array_values(array_filter($answer['scenes'], static fn (array $scene): bool => ($scene['dialogue'] ?? []) !== []));
        $this->assertNotEmpty($speaking, 'the fixture must contain dialogue for this test to mean anything');

        [$stored, $reason] = $this->serviceAnswering($answer, $seen)
            ->authorScenes($this->project->id, $foundationStage->id, $characters->id, $locations->id);

        $this->assertNull($stored);
        $this->assertSame('screenplay_invalid', $reason);

        $row = $this->sceneRows()->last();
        $this->assertSame(VideoPlanningStageStatus::FAILED->value, $row->status);
        $this->assertStringContainsString(
            "{$speaking[0]['id']}.dialogue: no declared person can speak in this film",
            (string) $row->error_message,
        );
        $this->assertSame(json_encode($answer, JSON_THROW_ON_ERROR), $row->raw_response);
        $this->assertGreaterThan(0, (float) $row->cost_usd);
    }

    public function test_a_scene_whose_build_state_object_is_not_listed_is_refused_with_its_cost_kept(): void
    {
        $foundationStage = $this->storeFoundation();
        $answer = $this->sceneAnswer();
        $index = array_search('sc_03', array_column($answer['scenes'], 'id'), true);
        $subject = $answer['scenes'][$index]['build_state']['subject_id'];
        $answer['scenes'][$index]['character_ids'] = array_values(array_diff($answer['scenes'][$index]['character_ids'], [$subject]));

        [$stored, $reason] = $this->scenes($this->serviceAnswering($answer, $seen), $foundationStage);

        $this->assertNull($stored);
        $this->assertSame('screenplay_invalid', $reason);

        $row = $this->sceneRows()->last();
        $this->assertSame(VideoPlanningStageStatus::FAILED->value, $row->status);
        $this->assertStringContainsString(
            "sc_03.build_state.subject_id: {$subject} must be listed in character_ids",
            (string) $row->error_message,
        );
        $this->assertSame(json_encode($answer, JSON_THROW_ON_ERROR), $row->raw_response);
        $this->assertGreaterThan(0, (float) $row->cost_usd);
    }

    public function test_a_scene_without_a_build_state_does_not_have_to_list_the_object(): void
    {
        $foundationStage = $this->storeFoundation();
        $answer = $this->sceneAnswer();
        $subject = $answer['scenes'][0]['build_state']['subject_id'];
        $answer['scenes'][0]['build_state'] = null;
        $answer['scenes'][0]['character_ids'] = array_values(array_diff($answer['scenes'][0]['character_ids'], [$subject]));

        [$stored, $reason] = $this->scenes($this->serviceAnswering($answer, $seen), $foundationStage);

        $this->assertSame('ok', $reason, (string) $this->sceneRows()->last()->error_message);
        $this->assertNull($stored['scenes'][0]['build_state']);
        $this->assertNotContains($subject, $stored['scenes'][0]['character_ids']);
    }

    public function test_an_unlisted_build_state_object_is_refused_only_by_the_new_scene_contract(): void
    {
        $fixture = $this->readJson(base_path('tests/Fixtures/screenplay/v3_screenplay.json'));
        $profile = $this->readJson(resource_path('ai/profiles/screenplay/yacht_v1.json'));
        $expansion = array_intersect_key($fixture, array_flip(['characters', 'locations', 'scenes', 'coverage']));
        $check = static fn (array $screenplay, string $contract): array => (new ScreenplayValidator)
            ->structural($screenplay, ['contract_version' => $contract] + $profile, $contract);
        $unlisted = static fn (array $violations): array => array_values(array_filter(
            $violations,
            static fn (string $violation): bool => str_contains($violation, 'must be listed in character_ids'),
        ));

        $this->assertContains(
            'sc_03.build_state.subject_id: ch_vessel must be listed in character_ids',
            $check($expansion, 'screenplay_scene_expansion_v2'),
        );
        $this->assertCount(8, $unlisted($check($expansion, 'screenplay_scene_expansion_v2')));
        $this->assertSame([], $unlisted($check($expansion, 'screenplay_scene_expansion_v1')));
        $this->assertSame([], $check($fixture, 'screenplay_v3'), 'the v3 contract keeps accepting the fixture');
    }

    /** @return array{0: VideoPlanningStage, 1: VideoPlanningStage} */
    private function castWithoutPeople(VideoPlanningStage $foundation): array
    {
        $groupsOnly = array_map(
            static fn (array $row): array => $row['kind'] === 'person' ? ['kind' => 'group'] + $row : $row,
            $this->expansion()['characters'],
        );

        return [
            $this->storeCastPart($foundation, 'characters', $groupsOnly),
            $this->storeCastPart($foundation, 'locations'),
        ];
    }

    public function test_a_profile_without_dialogue_offers_no_dialogue_and_stores_silent_scenes(): void
    {
        $foundationStage = $this->storeFoundation();

        [$stored, $reason] = $this->scenes($this->serviceAnswering($this->sceneAnswer(), $seen), $foundationStage);

        $sceneSchema = $seen['schema']['properties']['scenes']['items'];
        $this->assertArrayNotHasKey('dialogue', $sceneSchema['properties']);
        $this->assertNotContains('dialogue', $sceneSchema['required']);
        $this->assertStringContainsString('"dialogue_allowed": false', $seen['user']);

        $this->assertSame('ok', $reason);
        foreach ($stored['scenes'] as $scene) {
            $this->assertSame([], $scene['dialogue'], "{$scene['id']} must be silent");
        }

        $this->assertNotEmpty(
            collect($stored['characters'])->where('kind', 'person')->all(),
            'the cast still has people; the profile alone silences the film',
        );
    }

    public function test_a_profile_without_dialogue_refuses_an_answer_that_still_speaks(): void
    {
        $foundationStage = $this->storeFoundation();
        $answer = $this->sceneAnswer(spoken: true);
        $speaking = array_values(array_filter($answer['scenes'], static fn (array $scene): bool => $scene['dialogue'] !== []));
        $this->assertNotEmpty($speaking, 'the fixture must contain dialogue for this test to mean anything');

        [$stored, $reason] = $this->scenes($this->serviceAnswering($answer, $seen), $foundationStage);

        $this->assertNull($stored);
        $this->assertSame('screenplay_invalid', $reason);

        $row = $this->sceneRows()->last();
        $this->assertSame(VideoPlanningStageStatus::FAILED->value, $row->status);
        $this->assertStringContainsString(
            "{$speaking[0]['id']}.dialogue: the profile does not allow dialogue",
            (string) $row->error_message,
        );
        $this->assertStringNotContainsString('no declared person can speak', (string) $row->error_message);
        $this->assertSame(json_encode($answer, JSON_THROW_ON_ERROR), $row->raw_response);
        $this->assertGreaterThan(0, (float) $row->cost_usd);
    }

    public function test_the_dialogue_policy_changes_the_scene_key_but_not_the_foundation_or_the_cast(): void
    {
        $this->actAsAdmin();
        $foundationStage = $this->storeFoundation();
        [$characters, $locations] = $this->storeCast($foundationStage);

        $this->scenes($this->serviceAnswering($this->sceneAnswer()), $foundationStage);
        $silent = $this->sceneRows()->last()->input_json;

        $this->allowDialogue();
        $this->scenes($this->serviceAnswering($this->sceneAnswer(spoken: true)), $foundationStage);
        $spoken = $this->sceneRows()->last()->input_json;

        $this->assertNotSame($silent['fingerprint'], $spoken['fingerprint']);
        $this->assertNotSame($silent['output_schema_hash'], $spoken['output_schema_hash']);
        $this->assertCount(2, $this->sceneRows(), 'the silent answer is not reused once dialogue is allowed');

        $foundationProfile = (new \ReflectionClass(VideoProjectService::class))->getMethod('foundationProfile');
        $foundationProfile->setAccessible(true);
        $profile = $this->readJson(resource_path('ai/profiles/screenplay/yacht_v1.json'));
        $allowed = $profile;
        $allowed['people_policy']['dialogue_allowed'] = true;
        $this->assertSame($foundationProfile->invoke(null, $profile), $foundationProfile->invoke(null, $allowed));
        $this->assertArrayNotHasKey('dialogue_allowed', $foundationProfile->invoke(null, $profile)['people_policy']);

        $service = $this->app->make(ScreenplayExpansionService::class);
        $this->assertTrue($service->latestCast($this->project->id, 'characters', $foundationStage->id)['usable']);
        $this->assertTrue($service->latestCast($this->project->id, 'locations', $foundationStage->id)['usable']);
        $this->assertSame([$characters->id, $locations->id], [
            $spoken['_meta']['characters_stage_id'], $spoken['_meta']['locations_stage_id'],
        ]);
    }

    public function test_a_profile_without_the_flag_keeps_dialogue(): void
    {
        $this->useProfile(static function (array $profile): array {
            unset($profile['people_policy']['dialogue_allowed']);

            return $profile;
        });
        $foundationStage = $this->storeFoundation();
        $answer = $this->sceneAnswer(spoken: true);
        $spoken = array_values(array_filter($answer['scenes'], static fn (array $scene): bool => $scene['dialogue'] !== []));
        $this->assertNotEmpty($spoken, 'the fixture must contain dialogue for this test to mean anything');

        [$stored, $reason] = $this->scenes($this->serviceAnswering($answer, $seen), $foundationStage);

        $this->assertArrayHasKey('dialogue', $seen['schema']['properties']['scenes']['items']['properties']);
        $this->assertStringNotContainsString('dialogue_allowed', $seen['user']);
        $this->assertSame('ok', $reason, (string) $this->sceneRows()->last()->error_message);
        $this->assertSame(
            $spoken[0]['dialogue'],
            collect($stored['scenes'])->firstWhere('id', $spoken[0]['id'])['dialogue'],
        );
    }

    public function test_a_dialogue_policy_that_is_not_a_boolean_is_refused_before_any_call(): void
    {
        $this->useProfile(static function (array $profile): array {
            $profile['people_policy']['dialogue_allowed'] = 'false';

            return $profile;
        });
        $foundationStage = $this->storeFoundation();
        $this->storeCast($foundationStage);

        [$stored, $reason] = $this->scenes($this->silentService(), $foundationStage);

        $this->assertNull($stored);
        $this->assertSame('screenplay_profile_invalid', $reason);
        $this->assertCount(0, $this->sceneRows());
        $this->assertContains(
            'people_policy.dialogue_allowed: must be true or false',
            (new ScreenplayValidator)->profileViolations(
                ['contract_version' => 'screenplay_scene_expansion_v2', 'people_policy' => ['dialogue_allowed' => 'false']]
                    + $this->readJson(resource_path('ai/profiles/screenplay/yacht_v1.json')),
                'screenplay_scene_expansion_v2',
            ),
        );
    }

    public function test_the_prompts_carry_the_speaker_place_and_launch_rules(): void
    {
        $rules = (new \ReflectionClass(ScreenplayAuthor::class))->getMethod('rules');
        $rules->setAccessible(true);
        $prompt = fn (string $step): string => (string) $rules->invoke(
            $this->authorUsing(Mockery::mock(StructuredOutputLlmClient::class), $step),
        );

        $this->assertStringContainsString('merge minor groups rather than', $prompt('characters'));
        $this->assertStringContainsString('dialogue is optional', $prompt('characters'));
        $this->assertStringContainsString('Wrong: "A working quay where the hull lies moored alongside', $prompt('locations'));
        $this->assertStringContainsString("Never add or substitute a speaker to keep a line, and never hand a group's\nwords to a person.", $prompt('scenes'));
        $this->assertStringContainsString('When no listed person has a line of their own, use', $prompt('scenes'));
        $this->assertStringContainsString("listed in that\n   scene's own character_ids", $prompt('scenes'));
        $this->assertSame('scene-expansion-v2-r7', config('video.screenplay.scenes.prompt_version'));
        $this->assertSame('characters-v1-r3', config('video.screenplay.characters.prompt_version'));
        $this->assertStringContainsString("do not move a line into action or sound as quoted or reported\nspeech.", $prompt('scenes'));
        $this->assertStringContainsString('Indistinct background talk may stay in sound.', $prompt('scenes'));
        $this->assertStringContainsString("When the schema has no dialogue field, leave the field out;\nthe application records an empty dialogue.", $prompt('scenes'));
        $this->assertStringContainsString("Declare a person separately to carry dialogue only when the profile's\npeople_policy.dialogue_allowed permits it", $prompt('characters'));
        $this->assertStringContainsString('A scene has one location_id, and every shot of it is filmed there.', $prompt('scenes'));
        $this->assertStringContainsString('continuous situation together applies only within one location.', $prompt('scenes'));
        $this->assertStringContainsString("CONTINUOUS only when there is no time gap between the two\nscenes.", $prompt('scenes'));
        $this->assertStringContainsString("Do not claim\ncompleted work merely because it is expected at this stage.", $prompt('scenes'));
        $this->assertStringContainsString("Evidence for a shown item states what the referenced scenes' actions or\nobservable results show.", $prompt('scenes'));
        $this->assertStringContainsString('a transition never excuses content that is', $prompt('scenes'));
        $this->assertStringContainsString("stay in its one declared\n   location?", $prompt('scenes'));
        $this->assertStringContainsString("claim work that neither the\n   action nor accounted-for progress establishes?", $prompt('scenes'));
        $this->assertStringContainsString("A scene whose build_state names an object lists that object in its\ncharacter_ids", $prompt('scenes'));
        $this->assertStringContainsString('repeated crossings through a whole day', $prompt('scenes'));
        $this->assertStringContainsString("choose one continuous\nmoment that stands for it", $prompt('scenes'));
        $this->assertStringContainsString('A short wait inside one situation stays in that scene.', $prompt('scenes'));
        $this->assertStringContainsString('Launch belongs to the same record.', $prompt('scenes'));
        $this->assertStringContainsString("no later scene returns it to an unlaunched state\nor shows a first launch", $prompt('scenes'));
        $this->assertStringContainsString('first scene with the ferry afloat', $prompt('scenes'));

        $profile = $this->readJson(resource_path('ai/profiles/screenplay/yacht_v1.json'));
        $launch = collect($profile['coverage'])->firstWhere('id', 'cov_comp_launch');
        $this->assertStringContainsString('before the first scene in which the vessel is afloat', $launch['label']);
        $this->assertSame('completion', $launch['stage'], 'the stage is not moved for every project');
    }

    public function test_an_empty_list_is_refused_by_the_cast_step(): void
    {
        $foundationStage = $this->storeFoundation();

        [$stored, $reason] = $this->serviceAnswering(['locations' => []], $seen, 'locations')
            ->authorLocations($this->project->id, $foundationStage->id);

        $this->assertNull($stored);
        $this->assertSame('screenplay_invalid', $reason);
        $this->assertStringContainsString(
            'locations: must not be empty',
            (string) $this->rowsOf(PlanningStageName::SCREENPLAY_LOCATIONS)->last()->error_message,
        );
    }

    public function test_a_cast_stays_usable_for_a_new_foundation_revision_with_the_same_content(): void
    {
        $this->actAsAdmin();
        $first = $this->storeFoundation(2);
        [$characters, $locations] = $this->storeCast($first);
        $same = $this->storeFoundation(3);

        $service = $this->app->make(ScreenplayExpansionService::class);
        $this->assertTrue($service->latestCast($this->project->id, 'characters', $same->id)['usable']);
        $this->assertTrue($service->latestCast($this->project->id, 'locations', $same->id)['usable']);

        $this->get(route('video-projects.anchor', $this->project->id))->assertOk()
            ->assertSee('name="foundation_stage_id" value="'.$same->id.'"', false)
            ->assertSee('name="characters_stage_id" value="'.$characters->id.'"', false)
            ->assertSee('name="locations_stage_id" value="'.$locations->id.'"', false)
            ->assertDontSee('Danh sách nhân vật này tạo từ nội dung rev');

        $changed = $this->storeFoundation(4, $this->foundationOutput('A foundation with different content.'));
        $this->assertFalse($service->latestCast($this->project->id, 'characters', $changed->id)['usable']);
    }

    public function test_a_claude_answer_without_a_thinking_figure_carries_none_and_a_total_is_never_partial(): void
    {
        $read = (new \ReflectionClass(\App\Services\Admin\ClaudeWriterService::class))->getMethod('responseFromBody');
        $read->setAccessible(true);
        $writer = $this->app->make(\App\Services\Admin\ClaudeWriterService::class);
        $body = ['content' => [['type' => 'text', 'text' => 'ok']], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]];

        $this->assertNull($read->invoke($writer, $body)->thinkingTokens);

        $body['usage']['output_tokens_details'] = ['thinking_tokens' => 3];
        $this->assertSame(3, $read->invoke($writer, $body)->thinkingTokens);

        $body['usage']['output_tokens_details'] = ['thinking_tokens' => 0];
        $this->assertSame(0, $read->invoke($writer, $body)->thinkingTokens, 'a measured zero is a measurement');

        foreach ([-1, '5', 5.0, [], true] as $malformed) {
            $body['usage']['output_tokens_details'] = ['thinking_tokens' => $malformed];
            $this->assertNull($read->invoke($writer, $body)->thinkingTokens, var_export($malformed, true));
        }

        $inner = Mockery::mock(\App\Video\Llm\LlmClient::class);
        $inner->shouldReceive('complete')->twice()->andReturn(
            new \App\Video\Llm\LlmResponse('a', 'haiku', 1, 1, 0, 0.0, 'a', 'm', 4),
            new \App\Video\Llm\LlmResponse('b', 'haiku', 1, 1, 0, 0.0, 'b', 'm', null),
        );
        $totals = new \App\Video\Llm\CostAccumulatingLlmClient($inner);
        $request = new \App\Video\Llm\LlmRequest('i', 'x', 'v1', 'haiku');
        $totals->complete($request);

        $this->assertSame(4, $totals->totals()['thinking_tokens']);

        $totals->complete($request);

        $this->assertNull($totals->totals()['thinking_tokens'], 'a total missing one figure is not a total');
    }
}
