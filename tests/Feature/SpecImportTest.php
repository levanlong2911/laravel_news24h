<?php

namespace Tests\Feature;

use App\Models\AiJsonSchema;
use App\Models\AiPhase;
use App\Models\AiPhaseSchema;
use App\Models\VideoProject;
use App\Video\Superyacht\Import\SpecImporter;
use App\Video\Superyacht\Validation\SchemaValidator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Uri;
use Opis\JsonSchema\Validator;
use RuntimeException;
use Tests\TestCase;

class SpecImportTest extends TestCase
{
    use DatabaseTransactions;

    private const WORKFLOW = 'superyacht_v6_3';

    private ?string $temp = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category('yacht');
    }

    protected function tearDown(): void
    {
        if ($this->temp !== null) {
            foreach (glob($this->temp.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->temp);
        }

        parent::tearDown();
    }

    public function test_the_preview_cuts_the_spec_into_twenty_nine_verbatim_parts_and_writes_nothing(): void
    {
        $source = (string) file_get_contents(config('video.superyacht.spec_path'));
        $plan = $this->importer()->plan();
        $codes = array_column($plan['phases'], 'phase_code');

        self::assertCount(29, $plan['phases']);
        self::assertSame(['preamble' => 1, 'section' => 8, 'phase' => 20], array_count_values(array_column($plan['phases'], 'kind')));
        self::assertSame(['preamble', 'overview', 'system-prompt', 'common-standards', 'phase-0'], array_slice($codes, 0, 5));
        self::assertSame(['phase-10', 'studio-production-value', 'technical-architecture', 'operations', 'pilot-runs-and-acceptance', 'sources-for-tool-notes'], array_slice($codes, -6));
        self::assertNotContains('phase-3p', $codes);
        self::assertContains('phase-6c', $codes);
        self::assertSame(range(0, 28), array_column($plan['phases'], 'sort_order'));
        self::assertSame($source, implode('', array_column($plan['phases'], 'prompt_content')));
        self::assertSame(hash('sha256', $source), $plan['spec']['sha256']);
        self::assertSame([$this->category('yacht')], array_values(array_unique(array_column($plan['phases'], 'category_id'))));

        $phase6c = $plan['phases'][array_search('phase-6c', $codes, true)];
        self::assertSame(['3, 3R, 5, 5L, 6', '—', '**SPEC FREEZE** (automatic; UNCERTAIN → human)'], [$phase6c['needs'], $phase6c['parallel_with'], $phase6c['stop']]);
        self::assertNull($plan['phases'][0]['needs']);

        self::assertSame(config('video.superyacht.schemas'), array_column($plan['schemas'], 'schema_key'));
        foreach ($plan['schemas'] as $row) {
            self::assertSame(file_get_contents(config('video.superyacht.schema_dir').'/'.$row['schema_key'].'.json'), $row['schema_content']);
            self::assertSame($row['schema_key'].' (v6.3)', $row['name']);
            self::assertSame('superyacht', $row['namespace']);
        }
        $ids = array_column($plan['schemas'], 'id', 'schema_key');
        $dependencies = array_column($plan['schemas'], 'dependencies', 'schema_key');
        self::assertSame(['https://superyacht.local/schemas/BuildState' => $ids['BuildState']], $dependencies['CanonicalDesign']);
        self::assertSame([], $dependencies['BuildState']);

        self::assertSame([], $plan['links']);
        self::assertCount(8, $plan['pending']);

        $this->artisan('video:spec-import', ['--category' => 'yacht', '--dry-run' => true])->expectsOutputToContain('Chế độ xem trước: không ghi DB.')->assertSuccessful();
        $this->artisan('video:spec-import', ['--dry-run' => true])->expectsOutputToContain('Thiếu --category')->assertFailed();
        $this->assertPlanFails($this->importer(category: 'no-such-topic'), 'matches 0 rows');
        self::assertSame([0, 0, 0], $this->counts());
        Http::assertNothingSent();
    }

    public function test_the_import_stores_every_byte_and_refuses_a_second_run(): void
    {
        $source = (string) file_get_contents(config('video.superyacht.spec_path'));
        $sha = hash('sha256', $source);

        $this->artisan('video:spec-import', ['--category' => 'yacht', '--expected-spec-sha256' => $sha])
            ->expectsOutputToContain('Đã lưu tài liệu; chưa tích hợp pipeline.')
            ->assertSuccessful();

        $phases = AiPhase::query()->where('category_id', $this->category('yacht'))->where('workflow_code', self::WORKFLOW)->orderBy('sort_order')->get();
        self::assertCount(29, $phases);
        self::assertSame(20, $phases->where('kind', 'phase')->count());
        self::assertSame($source, $phases->pluck('prompt_content')->implode(''));
        self::assertTrue($phases->every(static fn (AiPhase $phase): bool => $phase->revision === 1
            && $phase->status === AiPhase::PUBLISHED
            && $phase->is_current
            && $phase->content_sha256 === hash('sha256', $phase->prompt_content)
            && $phase->source_file === 'resources/ai/superyacht/spec_v6_3_en.md'));

        $schemas = AiJsonSchema::query()->get()->keyBy('schema_key');
        self::assertCount(8, $schemas);
        foreach ($schemas as $key => $schema) {
            self::assertSame(file_get_contents(config('video.superyacht.schema_dir').'/'.$key.'.json'), $schema->schema_content);
            self::assertSame(['superyacht', config('video.superyacht.schema_versions.'.$schema->schema_key, '6.3.0')], [$schema->namespace, $schema->version]);
        }
        self::assertSame(['https://superyacht.local/schemas/BuildState' => $schemas['BuildState']->id], $schemas['CanonicalDesign']->dependencies);
        self::assertSame(19, AiPhaseSchema::query()->count());

        $this->artisan('video:spec-import', ['--category' => 'yacht', '--expected-spec-sha256' => $sha])
            ->expectsOutputToContain('Category/workflow đã có dữ liệu')
            ->assertFailed();
        self::assertSame([29, 8, 19], $this->counts());
        Http::assertNothingSent();
    }

    public function test_a_fresh_install_has_a_ready_design_contract_without_a_geometry_release(): void
    {
        $config = config('video.superyacht');
        $source = (string) file_get_contents($config['spec_path']);
        $this->artisan('video:spec-import', ['--category' => 'yacht', '--expected-spec-sha256' => hash('sha256', $source)])->assertSuccessful();

        self::assertFalse(AiJsonSchema::query()->where('schema_key', 'ProxyGeometry')->exists());
        self::assertFalse(AiPhase::query()->where('is_current', true)->where('phase_code', 'phase-3p')->exists());
        $bundle = \App\Video\Superyacht\Spec\PhaseResolver::fromConfig($config)->forCategory($this->category('yacht'), 'spec.design');
        self::assertTrue($bundle->ready());
        self::assertSame(['canonical'], $bundle->manifest()['action']['stages']['draft']);
    }

    public function test_writing_without_the_confirmed_hash_or_with_another_hash_stores_nothing(): void
    {
        $this->artisan('video:spec-import', ['--category' => 'yacht'])->expectsOutputToContain('Thiếu --expected-spec-sha256')->assertFailed();
        $this->artisan('video:spec-import', ['--category' => 'yacht', '--expected-spec-sha256' => str_repeat('0', 64)])->assertFailed();
        self::assertSame([0, 0, 0], $this->counts());

        $this->expectExceptionMessage('must be confirmed');
        $this->importer()->import($this->importer()->plan(), null);
    }

    public function test_a_plan_made_before_another_run_finished_is_refused_without_duplicates(): void
    {
        $importer = $this->importer();
        $first = $importer->plan();
        $second = $importer->plan();

        $importer->import($first, $first['spec']['sha256']);

        try {
            $importer->import($second, $second['spec']['sha256']);
            self::fail('A second import of the same category and workflow was accepted.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Not imported', $e->getMessage());
        }

        self::assertSame([29, 8, 0], $this->counts());
    }

    public function test_a_failure_inside_the_transaction_leaves_no_partial_rows(): void
    {
        $importer = $this->importer();
        $plan = $importer->plan();
        $plan['schemas'][7]['id'] = $plan['schemas'][0]['id'];

        try {
            $importer->import($plan, $plan['spec']['sha256']);
            self::fail('A duplicate schema id was stored.');
        } catch (QueryException $e) {
            self::assertStringContainsString('ai_json_schemas', $e->getMessage());
        }

        self::assertSame([0, 0, 0], $this->counts());
    }

    public function test_a_second_category_keeps_its_own_revisions_and_reuses_identical_schemas(): void
    {
        $yacht = $this->importer();
        $first = $yacht->plan();
        $yacht->import($first, $first['spec']['sha256']);

        $cruiseId = $this->category('cruise');
        $cruise = $this->importer(category: 'cruise');
        $plan = $cruise->plan();
        self::assertSame([], $cruise->existing($plan)['conflicts']);
        self::assertCount(8, $cruise->existing($plan)['reuse']);

        $report = $cruise->import($plan, $plan['spec']['sha256']);

        self::assertSame([8, 'cruise'], [$report['reused'], $report['category']]);
        self::assertSame(29, AiPhase::query()->where('category_id', $cruiseId)->where('workflow_code', self::WORKFLOW)->count());
        self::assertSame([58, 8, 0], $this->counts());
        self::assertSame(58, AiPhase::query()->whereNotNull('current_key')->distinct()->count('current_key'));
        self::assertSame(
            AiPhase::query()->where('category_id', $this->category('yacht'))->orderBy('sort_order')->pluck('prompt_content')->all(),
            AiPhase::query()->where('category_id', $cruiseId)->orderBy('sort_order')->pluck('prompt_content')->all(),
        );
    }

    public function test_a_stored_schema_with_different_content_stops_the_import_of_another_category(): void
    {
        $yacht = $this->importer();
        $first = $yacht->plan();
        $yacht->import($first, $first['spec']['sha256']);

        $this->category('cruise');
        $dir = $this->copySchemas();
        $this->rewrite($dir.'/Constraint.json', '"title": "Constraint (v6.3)"', '"title": "Constraint (v6.3)", "description": "edited"');
        $cruise = $this->importer(category: 'cruise', schemaDir: $dir);
        $plan = $cruise->plan();

        self::assertSame(['ai_json_schemas holds superyacht/Constraint@6.3.0 with different content'], $cruise->existing($plan)['conflicts']);

        try {
            $cruise->import($plan, $plan['spec']['sha256']);
            self::fail('A schema with the same namespace and version but other content was accepted.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('different content', $e->getMessage());
        }

        self::assertSame([29, 8, 0], $this->counts());
    }

    /** @return array<string, array{0: string}> */
    public static function unpublishedStatuses(): array
    {
        return ['draft' => ['draft'], 'retired' => ['retired']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unpublishedStatuses')]
    public function test_a_stored_schema_that_is_not_published_is_never_reused(string $status): void
    {
        $yacht = $this->importer();
        $first = $yacht->plan();
        $yacht->import($first, $first['spec']['sha256']);
        AiJsonSchema::query()->where('schema_key', 'Constraint')->update(['status' => $status]);

        $cruiseId = $this->category('cruise');
        $cruise = $this->importer(category: 'cruise');
        $plan = $cruise->plan();
        $state = $cruise->existing($plan);

        self::assertSame(["ai_json_schemas holds superyacht/Constraint@6.3.0 with status {$status}; only a published schema is reused"], $state['conflicts']);
        self::assertCount(7, $state['reuse']);

        try {
            $cruise->import($plan, $plan['spec']['sha256']);
            self::fail("A {$status} schema was reused for newly published phases.");
        } catch (RuntimeException $e) {
            self::assertStringContainsString("with status {$status}", $e->getMessage());
        }

        self::assertSame(0, AiPhase::query()->where('category_id', $cruiseId)->count());
        self::assertSame($status, AiJsonSchema::query()->where('schema_key', 'Constraint')->value('status'));
        self::assertSame(8, AiJsonSchema::query()->count());
    }

    public function test_rolling_back_is_refused_before_any_change_while_several_categories_share_a_phase_revision(): void
    {
        foreach (['yacht', 'cruise'] as $slug) {
            $this->category($slug);
            $importer = $this->importer(category: $slug);
            $plan = $importer->plan();
            $importer->import($plan, $plan['spec']['sha256']);
        }

        $this->assertRollbackRefused(['ai_phases (workflow_code, phase_code, revision): superyacht_v6_3/', 'ai_phases current (workflow_code, phase_code): superyacht_v6_3/']);
    }

    public function test_rolling_back_is_refused_before_any_change_while_two_namespaces_share_a_schema_version(): void
    {
        $importer = $this->importer();
        $plan = $importer->plan();
        $importer->import($plan, $plan['spec']['sha256']);
        $stored = AiJsonSchema::query()->where('schema_key', 'Constraint')->sole();
        DB::table('ai_json_schemas')->insert(['id' => (string) Str::uuid(), 'namespace' => 'other_film'] + collect($stored->getAttributes())->except(['id'])->all());

        $this->assertRollbackRefused(['ai_json_schemas (schema_key, version): Constraint@6.3.0']);
    }

    public function test_the_database_keeps_one_current_revision_per_category_and_guards_the_category(): void
    {
        $importer = $this->importer();
        $plan = $importer->plan();
        $importer->import($plan, $plan['spec']['sha256']);
        $yachtId = $this->category('yacht');
        $current = AiPhase::query()->where('category_id', $yachtId)->where('phase_code', 'phase-3')->sole();
        $copy = static fn (array $over): array => $over + collect($current->getAttributes())->except(['id', 'current_key'])->all();

        $this->assertRejected(1062, fn () => DB::table('ai_phases')->insert($copy(['id' => (string) Str::uuid(), 'revision' => 2])));
        $this->assertRejected(1452, fn () => DB::table('ai_phases')->insert($copy(['id' => (string) Str::uuid(), 'category_id' => (string) Str::uuid()])));
        $this->assertRejected(1451, fn () => DB::table('categories')->where('id', $yachtId)->delete());

        DB::table('ai_phases')->insert($copy(['id' => (string) Str::uuid(), 'revision' => 2, 'is_current' => false, 'status' => 'draft', 'published_at' => null, 'based_on_id' => $current->id]));
        self::assertSame(1, AiPhase::query()->where('category_id', $yachtId)->where('phase_code', 'phase-3')->where('is_current', true)->count());
    }

    public function test_two_namespaces_may_hold_the_same_schema_version(): void
    {
        $importer = $this->importer();
        $plan = $importer->plan();
        $importer->import($plan, $plan['spec']['sha256']);
        $stored = AiJsonSchema::query()->where('schema_key', 'Constraint')->sole();
        $copy = static fn (array $over): array => $over + collect($stored->getAttributes())->except(['id'])->all();

        DB::table('ai_json_schemas')->insert($copy(['id' => (string) Str::uuid(), 'namespace' => 'other_film']));
        $this->assertRejected(1062, fn () => DB::table('ai_json_schemas')->insert($copy(['id' => (string) Str::uuid()])));
        self::assertSame(2, AiJsonSchema::query()->where('schema_key', 'Constraint')->where('version', '6.3.0')->count());
    }

    public function test_a_project_reaches_its_current_phases_through_its_article_category(): void
    {
        $importer = $this->importer();
        $plan = $importer->plan();
        $importer->import($plan, $plan['spec']['sha256']);
        $this->category('cruise');
        $other = $this->importer(category: 'cruise');
        $otherPlan = $other->plan();
        $other->import($otherPlan, $otherPlan['spec']['sha256']);

        $project = $this->projectInCategory('yacht');
        $phases = AiPhase::query()
            ->where('category_id', $project->article->category_id)
            ->where('workflow_code', self::WORKFLOW)
            ->where('is_current', true)
            ->orderBy('sort_order')
            ->get();

        self::assertCount(29, $phases);
        self::assertSame(array_column($plan['phases'], 'id'), $phases->pluck('id')->all());
        self::assertSame('yacht', $phases->first()->category->slug);
    }

    public function test_a_missing_schema_an_unresolved_reference_or_an_ambiguous_id_stops_the_plan(): void
    {
        $names = config('video.superyacht.schemas');

        $this->assertPlanFails($this->importer(names: [...$names, 'Missing']), 'Source file not found');

        $dir = $this->copySchemas();
        $this->rewrite($dir.'/CanonicalDesign.json', '"$ref": "BuildState"', '"$ref": "Nowhere"');
        $this->assertPlanFails($this->importer(schemaDir: $dir), 'references Nowhere');

        $dir = $this->copySchemas();
        $this->rewrite($dir.'/ShotSpec.json', 'https://superyacht.local/schemas/ShotSpec', 'https://superyacht.local/schemas/CanonicalDesign');
        $this->assertPlanFails($this->importer(schemaDir: $dir), 'declare the same $id');

        $dir = $this->copySchemas();
        file_put_contents($dir.'/Constraint.json', '{');
        $this->assertPlanFails($this->importer(schemaDir: $dir), 'not valid JSON');

        $dir = $this->copySchemas();
        $this->rewrite($dir.'/LockRecord.json', 'https://superyacht.local/schemas/LockRecord', 'https://elsewhere.local/LockRecord');
        $this->assertPlanFails($this->importer(schemaDir: $dir), 'the configured URI is');

        self::assertSame([0, 0, 0], $this->counts());
    }

    public function test_a_heading_inside_a_code_fence_stays_in_its_section_and_the_pipeline_map_must_match(): void
    {
        $source = (string) file_get_contents(config('video.superyacht.spec_path'));
        $this->temp ??= $this->makeTemp();

        $fenced = $source."\n```text\n## Not a section\n```\n";
        file_put_contents($this->temp.'/fenced.md', $fenced);
        $plan = $this->importer(specPath: $this->temp.'/fenced.md')->plan();
        self::assertCount(29, $plan['phases']);
        self::assertStringEndsWith("## Not a section\n```\n", $plan['phases'][28]['prompt_content']);
        self::assertSame($fenced, implode('', array_column($plan['phases'], 'prompt_content')));

        $row = "| 9H | Opening lab | 9 | — | — |\n";
        self::assertStringContainsString($row, $source);
        file_put_contents($this->temp.'/unmapped.md', str_replace($row, '', $source));
        $this->assertPlanFails($this->importer(specPath: $this->temp.'/unmapped.md'), 'headings without a row [phase-9h]');
    }

    public function test_only_confirmed_links_are_stored(): void
    {
        $link = ['phase' => 'phase-3', 'role' => 'output', 'slot' => 'canonical', 'namespace' => 'superyacht', 'schema' => 'CanonicalDesign', 'version' => '6.3.0', 'pointer' => '/data/canonical', 'mode' => 'whole', 'required' => true, 'source' => 'test'];

        $this->assertPlanFails($this->importer(links: [['pointer' => null] + $link]), 'confirmed JSON Pointer');
        $this->assertPlanFails($this->importer(links: [['phase' => 'phase-99'] + $link]), 'does not contain');
        $this->assertPlanFails($this->importer(links: [['mode' => 'partial'] + $link]), 'unknown role or mode');
        $this->assertPlanFails($this->importer(links: [$link, $link]), 'repeats phase, role and slot');

        $importer = $this->importer(links: [$link]);
        $plan = $importer->plan();
        self::assertNotContains('CanonicalDesign@6.3.0', $plan['pending']);
        $importer->import($plan, $plan['spec']['sha256']);

        $stored = AiPhaseSchema::query()->sole();
        self::assertSame(['phase-3', 'yacht', 'CanonicalDesign', 'output', 'canonical', '/data/canonical', 'whole', true], [
            $stored->phase->phase_code, $stored->phase->category->slug, $stored->schema->schema_key, $stored->role,
            $stored->slot_key, $stored->data_pointer, $stored->validation_mode, $stored->is_required,
        ]);
        self::assertNotNull($stored->created_at);

        $this->category('cruise');
        $cruise = $this->importer(category: 'cruise', links: [$link]);
        $cruisePlan = $cruise->plan();
        $cruise->import($cruisePlan, $cruisePlan['spec']['sha256']);
        self::assertSame([$stored->schema_id], AiPhaseSchema::query()->distinct()->pluck('schema_id')->all());
        self::assertSame(2, AiPhaseSchema::query()->count());
    }

    public function test_schemas_read_from_the_database_judge_every_fixture_like_the_files(): void
    {
        $importer = $this->importer();
        $plan = $importer->plan();
        $importer->import($plan, $plan['spec']['sha256']);

        $fromDatabase = new Validator();
        $fromDatabase->setMaxErrors(50);
        foreach (AiJsonSchema::query()->where('namespace', 'superyacht')->get() as $schema) {
            self::assertTrue($fromDatabase->resolver()->registerRaw($schema->schema_content, $schema->json_schema_id));
        }
        $fromFiles = app(SchemaValidator::class);
        $fixtures = glob(config('video.superyacht.schema_dir').'/fixtures/*/*.json') ?: [];
        self::assertGreaterThanOrEqual(66, count($fixtures));

        foreach ($fixtures as $file) {
            $name = basename(dirname($file));
            $json = (string) file_get_contents($file);
            $result = $fromDatabase->validate(json_decode($json, false), Uri::parse($fromFiles->uri($name), true));
            $messages = [];
            if (! $result->isValid()) {
                foreach ((new ErrorFormatter())->format($result->error(), true) as $path => $lines) {
                    foreach ((array) $lines as $line) {
                        $messages[] = "[SCHEMA-{$name}] {$path}: {$line}";
                    }
                }
            }

            self::assertSame($fromFiles->validate($name, $json)->messages(), $messages, basename($file));
        }
    }

    /**
     * @param  list<string>|null  $names
     * @param  list<array<string, mixed>>  $links
     */
    private function importer(string $category = 'yacht', ?string $specPath = null, ?string $schemaDir = null, ?array $names = null, array $links = []): SpecImporter
    {
        $config = config('video.superyacht');

        return new SpecImporter(
            $config['workflow_code'],
            $category,
            $specPath ?? $config['spec_path'],
            $config['spec_version'],
            $config['schema_namespace'],
            $schemaDir ?? $config['schema_dir'],
            $config['schema_base_uri'],
            $names ?? $config['schemas'],
            $links,
            $config['schema_versions'],
        );
    }

    private function category(string $slug): string
    {
        $id = DB::table('categories')->where('slug', $slug)->value('id');

        if ($id === null) {
            $id = (string) Str::uuid();
            DB::table('categories')->insert(['id' => $id, 'name' => Str::title($slug), 'slug' => $slug, 'created_at' => now(), 'updated_at' => now()]);
        }

        return (string) $id;
    }

    private function projectInCategory(string $slug): VideoProject
    {
        $categoryId = $this->category($slug);
        $articleId = (string) Str::uuid();
        $keywordId = (string) Str::uuid();
        DB::table('keywords')->insert(['id' => $keywordId, 'name' => 'superyacht concept', 'category_id' => $categoryId, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('articles')->insert([
            'id' => $articleId, 'keyword_id' => $keywordId, 'category_id' => $categoryId,
            'source_url' => "https://example.test/spec/{$articleId}", 'source_url_hash' => md5("https://example.test/spec/{$articleId}"),
            'source_title' => 'A concept superyacht', 'title' => 'A concept superyacht', 'slug' => "concept-superyacht-{$articleId}",
            'content' => 'A concept superyacht article.', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return VideoProject::query()->create(['title' => 'Spec import fixture', 'article_id' => $articleId, 'project_type' => 'article_video']);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function counts(): array
    {
        return [
            AiPhase::query()->where('workflow_code', self::WORKFLOW)->count(),
            AiJsonSchema::query()->count(),
            AiPhaseSchema::query()->count(),
        ];
    }

    /** @param list<string> $fragments */
    private function assertRollbackRefused(array $fragments): void
    {
        $migration = require base_path('database/migrations/2026_10_21_120000_add_category_and_namespace_to_ai_phase_tables.php');

        try {
            $migration->down();
            self::fail('The rollback ran although the old unique keys cannot hold the stored rows.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Rollback refused', $e->getMessage());
            foreach ($fragments as $fragment) {
                self::assertStringContainsString($fragment, $e->getMessage());
            }
        }

        self::assertTrue(Schema::hasColumn('ai_phases', 'category_id'));
        self::assertTrue(Schema::hasColumn('ai_json_schemas', 'namespace'));
        self::assertSame(
            'if(`is_current`,concat(`category_id`,\'|\',`workflow_code`,\'|\',`phase_code`),NULL)',
            DB::selectOne("SELECT GENERATION_EXPRESSION g FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_phases' AND COLUMN_NAME = 'current_key'")->g,
        );
    }

    private function assertRejected(int $code, callable $write): void
    {
        try {
            $write();
        } catch (QueryException $e) {
            self::assertSame($code, $e->errorInfo[1] ?? null, $e->getMessage());

            return;
        }

        self::fail("The database accepted a write that should fail with {$code}.");
    }

    private function assertPlanFails(SpecImporter $importer, string $fragment): void
    {
        try {
            $importer->plan();
        } catch (RuntimeException $e) {
            self::assertStringContainsString($fragment, $e->getMessage());

            return;
        }

        self::fail("The plan was accepted; expected a failure containing \"{$fragment}\".");
    }

    private function copySchemas(): string
    {
        if ($this->temp !== null) {
            foreach (glob($this->temp.'/*') ?: [] as $file) {
                unlink($file);
            }
        }

        $this->temp ??= $this->makeTemp();

        foreach (glob(config('video.superyacht.schema_dir').'/*.json') ?: [] as $file) {
            copy($file, $this->temp.'/'.basename($file));
        }

        return $this->temp;
    }

    private function rewrite(string $file, string $search, string $replace): void
    {
        $content = (string) file_get_contents($file);
        self::assertStringContainsString($search, $content);
        file_put_contents($file, str_replace($search, $replace, $content));
    }

    private function makeTemp(): string
    {
        $dir = sys_get_temp_dir().'/spec-import-'.Str::random(10);
        mkdir($dir);

        return $dir;
    }
}
