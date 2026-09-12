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

    /**
     * Ba nhanh ban sac cua CanonicalDesignSpec — dung ba nhanh ma
     * freezeFromConcept() dong bang.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function concept(array $overrides = []): array
    {
        return array_replace_recursive([
            'dimensions' => [
                'length_m' => 120.0,
                'beam_m' => 17.5,
                'length_to_beam_ratio' => 6.857,
            ],
            'permanent_geometry' => [
                'bow' => ['stem' => 'near_plumb', 'rake_degrees' => 8.0],
            ],
            'finished_materials' => [
                'hull' => ['colour' => 'graphite grey satin'],
            ],
        ], $overrides);
    }

    /**
     * Phan ma freezeFromConcept() thuc su bam, tach rieng de test hash goi
     * duoc truc tiep.
     *
     * @param  array<string, mixed>  $concept
     * @return array<string, mixed>
     */
    private function identityOf(array $concept): array
    {
        return array_filter([
            'dimensions' => $concept['dimensions'] ?? null,
            'permanent_geometry' => $concept['permanent_geometry'] ?? null,
            'finished_materials' => $concept['finished_materials'] ?? null,
        ], static fn (mixed $branch): bool => is_array($branch) && $branch !== []);
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
        $second = $this->store->freezeFromConcept($this->project->id, $this->concept(['dimensions' => ['length_to_beam_ratio' => 6.2]]));

        $this->assertSame(2, $second->version);
        $this->assertSame(2, VideoVisualIdentity::where('project_id', $this->project->id)->count());
    }

    public function test_the_hash_ignores_the_order_the_model_wrote_the_keys_in(): void
    {
        $ordered = $this->concept();
        $shuffled = array_reverse($ordered, true);

        $this->assertSame(
            $this->store->hash($this->identityOf($ordered)),
            $this->store->hash($this->identityOf($shuffled)),
        );

        $first = $this->store->freezeFromConcept($this->project->id, $ordered);
        $second = $this->store->freezeFromConcept($this->project->id, $shuffled);

        $this->assertSame($first->id, $second->id);
    }

    public function test_the_hash_ignores_key_order_inside_a_nested_slot(): void
    {
        $a = $this->concept();
        $b = $this->concept();
        $b['permanent_geometry']['bow'] = ['rake_degrees' => 8.0, 'stem' => 'near_plumb'];

        $this->assertSame(
            $this->store->hash($this->identityOf($a)),
            $this->store->hash($this->identityOf($b)),
        );
    }

    public function test_the_frozen_row_holds_the_identity_and_nothing_else_from_the_concept(): void
    {
        $identity = $this->store->freezeFromConcept($this->project->id, $this->concept() + [
            'design_thesis' => ['text' => 'One shell carries the whole hull.', 'role' => 'soft_design_guidance'],
            'provenance' => [['target_path' => 'dimensions', 'origin' => 'invented', 'source_aspects' => []]],
            'invariants' => [['id' => 'I001', 'name' => 'x', 'source_path' => 'dimensions.length_m',
                'constraint_type' => 'exact_value', 'severity' => 'hard', 'visual_verification' => true]],
        ]);

        $this->assertArrayHasKey('dimensions', $identity->identity_json);
        $this->assertArrayNotHasKey('design_thesis', $identity->identity_json);
        $this->assertArrayNotHasKey('provenance', $identity->identity_json);
        $this->assertArrayNotHasKey('invariants', $identity->identity_json);
    }

    public function test_a_concept_without_an_identity_freezes_nothing(): void
    {
        $this->assertNull($this->store->freezeFromConcept($this->project->id, ['design_thesis' => ['text' => 'x']]));
        $this->assertNull($this->store->freezeFromConcept($this->project->id, ['dimensions' => []]));
    }

    public function test_the_latest_revision_is_the_one_offered(): void
    {
        $this->store->freezeFromConcept($this->project->id, $this->concept());
        $second = $this->store->freezeFromConcept($this->project->id, $this->concept(['dimensions' => ['beam_m' => 18.0]]));

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
            \App\Video\Concept\Handoff\CompiledAnchorPrompt::fromPython([
                'prompt' => $prompt,
                'prompt_hash' => hash('sha256', $prompt),
                'provider' => \App\Enums\ImageModel::GPT_IMAGE_2->provider(),
                'model' => \App\Enums\ImageModel::GPT_IMAGE_2->value,
            ], 'revision-for-test'),
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
        $newer = $this->store->freezeFromConcept($this->project->id, $this->concept(['dimensions' => ['beam_m' => 18.0]]));

        $preview = $this->compilePreview($this->concept());

        $this->assertNotSame($newer->id, $preview['identity_id']);
        $this->assertSame(2, $preview['identity_version']);
        $this->assertSame($this->store->hash($this->identityOf($this->concept())), $preview['identity_hash']);
    }

    public function test_a_concept_rerun_between_compile_and_generate_does_not_move_the_image(): void
    {
        $service = app(\App\Services\VideoProjectService::class);

        $preview = $this->compilePreview($this->concept());
        $first = $this->store->latestForProject($this->project->id);

        $second = $this->store->freezeFromConcept($this->project->id, $this->concept(['dimensions' => ['beam_m' => 18.0]]));
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
