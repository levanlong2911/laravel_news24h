<?php

namespace Tests\Feature\Video;

use App\Models\Article;
use App\Models\VideoProject;
use App\Models\VideoVisualIdentity;
use App\Services\Video\VisualIdentityStore;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VisualIdentityFreezeTest extends TestCase
{
    use DatabaseTransactions;

    private VideoProject $project;

    private VisualIdentityStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $article = Article::create([
            'keyword_id' => DB::table('keywords')->value('id'),
            'category_id' => DB::table('categories')->value('id'),
            'source_url' => 'https://example.com/'.uniqid(),
            'source_url_hash' => md5(uniqid('x', true)),
            'source_title' => 'TEST identity freeze source',
            'title' => 'TEST identity freeze '.uniqid(),
            'slug' => 'test-identity-freeze-'.uniqid(),
            'content' => 'noi dung test',
            'status' => 'pending',
        ]);

        $this->project = VideoProject::create([
            'title' => 'TEST identity freeze '.uniqid(),
            'article_id' => $article->id,
        ]);

        $this->store = new VisualIdentityStore;
    }

    /** @param array<string, mixed> $overrides */
    private function concept(array $overrides = []): array
    {
        return ['design_identity' => array_replace([
            'design_length_m' => 120.0,
            'design_beam_m' => 17.5,
            'length_to_beam_ratio' => 6.9,
            'bow' => ['stem' => 'near_plumb', 'rake_degrees' => 8.0],
            'hull_colour' => 'graphite grey satin',
        ], $overrides)];
    }

    public function test_freezing_a_concept_creates_the_first_revision(): void
    {
        $identity = $this->store->freezeFromConcept($this->project->id, $this->concept());

        $this->assertSame(1, $identity->version);
        $this->assertSame('subject', $identity->identity_type);
        $this->assertSame('master_vessel', $identity->name);
        $this->assertSame(64, strlen($identity->identity_hash));
    }

    public function test_the_freeze_leaves_the_lock_for_a_person_to_set(): void
    {
        $identity = $this->store->freezeFromConcept($this->project->id, $this->concept());

        $this->assertNull($identity->locked_at);
    }

    public function test_an_unchanged_identity_reuses_its_revision(): void
    {
        $first = $this->store->freezeFromConcept($this->project->id, $this->concept());
        $second = $this->store->freezeFromConcept($this->project->id, $this->concept());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, VideoVisualIdentity::where('project_id', $this->project->id)->count());
    }

    public function test_a_changed_identity_opens_a_new_revision(): void
    {
        $this->store->freezeFromConcept($this->project->id, $this->concept());
        $second = $this->store->freezeFromConcept($this->project->id, $this->concept(['length_to_beam_ratio' => 6.2]));

        $this->assertSame(2, $second->version);
        $this->assertSame(2, VideoVisualIdentity::where('project_id', $this->project->id)->count());
    }

    public function test_the_hash_ignores_the_order_the_model_wrote_the_keys_in(): void
    {
        $ordered = $this->concept();
        $shuffled = ['design_identity' => array_reverse($ordered['design_identity'], true)];

        $this->assertSame(
            $this->store->hash($ordered['design_identity']),
            $this->store->hash($shuffled['design_identity']),
        );

        $first = $this->store->freezeFromConcept($this->project->id, $ordered);
        $second = $this->store->freezeFromConcept($this->project->id, $shuffled);

        $this->assertSame($first->id, $second->id);
    }

    public function test_the_hash_ignores_key_order_inside_a_nested_slot(): void
    {
        $a = $this->concept(['bow' => ['stem' => 'near_plumb', 'rake_degrees' => 8.0]]);
        $b = $this->concept(['bow' => ['rake_degrees' => 8.0, 'stem' => 'near_plumb']]);

        $this->assertSame(
            $this->store->hash($a['design_identity']),
            $this->store->hash($b['design_identity']),
        );
    }

    public function test_the_frozen_row_holds_the_identity_and_nothing_else_from_the_concept(): void
    {
        $identity = $this->store->freezeFromConcept($this->project->id, $this->concept() + [
            'design_thesis' => 'One shell carries the whole hull.',
            'decisions' => [['aspect' => 'capacity', 'provenance' => 'invented', 'decision' => 'Six cabins.']],
        ]);

        $this->assertArrayHasKey('design_length_m', $identity->identity_json);
        $this->assertArrayNotHasKey('design_thesis', $identity->identity_json);
        $this->assertArrayNotHasKey('decisions', $identity->identity_json);
    }

    public function test_a_concept_without_an_identity_freezes_nothing(): void
    {
        $this->assertNull($this->store->freezeFromConcept($this->project->id, ['design_thesis' => 'x']));
        $this->assertNull($this->store->freezeFromConcept($this->project->id, ['design_identity' => []]));
    }

    public function test_the_latest_revision_is_the_one_offered(): void
    {
        $this->store->freezeFromConcept($this->project->id, $this->concept());
        $second = $this->store->freezeFromConcept($this->project->id, $this->concept(['design_beam_m' => 18.0]));

        $this->assertSame($second->id, $this->store->latestForProject($this->project->id)?->id);
    }

    /** @param array<string, mixed> $concept */
    private function compilePreview(array $concept, string $prompt = 'A compiled anchor prompt.'): array
    {
        $service = app(\App\Services\VideoProjectService::class);

        $service->storeAnchorPromptPreview(
            $this->project->id,
            \App\Enums\AnchorStage::cases()[0],
            \App\Video\Concept\Viewpoint::cases()[0],
            \App\Enums\ImageSize::cases()[0],
            $prompt,
            $concept,
        );

        return $service->anchorPromptPreview($this->project->id);
    }

    public function test_the_prompt_preview_records_the_identity_of_the_concept_it_compiled(): void
    {
        $preview = $this->compilePreview($this->concept());

        $identity = $this->store->latestForProject($this->project->id);

        $this->assertSame($identity->id, $preview['identity_id']);
        $this->assertSame($identity->identity_hash, $preview['identity_hash']);
        $this->assertSame(1, $preview['identity_version']);
    }

    public function test_compiling_a_preview_freezes_the_concept_it_used(): void
    {
        $this->assertNull($this->store->latestForProject($this->project->id));

        $preview = $this->compilePreview($this->concept());

        $this->assertNotNull($preview['identity_id']);
        $this->assertSame(1, VideoVisualIdentity::where('project_id', $this->project->id)->count());
    }

    public function test_recompiling_the_same_concept_does_not_open_a_second_revision(): void
    {
        $first = $this->compilePreview($this->concept());
        $second = $this->compilePreview($this->concept(), 'A recompiled anchor prompt.');

        $this->assertSame($first['identity_id'], $second['identity_id']);
        $this->assertSame(1, VideoVisualIdentity::where('project_id', $this->project->id)->count());
    }

    public function test_the_preview_stamps_the_concept_it_compiled_not_the_newest_identity(): void
    {
        $newer = $this->store->freezeFromConcept($this->project->id, $this->concept(['design_beam_m' => 18.0]));

        $preview = $this->compilePreview($this->concept());

        $this->assertNotSame($newer->id, $preview['identity_id']);
        $this->assertSame(2, $preview['identity_version']);
        $this->assertSame($this->store->hash($this->concept()['design_identity']), $preview['identity_hash']);
    }

    public function test_a_concept_rerun_between_compile_and_generate_does_not_move_the_image(): void
    {
        $service = app(\App\Services\VideoProjectService::class);

        $preview = $this->compilePreview($this->concept());
        $first = $this->store->latestForProject($this->project->id);

        $second = $this->store->freezeFromConcept($this->project->id, $this->concept(['design_beam_m' => 18.0]));
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($second->id, $this->store->latestForProject($this->project->id)->id);

        [$image] = $service->createAnchorImage(
            $this->project->id,
            'tester',
            $preview['prompt'],
            \App\Enums\AnchorStage::cases()[0],
            \App\Video\Concept\Viewpoint::cases()[0],
            \App\Enums\ImageSize::cases()[0],
            \App\Enums\ImageModel::cases()[0],
            \App\Enums\ImageQuality::cases()[0],
            \App\Enums\ImageVariations::cases()[0],
            [
                'identity_id' => $preview['identity_id'],
                'identity_hash' => $preview['identity_hash'],
                'identity_version' => $preview['identity_version'],
            ],
        );

        $this->assertSame($first->id, $image->identity_id);
        $this->assertSame($first->identity_hash, $image->prompt_spec_json['identity_hash']);
        $this->assertSame(1, $image->prompt_spec_json['identity_version']);
    }

    public function test_the_lineage_does_not_change_the_money_guard_hash(): void
    {
        $service = app(\App\Services\VideoProjectService::class);
        $args = [
            $this->project->id, 'tester', 'A compiled anchor prompt.',
            \App\Enums\AnchorStage::cases()[0], \App\Video\Concept\Viewpoint::cases()[0],
            \App\Enums\ImageSize::cases()[0], \App\Enums\ImageModel::cases()[0],
            \App\Enums\ImageQuality::cases()[0], \App\Enums\ImageVariations::cases()[0],
        ];

        [$bare] = $service->createAnchorImage(...$args);
        [$again, $reason] = $service->createAnchorImage(...[...$args, ['identity_id' => 'x', 'identity_hash' => 'y', 'identity_version' => 9]]);

        $this->assertSame('already_exists', $reason);
        $this->assertSame($bare->id, $again->id);
    }

    /** @param array<string, mixed> $designIdentity */
    private function runConceptStage(array $designIdentity): array
    {
        $stage = new \App\Models\VideoPlanningStage;
        $stage->id = (string) \Illuminate\Support\Str::uuid();
        $stage->project_id = $this->project->id;

        $concept = new \App\Video\Concept\CreativeConcept(
            'One shell carries the whole hull.',
            $designIdentity,
            [],
            [],
            new \App\Video\Concept\FormRelationships('a line', 'a rhythm', 'an integration'),
        );

        $renderPlan = $this->createMock(\App\Services\VideoRenderPlanService::class);
        $renderPlan->method('renderConceptStage')->willReturn(
            new \App\Video\Concept\ConceptDesignResult($concept, [], 1, '{"raw":true}'),
        );
        $renderPlan->method('lastUsage')->willReturn(null);

        $runner = new \App\Services\Video\ConceptStageRunner(
            $this->createMock(\App\Services\Video\PlanningStageStore::class),
            $renderPlan,
            $this->store,
        );

        return $runner->goToSonnet(
            $stage,
            'a-claim-token',
            new \App\Models\Article,
            new \App\Video\Inspiration\InspirationBrief([], 'a focus', [], []),
        );
    }

    public function test_a_succeeded_concept_stage_freezes_its_identity(): void
    {
        \Illuminate\Support\Facades\Log::spy();

        [$output, $reason] = $this->runConceptStage($this->concept()['design_identity']);

        $this->assertSame('ok', $reason);
        $this->assertSame(1, VideoVisualIdentity::where('project_id', $this->project->id)->count());
        $this->assertSame(
            $this->store->hash($output['design_identity']),
            $this->store->latestForProject($this->project->id)->identity_hash,
        );

        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('warning');
    }

    public function test_a_concept_stage_whose_freeze_finds_nothing_still_succeeds_but_warns(): void
    {
        \Illuminate\Support\Facades\Log::spy();

        [, $reason] = $this->runConceptStage([]);

        $this->assertSame('ok', $reason);
        $this->assertSame(0, VideoVisualIdentity::where('project_id', $this->project->id)->count());

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => $message === 'concept-stage: visual identity freeze skipped'
                && $context['project_id'] === $this->project->id);
    }

    public function test_the_hash_keeps_the_order_of_a_list_because_that_order_can_carry_meaning(): void
    {
        $this->assertNotSame(
            $this->store->hash(['zones' => ['bow', 'stern']]),
            $this->store->hash(['zones' => ['stern', 'bow']]),
        );
    }

    public function test_the_hash_still_sorts_an_object_nested_inside_a_list(): void
    {
        $this->assertSame(
            $this->store->hash(['features' => [['b' => 1, 'a' => 2], ['d' => 3]]]),
            $this->store->hash(['features' => [['a' => 2, 'b' => 1], ['d' => 3]]]),
        );
    }
}
