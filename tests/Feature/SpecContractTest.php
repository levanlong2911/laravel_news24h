<?php

namespace Tests\Feature;

use App\Models\AiJsonSchema;
use App\Models\AiPhase;
use App\Models\AiPhaseSchema;
use App\Models\VideoProject;
use App\Video\Superyacht\Import\SpecImporter;
use App\Video\Superyacht\Spec\ActionContract;
use App\Video\Superyacht\Spec\PhaseResolver;
use App\Video\Superyacht\Spec\PhaseRevisions;
use App\Video\Superyacht\Spec\ResolvedPhase;
use App\Video\Superyacht\Spec\TaskContext;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class SpecContractTest extends TestCase
{
    use DatabaseTransactions;

    private const WORKFLOW = 'superyacht_v6_3';

    private const LINKED = ['phase-3', 'phase-4m', 'phase-6', 'phase-6c', 'phase-7', 'phase-7a', 'phase-8', 'phase-9q'];

    private const SHOT_HASH = 'sha256:ff40e2225231c6f5b3d1911018c813b72c90f94f2ddfd5847cdd35bb690cfe41';

    private string $yachtId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->yachtId = $this->category('yacht');
        $this->import('yacht');
    }

    public function test_the_link_preview_lists_seven_phases_and_writing_needs_the_reviewed_plan(): void
    {
        $plan = $this->revisions()->linkPlan('yacht');
        self::assertSame(self::LINKED, array_column($plan['changes'], 'phase_code'));
        self::assertSame(19, array_sum(array_map(static fn (array $change): int => count($change['links']), $plan['changes'])));

        $this->artisan('video:spec-link', ['--category' => 'yacht', '--dry-run' => true])
            ->expectsOutputToContain('8 phase sẽ có revision mới. plan_hash: '.$plan['plan_hash'])
            ->assertSuccessful();
        $this->artisan('video:spec-link', ['--category' => 'yacht'])->expectsOutputToContain('Thiếu --expected-plan')->assertFailed();
        $this->artisan('video:spec-link', ['--category' => 'yacht', '--expected-plan' => CanonicalJson::hashString('other')])->assertFailed();
        self::assertSame(0, AiPhaseSchema::query()->count());

        $this->artisan('video:spec-link', ['--category' => 'yacht', '--expected-plan' => $plan['plan_hash']])
            ->expectsOutputToContain('chưa tích hợp pipeline')
            ->assertSuccessful();

        foreach (self::LINKED as $code) {
            $revisions = AiPhase::query()->where('category_id', $this->yachtId)->where('phase_code', $code)->orderBy('revision')->get();
            self::assertSame([1, 2], $revisions->pluck('revision')->all(), $code);
            self::assertSame($revisions[0]->prompt_content, $revisions[1]->prompt_content, $code);
            self::assertSame([AiPhase::PUBLISHED, false, AiPhase::PUBLISHED, true, $revisions[0]->id], [
                $revisions[0]->status, $revisions[0]->is_current, $revisions[1]->status, $revisions[1]->is_current, $revisions[1]->based_on_id,
            ], $code);
        }

        self::assertSame(19, AiPhaseSchema::query()->count());
        self::assertSame(29, AiPhase::query()->where('category_id', $this->yachtId)->where('is_current', true)->count());
        $this->artisan('video:spec-link', ['--category' => 'yacht', '--dry-run' => true])->expectsOutputToContain('không có revision nào cần tạo')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_a_link_plan_reviewed_before_another_change_is_refused(): void
    {
        $plan = $this->revisions()->linkPlan('yacht');
        $phase4m = $this->current('phase-4m');
        $this->revisions()->publish($this->revisions()->saveDraft($phase4m, $phase4m->prompt_content."\nEdited.", null, 'edit', 'test'), $phase4m->id);

        try {
            $this->revisions()->applyLinkPlan('yacht', $plan['plan_hash'], 'test');
            self::fail('A stale link plan was written.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('not the reviewed', $e->getMessage());
        }

        self::assertSame(0, AiPhaseSchema::query()->count());
    }

    public function test_every_save_is_a_new_revision_and_publish_detects_a_stale_current(): void
    {
        $revisions = $this->revisions();
        $first = $this->current('phase-1');

        $draft = $revisions->saveDraft($first, 'Rewritten phase 1.', null, 'rewrite', 'test');
        self::assertSame([2, AiPhase::DRAFT, false, $first->id], [$draft->revision, $draft->status, $draft->is_current, $draft->based_on_id]);
        self::assertSame($first->id, $this->current('phase-1')->id);
        self::assertNull($draft->source_file);

        $second = $revisions->publish($draft, $first->id);
        self::assertSame($second->id, $this->current('phase-1')->id);
        self::assertSame([AiPhase::PUBLISHED, false], [$first->fresh()->status, $first->fresh()->is_current]);

        $stale = $revisions->saveDraft($first, 'Another edit made against revision 1.', null, 'stale', 'test');

        try {
            $revisions->publish($stale, $first->id);
            self::fail('A draft prepared against a replaced revision was published.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Conflict', $e->getMessage());
        }

        self::assertSame($second->id, $this->current('phase-1')->id);

        try {
            $revisions->publish($second, $second->id);
            self::fail('A published revision was published again.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('only a draft is published', $e->getMessage());
        }

        $restored = $revisions->restore($first, $second->id, 'restore revision 1', 'test');
        self::assertSame([4, $first->prompt_content, $first->id, true], [$restored->revision, $restored->prompt_content, $restored->based_on_id, $restored->is_current]);
        self::assertSame($first->source_file, $restored->source_file);
        self::assertSame(1, AiPhase::query()->where('category_id', $this->yachtId)->where('phase_code', 'phase-1')->where('is_current', true)->count());
    }

    public function test_published_rows_drafts_links_and_schemas_cannot_be_changed_in_place(): void
    {
        $this->link();
        $phase = $this->current('phase-3');
        $link = $phase->schemaLinks()->first();
        $schema = AiJsonSchema::query()->where('schema_key', 'Constraint')->sole();
        $draft = $this->revisions()->saveDraft($this->current('phase-1'), null, null, 'draft', 'test');

        $this->assertRefused(function () use ($phase): void {
            $phase->prompt_content = 'changed';
            $phase->save();
        });
        $this->assertRefused(fn () => $phase->delete());
        $this->assertRefused(function () use ($draft): void {
            $draft->prompt_content = 'changed';
            $draft->save();
        });
        $this->assertRefused(function () use ($link): void {
            $link->data_pointer = '/elsewhere';
            $link->save();
        });
        $this->assertRefused(fn () => $link->delete());
        $this->assertRefused(fn () => AiPhaseSchema::query()->create([
            'phase_id' => $phase->id, 'schema_id' => $schema->id, 'role' => 'input', 'slot_key' => 'extra',
            'data_pointer' => '', 'validation_mode' => 'whole', 'is_required' => false,
        ]));
        $this->assertRefused(function () use ($schema): void {
            $schema->name = 'changed';
            $schema->save();
        });
        $this->assertRefused(fn () => $schema->delete());

        self::assertSame('/data/canonical', $phase->schemaLinks()->where('slot_key', 'canonical')->value('data_pointer'));
    }

    public function test_publishing_refuses_a_revision_without_its_action_outputs_or_with_an_unpublished_schema(): void
    {
        $revisions = $this->revisions();
        $phase3 = $this->current('phase-3');
        $canonical = AiJsonSchema::query()->where('schema_key', 'CanonicalDesign')->sole();
        $constraint = AiJsonSchema::query()->where('schema_key', 'Constraint')->sole();
        $output = static fn (string $slot, string $schemaId, string $pointer, string $mode): array => [
            'role' => 'output', 'slot_key' => $slot, 'schema_id' => $schemaId, 'data_pointer' => $pointer, 'validation_mode' => $mode, 'is_required' => true,
        ];

        $partial = $revisions->saveDraft($phase3, null, [$output('canonical', $canonical->id, '/data/canonical', 'whole')], 'partial', 'test');
        $this->assertRefused(fn () => $revisions->publish($partial, $phase3->id), 'output slot constraints is missing');

        $draftSchemaId = (string) Str::uuid();
        DB::table('ai_json_schemas')->insert(['id' => $draftSchemaId, 'namespace' => 'shared_draft', 'status' => 'draft', 'published_at' => null]
            + collect($constraint->getAttributes())->except(['id', 'namespace', 'status', 'published_at'])->all());
        $unpublished = $revisions->saveDraft($phase3, null, [
            $output('canonical', $canonical->id, '/data/canonical', 'whole'),
            $output('constraints', $draftSchemaId, '/data/constraints', 'each_item'),
        ], 'draft schema', 'test');
        $this->assertRefused(fn () => $revisions->publish($unpublished, $phase3->id), 'not published');

        $this->assertRefused(fn () => $revisions->saveDraft($phase3, null, [$output('canonical', $canonical->id, 'data/canonical', 'whole')], 'bad pointer', 'test'), 'confirmed JSON Pointer');
        self::assertSame($phase3->id, $this->current('phase-3')->id);
    }

    public function test_the_resolver_returns_the_bundle_of_the_project_category_only(): void
    {
        $this->link();
        $cruiseId = $this->category('cruise');
        $this->import('cruise');
        config(['video.superyacht.contracts.categories.cruise' => self::WORKFLOW]);

        $yacht = $this->instructedResolver()->forProject((string) $this->projectInCategory('yacht')->id, 'spec.design');
        $manifest = $yacht->manifest();

        self::assertTrue($yacht->ready());
        self::assertSame(['yacht', $this->current('phase-3')->id, 2], [$manifest['category']['slug'], $manifest['phase']['id'], $manifest['phase']['revision']]);
        self::assertSame(['system-prompt', 'common-standards', 'phase-3'], array_column($yacht->parts(), 'code'));
        self::assertSame($this->current('common-standards')->prompt_content, $yacht->parts()[1]['content']);
        self::assertSame(['BuildState', 'CanonicalDesign', 'Constraint', 'LockRecord', 'ReviewDecision'], array_column($manifest['schemas'], 'key'));
        self::assertSame(['lock/design', 'output/canonical', 'output/constraints', 'review/decision'], array_map(static fn (array $link): string => $link['role'].'/'.$link['slot'], $manifest['links']));

        $cruise = $this->instructedResolver()->forProject((string) $this->projectInCategory('cruise')->id, 'spec.design');
        self::assertSame(
            [false, ['lock slot design is missing', 'output slot canonical is missing', 'output slot constraints is missing', 'review slot decision is missing'], $this->current('phase-3', $cruiseId)->id],
            [$cruise->ready(), $cruise->mismatches(), $cruise->manifest()['phase']['id']],
        );
        self::assertNotSame($yacht->bundleHash(), $cruise->bundleHash());

        $notReady = $cruise->checkOutput('{"phase":"3"}', null);
        self::assertSame([['CONTRACT-NOT-READY'], false], [$notReady['report']->codes(), $notReady['applicable']]);
        Http::assertNothingSent();
    }

    public function test_a_missing_or_unclear_context_stops_before_any_request(): void
    {
        $this->assertRefused(fn () => $this->resolver()->forProject((string) Str::uuid(), 'spec.design'), 'does not exist');
        $this->category('travel');
        $this->assertRefused(fn () => $this->resolver()->forProject((string) $this->projectInCategory('travel')->id, 'spec.design'), 'travel has no configured workflow');
        $this->assertRefused(fn () => $this->resolver()->forCategory($this->yachtId, 'spec.unknown'), 'is not configured');

        $base = config('video.superyacht.contracts')['workflows'][self::WORKFLOW]['actions']['spec.design'];
        self::assertSame('phase-3', $base['phase']);
        foreach ([
            'malformed' => ['instructions' => ['system-prompt', 'phase-3']] + $base,
            'executor ai needs a model profile' => ['executor' => 'ai'] + $base,
            'has executor "anthropic"; allowed: none, code, ai' => ['executor' => 'anthropic'] + $base,
            'executors none and code take none' => ['model_profile' => 'claude-sonnet'] + $base,
            '0 current revisions' => ['instructions' => ['no-such-section']] + $base,
        ] as $message => $action) {
            $contracts = config('video.superyacht.contracts');
            $contracts['workflows'][self::WORKFLOW]['actions']['spec.broken'] = $action;
            $this->assertRefused(fn () => (new PhaseResolver($contracts))->forCategory($this->yachtId, 'spec.broken'), $message);
        }

        Http::assertNothingSent();
    }

    public function test_a_manifest_replays_its_own_bundle_after_newer_revisions_and_config_changes(): void
    {
        $this->link();
        $revisions = $this->revisions();
        $bundle = $this->instructedResolver()->forCategory($this->yachtId, 'spec.design');
        $stored = json_decode(json_encode($bundle->manifest(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($bundle->bundleHash(), $this->instructedResolver()->forCategory($this->yachtId, 'spec.design')->bundleHash());
        self::assertSame($bundle->bundleHash(), $this->resolver()->fromManifest($stored)->bundleHash());

        $phase3 = $this->current('phase-3');
        $revisions->publish($revisions->saveDraft($phase3, $phase3->prompt_content."\nEdited.", null, 'edit', 'test'), $phase3->id);
        $afterPhase = $this->instructedResolver()->forCategory($this->yachtId, 'spec.design')->bundleHash();
        $standards = $this->current('common-standards');
        $revisions->publish($revisions->saveDraft($standards, $standards->prompt_content."\nEdited.", null, 'edit', 'test'), $standards->id);
        $afterStandards = $this->instructedResolver()->forCategory($this->yachtId, 'spec.design')->bundleHash();
        $contracts = $this->instructedContracts();
        $contracts['workflows'][self::WORKFLOW]['actions']['spec.design']['instructions'] = ['common-standards', 'system-prompt'];
        $afterOrder = (new PhaseResolver($contracts))->forCategory($this->yachtId, 'spec.design')->bundleHash();

        self::assertCount(4, array_unique([$bundle->bundleHash(), $afterPhase, $afterStandards, $afterOrder]));

        $replayed = (new PhaseResolver($contracts))->fromManifest($stored);
        self::assertSame($bundle->bundleHash(), $replayed->bundleHash());
        self::assertSame(array_column($bundle->parts(), 'content'), array_column($replayed->parts(), 'content'));
        self::assertSame(['system-prompt', 'common-standards', 'phase-3'], array_column($replayed->parts(), 'code'));

        $tampered = $stored;
        $tampered['phase']['revision'] = 99;
        $this->assertRefused(fn () => $this->resolver()->fromManifest($tampered), 'does not match its own bundle_hash');

        $otherCompiler = $stored;
        $otherCompiler['compiler_version'] = 'spec-contract-0';
        $otherCompiler['bundle_hash'] = CanonicalJson::hash($otherCompiler, ['bundle_hash']);
        $this->assertRefused(fn () => $this->resolver()->fromManifest($otherCompiler), 'replays only '.ResolvedPhase::COMPILER_VERSION);
    }

    public function test_an_output_link_that_keeps_the_slot_name_but_breaks_the_contract_is_never_published(): void
    {
        $this->link();
        $revisions = $this->revisions();
        $phase3 = $this->current('phase-3');
        $constraint = AiJsonSchema::query()->where('schema_key', 'Constraint')->sole();
        $copyId = (string) Str::uuid();
        DB::table('ai_json_schemas')->insert(['id' => $copyId, 'namespace' => 'shared'] + collect(AiJsonSchema::query()->where('schema_key', 'CanonicalDesign')->sole()->getAttributes())->except(['id', 'namespace'])->all());

        foreach ([
            'points to superyacht/Constraint@6.3.0; the contract names superyacht/CanonicalDesign@6.3.0' => ['schema_id' => $constraint->id],
            'points to shared/CanonicalDesign@6.3.0' => ['schema_id' => $copyId],
            'is optional; the contract makes it required' => ['is_required' => false],
            'reads "/data/design"; the contract names "/data/canonical"' => ['data_pointer' => '/data/design'],
            'is checked each_item; the contract names whole' => ['validation_mode' => 'each_item'],
        ] as $message => $change) {
            $links = $this->linksOf($phase3, static fn (array $link): array => $link['slot_key'] === 'canonical' ? $change + $link : $link);
            $draft = $revisions->saveDraft($phase3, null, $links, $message, 'test');
            $this->assertRefused(fn () => $revisions->publish($draft, $phase3->id), "output slot canonical {$message}");
        }

        $lockOptional = $this->linksOf($phase3, static fn (array $link): array => $link['slot_key'] === 'design' ? ['is_required' => false] + $link : $link);
        $draft = $revisions->saveDraft($phase3, null, $lockOptional, 'optional lock', 'test');
        $this->assertRefused(fn () => $revisions->publish($draft, $phase3->id), 'lock slot design is optional; the contract makes it required');

        $extra = [...$this->linksOf($phase3), [
            'role' => 'output', 'slot_key' => 'model_sheet', 'schema_id' => $constraint->id,
            'data_pointer' => '/data/model_sheet', 'validation_mode' => 'each_item', 'is_required' => false,
        ]];
        $draft = $revisions->saveDraft($phase3, null, $extra, 'extra output', 'test');
        $this->assertRefused(fn () => $revisions->publish($draft, $phase3->id), "output slot model_sheet is not part of this action's contract");

        self::assertSame($phase3->id, $this->current('phase-3')->id);
        self::assertTrue($this->resolver()->forCategory($this->yachtId, 'spec.design')->ready());
    }

    public function test_a_contract_changes_only_with_its_config_and_the_bundle_hash_follows(): void
    {
        $this->link();
        $before = $this->resolver()->forCategory($this->yachtId, 'spec.design');
        $contracts = config('video.superyacht.contracts');
        $contracts['workflows'][self::WORKFLOW]['actions']['spec.design']['outputs']['constraints']['mode'] = 'whole';
        $contracts['workflows'][self::WORKFLOW]['actions']['spec.design']['contract_version'] = '3';
        $revisions = new PhaseRevisions($contracts);
        $phase3 = $this->current('phase-3');
        $links = $this->linksOf($phase3, static fn (array $link): array => $link['slot_key'] === 'constraints' ? ['validation_mode' => 'whole'] + $link : $link);
        $revisions->publish($revisions->saveDraft($phase3, null, $links, 'constraints as one object, contract 2', 'test'), $phase3->id);

        $stale = $this->resolver()->forCategory($this->yachtId, 'spec.design');
        self::assertSame([false, ['output slot constraints is checked whole; the contract names each_item']], [$stale->ready(), $stale->mismatches()]);

        $after = (new PhaseResolver($contracts))->forCategory($this->yachtId, 'spec.design');
        self::assertTrue($after->ready());
        self::assertSame('3', $after->manifest()['action']['contract_version']);
        self::assertNotSame($before->bundleHash(), $after->bundleHash());

        $output = $this->designOutput('p', [json_decode((string) file_get_contents(config('video.superyacht.schema_dir').'/fixtures/Constraint/valid_P1_hull_doors.json'))]);
        self::assertTrue($before->checkOutput($output, 'p')['applicable']);
        self::assertSame(['SCHEMA-Constraint'], $after->checkOutput($output, 'p')['report']->codes());
    }

    public function test_a_bundle_checks_output_only_for_the_project_it_was_resolved_for(): void
    {
        $this->link();
        $projectA = (string) $this->projectInCategory('yacht')->id;
        $projectB = (string) $this->projectInCategory('yacht')->id;
        $bundleA = $this->resolver()->forProject($projectA, 'spec.design');
        $constraints = [json_decode((string) file_get_contents(config('video.superyacht.schema_dir').'/fixtures/Constraint/valid_P1_hull_doors.json'))];

        self::assertTrue($bundleA->checkOutput($this->designOutput($projectA, $constraints))['applicable']);
        self::assertSame(['ENVELOPE'], $bundleA->checkOutput($this->designOutput($projectB, $constraints))['report']->codes());
        self::assertSame(['CONTRACT-PROJECT'], $bundleA->checkOutput($this->designOutput($projectB, $constraints), $projectB)['report']->codes());
        self::assertTrue($bundleA->checkOutput($this->designOutput($projectA, $constraints), $projectA)['applicable']);

        $byCategory = $this->resolver()->forCategory($this->yachtId, 'spec.design');
        $unbound = $byCategory->checkOutput($this->designOutput($projectA, $constraints));
        self::assertSame([['CONTRACT-PROJECT'], false], [$unbound['report']->codes(), $unbound['applicable']]);
        self::assertTrue($byCategory->checkOutput($this->designOutput($projectA, $constraints), $projectA)['applicable']);
    }

    public function test_a_declared_stage_checks_only_its_own_output_slots(): void
    {
        $this->link();
        $project = (string) $this->projectInCategory('yacht')->id;
        $bundle = $this->resolver()->forProject($project, 'spec.design');
        $draft = json_decode($this->designOutput($project, []), true);
        unset($draft['data']['constraints']);
        $draft = (string) json_encode($draft, JSON_PRESERVE_ZERO_FRACTION);

        self::assertSame(['draft' => ['canonical'], 'lock' => ['canonical', 'constraints']], $bundle->manifest()['action']['stages']);
        self::assertTrue($bundle->checkOutput($draft, null, 'draft')['applicable']);
        self::assertSame(['CONTRACT-MISSING'], $bundle->checkOutput($draft, null, 'lock')['report']->codes());
        self::assertSame(['CONTRACT-MISSING'], $bundle->checkOutput($draft)['report']->codes());
        self::assertSame(['CONTRACT-STAGE'], $bundle->checkOutput($draft, null, 'final')['report']->codes());

        $contracts = config('video.superyacht.contracts');
        $contracts['workflows'][self::WORKFLOW]['actions']['spec.design']['stages'] = ['draft' => ['canonical', 'shots']];
        $this->assertRefused(fn () => (new PhaseResolver($contracts))->forProject($project, 'spec.design'), 'must list distinct output slots');
    }

    public function test_phase_6c_compiles_one_shot_per_task_bound_when_the_bundle_is_resolved(): void
    {
        $this->link();
        $projectA = (string) $this->projectInCategory('yacht')->id;
        $projectB = (string) $this->projectInCategory('yacht')->id;
        $shot = fn (string $id, string $project = ''): TaskContext => new TaskContext($project === '' ? $projectA : $project, 'shot_id', $id, 2, self::SHOT_HASH);

        $this->assertRefused(fn () => $this->resolver()->forProject($projectA, 'spec.prompt_compile'), 'processes one shot_id per task');
        $this->assertRefused(fn () => $this->resolver()->forProject($projectA, 'spec.prompt_compile', new TaskContext($projectA, 'room_id', 'INT03', 1, self::SHOT_HASH)), 'is keyed by shot_id');
        $this->assertRefused(fn () => $this->resolver()->forProject($projectA, 'spec.prompt_compile', $shot('SC07_SH02', $projectB)), "belongs to project {$projectB}");
        $this->assertRefused(fn () => $this->resolver()->forProject($projectA, 'spec.design', $shot('SC07_SH02')), 'takes no task context');
        $this->assertRefused(fn () => new TaskContext($projectA, 'shot_id', 'SC07_SH02', 0, self::SHOT_HASH), 'revision of at least 1');
        $this->assertRefused(fn () => new TaskContext($projectA, 'shot_id', 'SC07_SH02', 2, 'abc'), 'sha256:<64 hex>');

        $bundle = $this->resolver()->forProject($projectA, 'spec.prompt_compile', $shot('SC07_SH02'));
        $manifest = $bundle->manifest();
        self::assertTrue($bundle->ready());
        self::assertSame([$shot('SC07_SH02')->toArray(), '2', ['count' => 1, 'key' => 'shot_id']], [
            $manifest['context'], $manifest['action']['contract_version'], $manifest['action']['outputs']['compiled_shots']['items'],
        ]);
        self::assertSame(['lock/spec', 'output/compiled_shots', 'review/decision'], array_map(static fn (array $link): string => $link['role'].'/'.$link['slot'], $manifest['links']));

        $frozen = json_decode((string) file_get_contents(config('video.superyacht.schema_dir').'/fixtures/CompiledShot/valid_SC07_SH02_frozen.json'));
        $output = fn (string $project, array $shots): string => json_encode([
            'phase' => '6C', 'prompt_version' => '6.3.0', 'project_id' => $project, 'status' => 'ok', 'flags' => [], 'notes' => '',
            'data' => ['compiled_shots' => $shots],
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);

        self::assertTrue($bundle->checkOutput($output($projectA, [$frozen]))['applicable']);
        self::assertSame(['CONTRACT-ITEM-COUNT'], $bundle->checkOutput($output($projectA, []))['report']->codes());
        self::assertSame(['CONTRACT-PROJECT'], $bundle->checkOutput($output($projectB, [$frozen]), $projectB)['report']->codes());

        $other = $this->resolver()->forProject($projectA, 'spec.prompt_compile', $shot('SC08_SH01'));
        self::assertNotSame($bundle->bundleHash(), $other->bundleHash());
        self::assertSame(['CONTRACT-ITEM-KEY'], $other->checkOutput($output($projectA, [$frozen]))['report']->codes());

        $stored = json_decode(json_encode($manifest, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $replayed = $this->resolver()->fromManifest($stored);
        self::assertSame($bundle->bundleHash(), $replayed->bundleHash());
        self::assertSame(['CONTRACT-ITEM-KEY'], $replayed->checkOutput($output($projectA, [json_decode(str_replace('SC07_SH02', 'SC08_SH01', json_encode($frozen)))]))['report']->codes());
        Http::assertNothingSent();
    }

    public function test_items_and_match_are_part_of_the_contract_and_are_validated_when_configured(): void
    {
        $this->link();
        $context = new TaskContext('p', 'shot_id', 'SC07_SH02', 2, self::SHOT_HASH);
        $before = $this->resolver()->forCategory($this->yachtId, 'spec.prompt_compile', $context);
        $contracts = config('video.superyacht.contracts');
        $compile = $contracts['workflows'][self::WORKFLOW]['actions']['spec.prompt_compile'];

        $twoPerTask = $contracts;
        $twoPerTask['workflows'][self::WORKFLOW]['actions']['spec.prompt_compile']['outputs']['compiled_shots']['items']['count'] = 2;
        $twoPerTask['workflows'][self::WORKFLOW]['actions']['spec.prompt_compile']['contract_version'] = '3';
        self::assertNotSame($before->bundleHash(), (new PhaseResolver($twoPerTask))->forCategory($this->yachtId, 'spec.prompt_compile', $context)->bundleHash());

        $otherLock = $contracts;
        $otherLock['workflows'][self::WORKFLOW]['actions']['spec.prompt_compile']['records']['lock/spec']['match'] = ['/lock_type' => ['spec', 'pacing']];
        $otherLock['workflows'][self::WORKFLOW]['actions']['spec.prompt_compile']['contract_version'] = '3';
        self::assertNotSame($before->bundleHash(), (new PhaseResolver($otherLock))->forCategory($this->yachtId, 'spec.prompt_compile', $context)->bundleHash());

        $output = $compile['outputs']['compiled_shots'];
        $record = $compile['records']['lock/spec'];
        foreach ([
            'items needs mode each_item' => ['outputs' => ['compiled_shots' => ['mode' => 'whole'] + $output]],
            'positive integer count' => ['outputs' => ['compiled_shots' => ['items' => ['count' => 0, 'key' => 'shot_id']] + $output]],
            'needs exactly namespace' => ['outputs' => ['compiled_shots' => ['match' => ['/shot_id' => ['x']]] + $output]],
            'non-empty JSON Pointer' => ['records' => ['lock/spec' => ['match' => ['' => ['spec']]] + $record]],
            'non-empty list of scalar values' => ['records' => ['lock/spec' => ['match' => ['/lock_type' => []]] + $record]],
            'must be named lock/<slot> or review/<slot>' => ['records' => ['input/spec' => $record]],
            'unknown keys [freeze]' => ['freeze' => true],
        ] as $message => $change) {
            $this->assertRefused(fn () => ActionContract::normalise('spec.prompt_compile', $change + $compile), $message);
        }

        $duplicate = $contracts['workflows'][self::WORKFLOW];
        $duplicate['links'][] = ['phase' => 'phase-6c', 'role' => 'lock', 'slot' => 'spec'] + array_intersect_key($record, array_flip(['namespace', 'schema', 'version', 'pointer', 'mode', 'required', 'source']));
        $this->assertRefused(fn () => ActionContract::desiredLinks($duplicate), 'which an action already declares');
    }

    public function test_lock_and_review_records_are_checked_against_their_match_whenever_given(): void
    {
        $this->link();
        $design = $this->resolver()->forCategory($this->yachtId, 'spec.design');
        $lock = json_decode((string) file_get_contents(config('video.superyacht.schema_dir').'/fixtures/LockRecord/valid_design_lock.json'), true);

        self::assertSame([], $design->validateSlot('lock', 'design', json_encode($lock))->codes());

        $withoutType = $lock;
        unset($withoutType['lock_type']);
        $report = $design->validateSlot('lock', 'design', json_encode($withoutType));
        self::assertSame(['SCHEMA-LockRecord', 'CONTRACT-MATCH'], $report->codes());
        self::assertStringContainsString('/lock_type, which is missing', implode(' ', $report->messages()));

        $review = json_decode((string) file_get_contents(config('video.superyacht.schema_dir').'/fixtures/ReviewDecision/valid_design_lock_by_human.json'), true);
        self::assertSame([], $design->validateSlot('review', 'decision', json_encode(['item_type' => 'model_sheet'] + $review))->codes());
        self::assertSame(['CONTRACT-MATCH'], $design->validateSlot('review', 'decision', json_encode(['item_type' => 'compiled_shot'] + $review))->codes());
    }

    /**
     * @param  (callable(array<string, mixed>): array<string, mixed>)|null  $change
     * @return list<array<string, mixed>>
     */
    private function linksOf(AiPhase $phase, ?callable $change = null): array
    {
        return $phase->schemaLinks()->get()
            ->map(static fn (AiPhaseSchema $link): array => $link->only(['role', 'slot_key', 'schema_id', 'data_pointer', 'validation_mode', 'is_required']))
            ->map($change ?? static fn (array $link): array => $link)
            ->values()
            ->all();
    }

    /** @param list<mixed> $constraints */
    private function designOutput(string $projectId, array $constraints): string
    {
        return json_encode([
            'phase' => '3', 'prompt_version' => '6.3.0', 'project_id' => $projectId, 'status' => 'ok', 'flags' => [], 'notes' => '',
            'data' => [
                'canonical' => json_decode((string) file_get_contents(config('video.superyacht.schema_dir').'/fixtures/CanonicalDesign/valid_locked_rev1.json')),
                'constraints' => $constraints,
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    public function test_the_committed_contract_fixtures_pass_only_once_the_contract_is_complete(): void
    {
        $this->artisan('video:spec-check-contracts', ['--category' => 'yacht'])->assertFailed();

        $this->link();
        $this->withoutMockingConsoleOutput();

        $code = \Illuminate\Support\Facades\Artisan::call('video:spec-check-contracts', ['--category' => 'yacht']);
        $output = \Illuminate\Support\Facades\Artisan::output();
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('Mọi tác vụ phát lại đúng hash', $output);
        Http::assertNothingSent();
    }

    private function link(): void
    {
        $plan = $this->revisions()->linkPlan('yacht');
        $this->revisions()->applyLinkPlan('yacht', $plan['plan_hash'], 'test');
    }

    private function revisions(): PhaseRevisions
    {
        return PhaseRevisions::fromConfig(config('video.superyacht'));
    }

    private function resolver(): PhaseResolver
    {
        return PhaseResolver::fromConfig(config('video.superyacht'));
    }

    /** @return array<string, mixed> */
    private function instructedContracts(): array
    {
        $contracts = config('video.superyacht.contracts');
        $contracts['workflows'][self::WORKFLOW]['actions']['spec.design']['instructions'] = ['system-prompt', 'common-standards'];

        return $contracts;
    }

    private function instructedResolver(): PhaseResolver
    {
        return new PhaseResolver($this->instructedContracts());
    }

    private function current(string $code, ?string $categoryId = null): AiPhase
    {
        return AiPhase::query()
            ->where('category_id', $categoryId ?? $this->yachtId)
            ->where('workflow_code', self::WORKFLOW)
            ->where('phase_code', $code)
            ->where('is_current', true)
            ->sole();
    }

    private function import(string $slug): void
    {
        $config = config('video.superyacht');
        $importer = new SpecImporter(
            $config['workflow_code'], $slug, $config['spec_path'], $config['spec_version'], $config['schema_namespace'],
            $config['schema_dir'], $config['schema_base_uri'], $config['schemas'], [], $config['schema_versions'],
        );
        $plan = $importer->plan();
        $importer->import($plan, $plan['spec']['sha256']);
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
        DB::table('keywords')->insert(['id' => $keywordId, 'name' => "{$slug} concept {$articleId}", 'category_id' => $categoryId, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('articles')->insert([
            'id' => $articleId, 'keyword_id' => $keywordId, 'category_id' => $categoryId,
            'source_url' => "https://example.test/spec/{$articleId}", 'source_url_hash' => md5("https://example.test/spec/{$articleId}"),
            'source_title' => 'A concept', 'title' => 'A concept', 'slug' => "concept-{$articleId}",
            'content' => 'A concept article.', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return VideoProject::query()->create(['title' => 'Spec contract fixture', 'article_id' => $articleId, 'project_type' => 'article_video']);
    }

    private function assertRefused(callable $action, string $fragment = ''): void
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            self::assertStringContainsString($fragment, $e->getMessage());

            return;
        }

        self::fail('The action was accepted; expected a refusal'.($fragment === '' ? '.' : " containing \"{$fragment}\"."));
    }
}
