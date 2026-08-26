<?php

namespace Tests\Feature\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\Admin;
use App\Models\Article;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sonnet tra ve nam nhanh, man hinh chi bay hai. Ba nhanh bi giau
 * (`form_relationships`, `signature_features`, `decisions`) lai dung la ba
 * nhanh ba phien ban prompt vua roi sua den — nen moi luot chay $0.027 deu
 * phai mo DB doc tay moi biet ket qua.
 */
class ConceptPanelTest extends TestCase
{
    use DatabaseTransactions;

    private VideoProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        $article = Article::create([
            'keyword_id' => DB::table('keywords')->value('id'),
            'category_id' => DB::table('categories')->value('id'),
            'source_url' => 'https://example.com/'.uniqid(),
            'source_url_hash' => md5(uniqid('x', true)),
            'source_title' => 'TEST concept panel source',
            'title' => 'TEST concept panel article '.uniqid(),
            'slug' => 'test-concept-panel-'.uniqid(),
            'content' => 'noi dung test',
            'status' => 'pending',
        ]);

        $this->project = VideoProject::create([
            'title' => 'TEST concept panel '.uniqid(),
            'article_id' => $article->id,
        ]);

        $this->actingAs(Admin::create([
            'name' => 'TEST concept panel admin '.uniqid(),
            'email' => 'test_concept_panel_'.uniqid().'@example.com',
            'password' => bcrypt('secret'),
            'role_id' => DB::table('roles')->value('id'),
        ]));

        $this->stage(PlanningStageName::INSPIRATION, ['source_insights' => []]);
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
            'input_hash' => hash('sha256', $name->value),
            'output_json' => $output,
        ]);
    }

    /** @return array<string, mixed> */
    private function concept(): array
    {
        return [
            'design_thesis' => 'One mass carries the whole silhouette.',
            'design_identity' => ['design_length_m' => 128.0, 'hull_colour' => 'graphite'],
            'form_relationships' => [
                'governing_line' => 'A single sheer runs bow to stern.',
                'massing_rhythm' => 'The volume swells amidships and is carved away aft.',
                'feature_integration' => 'Features are recessed into the envelope.',
            ],
            'signature_features' => [
                ['description' => 'A recessed bathing platform.', 'visible_from' => ['rear_three_quarter', 'side']],
            ],
            'decisions' => [
                ['aspect' => 'size_and_dimensions', 'provenance' => 'invented', 'decision' => 'Scaled to the editorial floor.'],
                ['aspect' => 'materials_and_finishes', 'provenance' => 'inspired', 'decision' => 'Brushed metal, as the source shows.'],
                ['aspect' => 'amenities', 'provenance' => 'invented', 'decision' => 'A sheltered aft lounge.'],
            ],
        ];
    }

    private function screen(): string
    {
        return $this->get(route('video-projects.anchor', $this->project->id))->assertOk()->getContent();
    }

    private function summaryOf(string $html): string
    {
        $panel = substr($html, (int) strpos($html, 'va-concept'));
        $firstDetails = strpos($panel, '<details');

        return $firstDetails === false ? $panel : substr($panel, 0, $firstDetails);
    }

    public function test_the_panel_shows_the_three_form_relationships(): void
    {
        // Day la noi bias hinh hoc hien ra. concept-v12 va v15 deu sua vao day,
        // ma man hinh chua bao gio bay no.
        $this->stage(PlanningStageName::CONCEPT, $this->concept());

        $html = $this->screen();

        $this->assertStringContainsString('A single sheer runs bow to stern.', $html);
        $this->assertStringContainsString('The volume swells amidships and is carved away aft.', $html);
        $this->assertStringContainsString('Features are recessed into the envelope.', $html);
    }

    public function test_the_panel_lists_a_signature_feature_with_the_views_it_reads_from(): void
    {
        $this->stage(PlanningStageName::CONCEPT, $this->concept());

        $html = $this->screen();

        $this->assertStringContainsString('A recessed bathing platform.', $html);
        $this->assertStringContainsString('rear three quarter', $html);
    }

    public function test_the_panel_counts_provenance_so_a_run_can_be_read_at_a_glance(): void
    {
        // Cau hoi cua ba phien ban prompt vua roi: co bao nhieu quyet dinh
        // `invented`. Dem tai day thi khong phai mo DB.
        $this->stage(PlanningStageName::CONCEPT, $this->concept());

        $panel = $this->summaryOf($this->screen());

        $this->assertStringContainsString('1 inspired', $panel);
        $this->assertStringContainsString('2 invented', $panel);
    }

    public function test_the_panel_shows_what_the_call_actually_cost(): void
    {
        $this->stage(PlanningStageName::CONCEPT, $this->concept(), [
            'model' => 'claude-sonnet-4-6',
            'instruction_version' => 'concept-v15',
            'tokens_in' => 2840,
            'tokens_out' => 1120,
            'cost_usd' => 0.0271,
        ]);

        $html = $this->screen();

        $this->assertStringContainsString('claude-sonnet-4-6', $html);
        $this->assertStringContainsString('concept-v15', $html);
        $this->assertStringContainsString('2,840', $html);
        $this->assertStringContainsString('1,120', $html);
        $this->assertStringContainsString('$0.0271', $html);
    }

    public function test_the_full_json_sits_behind_details_and_not_in_the_summary(): void
    {
        // `assertDontSee` se noi doi o day: <details> van nam trong HTML, chi bi
        // CSS giau. Nen khoa VI TRI — JSON phai o sau <details>, khong o tren.
        $this->stage(PlanningStageName::CONCEPT, $this->concept());

        $html = $this->screen();

        $this->assertStringContainsString('<pre class="cjson">', $html);
        $this->assertStringContainsString('design_thesis', $html);
        $this->assertStringNotContainsString('design_thesis', $this->summaryOf($html));
    }

    private function realConceptHash(): string
    {
        $service = app(\App\Services\VideoProjectService::class);
        $input = new \ReflectionMethod($service, 'conceptInput');
        $input->setAccessible(true);

        $store = app(\App\Services\Video\PlanningStageStore::class);
        $hash = new \ReflectionMethod($store, 'hash');
        $hash->setAccessible(true);

        return $hash->invoke($store, $input->invoke($service, $this->project->fresh(), ['source_insights' => []]));
    }

    public function test_a_project_with_no_concept_yet_offers_to_create(): void
    {
        $html = $this->screen();

        $this->assertStringContainsString('Creat Prompt', $html);
        $this->assertStringNotContainsString(route('video-projects.concept-rerun', $this->project->id), $html);
    }

    public function test_a_failed_concept_with_no_cached_success_offers_to_build_again(): void
    {
        $this->stage(PlanningStageName::CONCEPT, [], [
            'status' => VideoPlanningStageStatus::FAILED->value,
            'input_hash' => $this->realConceptHash(),
            'error_message' => 'Claude tra ve rong',
        ]);

        $html = $this->screen();

        $this->assertStringContainsString('Dựng lại concept', $html);
        $this->assertStringNotContainsString('Creat Prompt', $html);
        $this->assertStringContainsString(route('video-projects.concept', $this->project->id).'"', $html);
    }

    public function test_a_failed_concept_that_still_has_a_cached_success_offers_the_paid_rerun(): void
    {
        $hash = $this->realConceptHash();

        $this->stage(PlanningStageName::CONCEPT, $this->concept(), ['input_hash' => $hash]);
        $this->stage(PlanningStageName::CONCEPT, [], [
            'planning_revision' => 2,
            'status' => VideoPlanningStageStatus::FAILED->value,
            'input_hash' => $hash,
            'error_message' => 'Claude tra ve rong',
        ]);

        $html = $this->screen();

        $this->assertStringContainsString(route('video-projects.concept-rerun', $this->project->id), $html);
        $this->assertStringNotContainsString('Creat Prompt', $html);
    }

    public function test_a_succeeded_concept_with_the_same_brief_offers_the_paid_rerun(): void
    {
        $this->stage(PlanningStageName::CONCEPT, $this->concept(), ['input_hash' => $this->realConceptHash()]);

        $this->assertStringContainsString(
            route('video-projects.concept-rerun', $this->project->id),
            $this->screen(),
        );
    }
}
