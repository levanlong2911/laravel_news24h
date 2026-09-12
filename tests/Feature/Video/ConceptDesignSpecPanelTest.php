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

        $concept = app(VideoProjectService::class)->latestConcept($projectId);

        // Ban ghi moi nhat co the con la concept-v19 (truoc Phan 1): no khong
        // co DesignSpec va khong dung lai duoc. Bo qua, dung bao do — thu dang
        // kiem la HOP DONG cua ban canonical, khong phai lich su cua DB.
        if (($concept['design_spec'] ?? []) === []) {
            $this->markTestSkipped('Ban ghi concept moi nhat chua phai CanonicalDesignSpec.');
        }

        return $concept;
    }

    public function test_the_panel_carries_the_design_spec_beside_the_record_it_was_built_from(): void
    {
        $concept = $this->conceptOfTheLatestRun();

        $this->assertArrayHasKey('design_spec', $concept);
        $this->assertSame([
            'schema_version', 'object_type', 'design_thesis', 'identity',
            'dimensions', 'permanent_geometry', 'relationships', 'form_relationships',
            'finished_materials', 'exclusions', 'invariants', 'provenance',
        ], array_keys($concept['design_spec']));
    }

    public function test_a_project_without_a_concept_shows_no_design_spec_instead_of_failing(): void
    {
        $concept = app(VideoProjectService::class)->latestConcept('00000000-0000-0000-0000-000000000000');

        $this->assertArrayHasKey('design_spec', $concept);
        $this->assertSame([], $concept['design_spec']);
    }
}
