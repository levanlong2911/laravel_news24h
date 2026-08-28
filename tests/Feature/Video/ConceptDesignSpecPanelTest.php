<?php

namespace Tests\Feature\Video;

use App\Services\VideoProjectService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConceptDesignSpecPanelTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array<string, mixed> */
    private function conceptOfTheLatestRun(): array
    {
        $projectId = (string) DB::table('video_planning_stages')
            ->where('stage', 'concept')
            ->where('status', 'succeeded')
            ->orderByDesc('created_at')
            ->value('project_id');

        if ($projectId === '') {
            $this->markTestSkipped('Chua co concept nao chay thanh cong de doi chieu.');
        }

        return app(VideoProjectService::class)->latestConcept($projectId);
    }

    public function test_the_panel_carries_the_design_spec_beside_the_record_it_was_built_from(): void
    {
        $concept = $this->conceptOfTheLatestRun();

        $this->assertArrayHasKey('design_spec', $concept);
        $this->assertSame([
            'schema_version', 'object_type', 'design_thesis', 'dimensions',
            'permanent_geometry', 'form_relationships', 'finished_materials', 'invariants',
        ], array_keys($concept['design_spec']));
    }

    /**
     * Man hinh phai cho thay CA HAI: ban trung thuc giu so model khai, ban xuat
     * mang so Laravel tinh. Neu hai cot nay bang nhau thi mot trong hai da bi
     * ghi de, va tinh doi chieu duoc bien mat.
     */
    public function test_the_exported_ratio_is_computed_while_the_record_keeps_what_the_model_said(): void
    {
        $concept = $this->conceptOfTheLatestRun();

        $length = $concept['json']['design_identity']['design_length_m'];
        $beam = $concept['json']['design_identity']['design_beam_m'];

        $this->assertSame(
            round($length / $beam, 3),
            $concept['design_spec']['dimensions']['length_to_beam_ratio'],
        );

        $this->assertSame(
            $concept['json']['design_identity']['length_to_beam_ratio'],
            $concept['identity']['length_to_beam_ratio'],
        );
    }

    public function test_a_project_without_a_concept_shows_no_design_spec_instead_of_failing(): void
    {
        $concept = app(VideoProjectService::class)->latestConcept('00000000-0000-0000-0000-000000000000');

        $this->assertArrayHasKey('design_spec', $concept);
        $this->assertSame([], $concept['design_spec']);
    }
}
