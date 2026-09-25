<?php

namespace Tests\Feature\Video;

use App\Enums\PlanningStageName;
use App\Models\Article;
use App\Models\Category;
use App\Models\VideoProject;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Services\Video\PlanningStageStore;
use App\Services\Video\CreativeProfileResolver;
use App\Services\VideoProjectService;
use App\Video\Concept\Contracts\StructuredOutputLlmClient;
use App\Video\Screenplay\ScreenplayAuthor;
use App\Video\Screenplay\ScreenplayValidator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ScreenplayProfileGuardTest extends TestCase
{
    private ?string $profileDirectory = null;

    private ?string $schemaFile = null;

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
            if ($this->schemaFile !== null && is_file($this->schemaFile)) {
                unlink($this->schemaFile);
            }
            unlink($this->profileDirectory.'/guard.json');
            rmdir($this->profileDirectory);
        }
        parent::tearDown();
    }

    private function fixture(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../../fixtures/screenplay/v3_profile.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_valid_profiles_pass_without_changing_the_active_contract(): void
    {
        $validator = new ScreenplayValidator;
        $this->assertSame([], $validator->profileViolations($this->fixture(), 'screenplay_v3'));
        foreach (['superyacht_v1' => 'screenplay_v2', 'yacht_v1' => 'screenplay_v3'] as $name => $version) {
            $profile = json_decode(file_get_contents(resource_path("ai/profiles/screenplay/{$name}.json")), true, 512, JSON_THROW_ON_ERROR);
            $creative = (new CreativeProfileResolver)->resolve('yacht');
            if ($version === 'screenplay_v2') {
                $profile += ['arc_stages' => $creative->arcStages, 'arc_required_stages' => $creative->arcRequiredStages];
            }
            $this->assertSame([], $validator->profileViolations($profile, $version));
        }
    }

    /** @dataProvider invalidProfiles */
    public function test_bad_profile_fields_return_precise_errors(string $path, mixed $value, string $expected, bool $remove = false): void
    {
        $profile = $this->fixture();
        if ($remove) {
            Arr::forget($profile, $path);
        } else {
            Arr::set($profile, $path, $value);
        }

        $this->assertContains($expected, (new ScreenplayValidator)->profileViolations($profile, 'screenplay_v3'));
    }

    public static function invalidProfiles(): array
    {
        return [
            'missing version' => ['contract_version', null, 'contract_version: must match the author contract', true],
            'wrong version' => ['contract_version', 'screenplay_v2', 'contract_version: must match the author contract'],
            'unknown version' => ['contract_version', 'screenplay_v999', 'contract_version: must match the author contract'],
            'missing min' => ['min_scenes', null, 'min_scenes: must be a positive integer', true],
            'numeric string' => ['min_scenes', '3', 'min_scenes: must be a positive integer'],
            'zero max' => ['max_scenes', 0, 'max_scenes: must be a positive integer'],
            'boolean max' => ['max_scenes', true, 'max_scenes: must be a positive integer'],
            'min above max' => ['min_scenes', 31, 'min_scenes: must not exceed max_scenes'],
            'impossible stages' => ['max_scenes', 3, 'max_scenes: cannot accommodate all required stages'],
            'missing subjects' => ['max_subjects', null, 'max_subjects: must be a positive integer', true],
            'negative subjects' => ['max_subjects', -1, 'max_subjects: must be a positive integer'],
            'missing locations' => ['max_locations', null, 'max_locations: must be a positive integer', true],
            'float locations' => ['max_locations', 12.0, 'max_locations: must be a positive integer'],
            'array limit' => ['max_locations', [], 'max_locations: must be a positive integer'],
            'empty stages' => ['arc_stages', [], 'arc_stages: must be a nonempty list'],
            'string stages' => ['arc_stages', 'design', 'arc_stages: must be a nonempty list'],
            'object stages' => ['arc_stages', ['stage' => 'design'], 'arc_stages: must be a nonempty list'],
            'bad stage type' => ['arc_stages.0', [], 'arc_stages[0]: must be a nonempty string'],
            'blank stage' => ['arc_stages.0', '   ', 'arc_stages[0]: must be a nonempty string'],
            'duplicate stage' => ['arc_stages.1', 'design', 'arc_stages[1]: duplicate stage'],
            'empty required' => ['arc_required_stages', [], 'arc_required_stages: must be a nonempty list'],
            'duplicate required' => ['arc_required_stages.1', 'design', 'arc_required_stages[1]: duplicate stage'],
            'unknown required' => ['arc_required_stages.0', 'unknown', 'arc_required_stages: must be a subset of arc_stages'],
            'missing coverage' => ['coverage', null, 'coverage: must be a nonempty list', true],
            'empty coverage' => ['coverage', [], 'coverage: must be a nonempty list'],
            'string coverage' => ['coverage', 'wrong', 'coverage: must be a nonempty list'],
            'bad coverage row' => ['coverage.0', 'wrong', 'coverage[0]: must be an object'],
            'bad coverage id' => ['coverage.0.id', [], 'coverage[0].id: invalid coverage id'],
            'wrong id syntax' => ['coverage.0.id', 'design', 'coverage[0].id: invalid coverage id'],
            'duplicate coverage' => ['coverage.1.id', 'cov_design', 'coverage[1].id: duplicate coverage id'],
            'unknown coverage stage' => ['coverage.0.stage', 'unknown', 'coverage[0].stage: must belong to arc_stages'],
            'bad coverage stage type' => ['coverage.0.stage', [], 'coverage[0].stage: must belong to arc_stages'],
            'unknown coverage level' => ['coverage.0.level', 'optional', 'coverage[0].level: unsupported coverage level'],
            'blank coverage label' => ['coverage.0.label', '   ', 'coverage[0].label: must be a nonempty string'],
        ];
    }

    public function test_unsupported_server_contract_is_rejected(): void
    {
        $this->assertSame(['contract_version: unsupported author contract'], (new ScreenplayValidator)->profileViolations($this->fixture(), 'screenplay_v999'));
    }

    /** @dataProvider rejectedServiceProfiles */
    public function test_service_rejects_profile_before_claim_and_client(string $path, mixed $value, string $reason, bool $remove = false): void
    {
        $profile = json_decode(file_get_contents(resource_path('ai/profiles/screenplay/superyacht_v1.json')), true, 512, JSON_THROW_ON_ERROR);
        if ($remove) {
            Arr::forget($profile, $path);
        } else {
            Arr::set($profile, $path, $value);
        }

        [$service, $store] = $this->serviceWithProfile($profile);
        $store->shouldNotReceive('claimProjectStage');
        $store->shouldNotReceive('finishFailed');
        $store->shouldNotReceive('finishSucceeded');

        $this->assertSame([null, $reason], $service->authorScreenplay('guard-project'));
    }

    public static function rejectedServiceProfiles(): array
    {
        return [
            'version absent' => ['contract_version', null, 'screenplay_profile_contract_mismatch', true],
            'v3 profile with v2 author' => ['contract_version', 'screenplay_v3', 'screenplay_profile_contract_mismatch'],
            'unknown version' => ['contract_version', 'screenplay_v999', 'screenplay_profile_contract_mismatch'],
            'missing limit' => ['max_subjects', null, 'screenplay_profile_invalid', true],
            'invalid type' => ['max_locations', [], 'screenplay_profile_invalid'],
            'impossible plan' => ['max_scenes', 3, 'screenplay_profile_invalid'],
            'empty allowed stages' => ['arc_stages', [], 'screenplay_profile_invalid'],
            'invalid required stage' => ['arc_required_stages', ['unknown'], 'screenplay_profile_invalid'],
        ];
    }

    public function test_compatible_v2_profile_reaches_claim_without_calling_provider(): void
    {
        $profile = json_decode(file_get_contents(resource_path('ai/profiles/screenplay/superyacht_v1.json')), true, 512, JSON_THROW_ON_ERROR);
        [$service, $store, $client] = $this->serviceWithProfile($profile);
        $author = $this->app->make(ScreenplayAuthor::class);
        $this->assertSame($client, (new \ReflectionProperty($author, 'client'))->getValue($author));
        $effective = (new \ReflectionMethod($service, 'screenplayProfile'))->invoke($service, 'yacht');
        $creative = (new CreativeProfileResolver)->resolve('yacht');
        $this->assertSame($creative->arcStages, $effective['arc_stages']);
        $this->assertSame($creative->arcRequiredStages, $effective['arc_required_stages']);
        $store->shouldReceive('claimProjectStage')->once()
            ->with('guard-project', PlanningStageName::SCREENPLAY, Mockery::type('array'))
            ->andReturn([null, null, 'claimed_by_other']);

        $this->assertSame([null, 'screenplay_running'], $service->authorScreenplay('guard-project'));
    }

    /** @dataProvider stageFields */
    public function test_service_loader_does_not_supply_missing_v3_stages(string $field): void
    {
        $profile = $this->fixture();
        unset($profile[$field]);
        [$service, $store] = $this->serviceWithProfile($profile);
        $store->shouldNotReceive('claimProjectStage');

        // Exercise the real loader while the running author intentionally remains v2.
        $effective = (new \ReflectionMethod($service, 'screenplayProfile'))->invoke($service, 'yacht');
        $this->assertArrayNotHasKey($field, $effective);
        $this->assertContains("{$field}: must be a nonempty list", (new ScreenplayValidator)->profileViolations($effective, 'screenplay_v3'));
        $this->assertSame([null, 'screenplay_profile_contract_mismatch'], $service->authorScreenplay('guard-project'));
    }

    public static function stageFields(): array
    {
        return [['arc_stages'], ['arc_required_stages']];
    }

    /** @dataProvider invalidSchemas */
    public function test_service_rejects_bad_schema_before_claim_or_client(string $case): void
    {
        $profile = json_decode(file_get_contents(resource_path('ai/profiles/screenplay/superyacht_v1.json')), true, 512, JSON_THROW_ON_ERROR);
        [$service, $store] = $this->serviceWithProfile($profile);
        $store->shouldNotReceive('claimProjectStage');
        $store->shouldNotReceive('finishFailed');
        $store->shouldNotReceive('finishSucceeded');

        $this->schemaFile = $this->profileDirectory.'/screenplay_v2.json';
        if ($case === 'v3_path') {
            $path = resource_path('ai/screenplay/schemas/screenplay_v3.json');
        } else {
            $path = $this->schemaFile;
            $content = match ($case) {
                'v3_content_with_v2_name' => file_get_contents(resource_path('ai/screenplay/schemas/screenplay_v3.json')),
                'broken_json' => '{',
                'non_object' => 'null',
                'incomplete_schema' => '{"type":"object"}',
                default => null,
            };
            if ($content !== null) {
                file_put_contents($path, $content);
            }
        }
        config(['video.screenplay.schema_path' => $path]);
        $this->app->forgetInstance(ScreenplayAuthor::class);

        $this->assertSame([null, 'screenplay_schema_invalid'], $service->authorScreenplay('guard-project'));
    }

    public static function invalidSchemas(): array
    {
        return array_map(static fn (string $case): array => [$case], [
            'v3_path', 'v3_content_with_v2_name', 'broken_json', 'non_object', 'incomplete_schema', 'missing_file',
        ]);
    }

    /** @dataProvider schemaChanges */
    public function test_schema_comparison_ignores_object_order_but_preserves_arrays_and_types(string $change, bool $valid): void
    {
        $profile = json_decode(file_get_contents(resource_path('ai/profiles/screenplay/superyacht_v1.json')), true, 512, JSON_THROW_ON_ERROR);
        [$service, $store] = $this->serviceWithProfile($profile);
        $schema = json_decode(file_get_contents(resource_path('ai/screenplay/schemas/screenplay_v2.json')), false, 512, JSON_THROW_ON_ERROR);
        $author = $this->app->make(ScreenplayAuthor::class);
        $originalFingerprint = $author->fingerprint([], $profile, []);
        $this->app->forgetInstance(ScreenplayAuthor::class);

        switch ($change) {
            case 'object_order':
                $reverse = function (mixed $value) use (&$reverse): mixed {
                    if ($value instanceof \stdClass) {
                        $result = new \stdClass;
                        foreach (array_reverse(get_object_vars($value), true) as $key => $child) {
                            $result->{$key} = $reverse($child);
                        }
                        return $result;
                    }
                    return is_array($value) ? array_map($reverse, $value) : $value;
                };
                $schema = $reverse($schema);
                break;
            case 'array_order':
                $schema->required = array_reverse($schema->required);
                break;
            case 'string_number':
                $schema->properties->logline->minLength = '20';
                break;
            case 'float_number':
                $schema->properties->logline->minLength = 20.0;
                break;
            case 'changed_value':
                $schema->properties->logline->minLength = 21;
                break;
            case 'array_as_object':
                $schema->required = (object) $schema->required;
                break;
        }
        $this->schemaFile = $this->profileDirectory.'/custom-schema.json';
        file_put_contents($this->schemaFile, json_encode($schema, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        config(['video.screenplay.schema_path' => $this->schemaFile]);

        if ($valid) {
            $store->shouldReceive('claimProjectStage')->once()->andReturn([null, null, 'claimed_by_other']);
            $this->assertSame($originalFingerprint, $this->app->make(ScreenplayAuthor::class)->fingerprint([], $profile, []));
            $this->assertSame([null, 'screenplay_running'], $service->authorScreenplay('guard-project'));
        } else {
            $store->shouldNotReceive('claimProjectStage');
            $this->assertSame([null, 'screenplay_schema_invalid'], $service->authorScreenplay('guard-project'));
        }
    }

    public static function schemaChanges(): array
    {
        return [
            'nested object keys' => ['object_order', true],
            'array order' => ['array_order', false],
            'string instead of integer' => ['string_number', false],
            'float instead of integer' => ['float_number', false],
            'changed constraint' => ['changed_value', false],
            'numeric object instead of array' => ['array_as_object', false],
        ];
    }

    public function test_equivalent_schema_copy_is_accepted_and_reused_after_validation(): void
    {
        $profile = json_decode(file_get_contents(resource_path('ai/profiles/screenplay/superyacht_v1.json')), true, 512, JSON_THROW_ON_ERROR);
        [$service, $store] = $this->serviceWithProfile($profile);
        $this->schemaFile = $this->profileDirectory.'/custom-schema.json';
        $schema = json_decode(file_get_contents(resource_path('ai/screenplay/schemas/screenplay_v2.json')), true, 512, JSON_THROW_ON_ERROR);
        file_put_contents($this->schemaFile, json_encode($schema, JSON_THROW_ON_ERROR));
        config(['video.screenplay.schema_path' => $this->schemaFile]);
        $author = $this->app->make(ScreenplayAuthor::class);
        $author->assertSchemaMatchesContract();
        // A later read must not replace the validated document with different bytes.
        file_put_contents($this->schemaFile, '{}');
        $this->assertSame($schema, (new \ReflectionMethod($author, 'schema'))->invoke($author));
        $store->shouldReceive('claimProjectStage')->once()->andReturn([null, null, 'claimed_by_other']);

        $this->assertSame([null, 'screenplay_running'], $service->authorScreenplay('guard-project'));
    }

    private function serviceWithProfile(array $profile): array
    {
        $this->profileDirectory = sys_get_temp_dir().'/screenplay-guard-'.Str::uuid();
        mkdir($this->profileDirectory);
        file_put_contents($this->profileDirectory.'/guard.json', json_encode($profile, JSON_THROW_ON_ERROR));
        config(['video.screenplay.profile_dir' => $this->profileDirectory, 'video.screenplay.profiles.yacht' => 'guard']);

        $project = new VideoProject;
        $project->setRelation('article', (new Article)->setRelation('category', new Category(['slug' => 'yacht'])));
        $repository = Mockery::mock(VideoProjectRepositoryInterface::class);
        $repository->shouldReceive('getById')->once()->with('guard-project')->andReturn($project);
        $store = Mockery::mock(PlanningStageStore::class);
        $store->shouldReceive('latestOutputForProject')->once()
            ->with('guard-project', PlanningStageName::INSPIRATION)
            ->andReturn(['source_insights' => [['aspect' => 'design', 'summary' => 'Open spaces connect the vessel.']]]);
        $client = Mockery::mock(StructuredOutputLlmClient::class);
        $client->shouldNotReceive('create');
        $this->app->instance(VideoProjectRepositoryInterface::class, $repository);
        $this->app->instance(PlanningStageStore::class, $store);
        $this->app->forgetInstance(ScreenplayAuthor::class);
        $this->app->singleton(ScreenplayAuthor::class, static fn () => new ScreenplayAuthor(
            client: $client,
            promptDir: (string) config('video.screenplay.prompt_dir'),
            schemaPath: (string) config('video.screenplay.schema_path'),
            promptVersion: (string) config('video.screenplay.prompt_version'),
            model: (string) config('video.screenplay.model'),
            maxTokens: (int) config('video.screenplay.max_tokens'),
            contractVersion: (string) config('video.screenplay.contract_version'),
        ));
        $this->app->forgetInstance(VideoProjectService::class);

        return [$this->app->make(VideoProjectService::class), $store, $client];
    }
}
