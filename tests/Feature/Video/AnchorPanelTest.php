<?php

namespace Tests\Feature\Video;

use App\Enums\DesignImageStatus;
use App\Models\Admin;
use App\Models\VideoArtifact;
use App\Models\VideoCostEntry;
use App\Models\VideoDesignImage;
use App\Models\VideoProject;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnchorPanelTest extends TestCase
{
    use DatabaseTransactions;

    private VideoProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = VideoProject::create(['title' => 'TEST anchor panel '.uniqid()]);
        $this->actingAs(Admin::create([
            'name' => 'TEST panel admin '.uniqid(),
            'email' => 'test_panel_'.uniqid().'@example.com',
            'password' => bcrypt('secret'),
            'role_id' => DB::table('roles')->value('id'),
        ]));
    }

    private function cell(string $status, array $override = []): VideoDesignImage
    {
        return VideoDesignImage::create($override + [
            'project_id' => $this->project->id,
            'image_code' => 'panel_'.$status.'_'.uniqid(),
            'image_type' => 'identity_anchor',
            'prompt_spec_json' => [
                'prompt' => 'CAMERA: front three-quarter.',
                'model' => 'gpt-image-2',
                'quality' => 'medium',
                'size' => '1024x1536',
                'variations' => 2,
                'stage' => 'fabrication_geometry_anchor',
            ],
            'prompt_sha256' => hash('sha256', $status.uniqid()),
            'status' => $status,
            'revision' => 1,
        ]);
    }

    private function screen(): string
    {
        return $this->get(route('video-projects.anchor', $this->project->id))
            ->assertOk()
            ->getContent();
    }

    public function test_a_candidate_offers_the_button_that_spends_money(): void
    {
        $cell = $this->cell(DesignImageStatus::CANDIDATE->value);
        $html = $this->screen();

        $this->assertStringContainsString($cell->image_code, $html);
        $this->assertStringContainsString('Render Anchor', $html);
        $this->assertStringContainsString('gpt-image-2 render', $html);
    }

    public function test_a_legacy_cell_without_a_stage_still_renders_on_the_panel(): void
    {
        // O tao truoc khi co anchor stage khong mang `prompt_spec_json.stage`.
        // Panel phai doc duoc spec cu va cho render theo du lieu da luu.
        $cell = $this->cell(DesignImageStatus::CANDIDATE->value);
        $spec = $cell->prompt_spec_json;
        unset($spec['stage']);
        $cell->update(['prompt_spec_json' => $spec]);

        $html = $this->screen();

        $this->assertStringContainsString($cell->image_code, $html);
        $this->assertStringContainsString('Render Anchor', $html);
        $this->assertStringContainsString('1024x1536', $html);
    }

    public function test_the_confirmation_names_the_estimate_and_calls_it_an_estimate(): void
    {
        // 0.041 la gia mot anh medium; o nay xin 2 anh.
        $this->cell(DesignImageStatus::CANDIDATE->value);
        $html = $this->screen();

        $this->assertStringContainsString('$0.082', $html);
        $this->assertStringNotContainsString('Cost: $0.17', $html);
    }

    public function test_a_cell_in_the_queue_cannot_be_sent_again_from_the_screen(): void
    {
        foreach ([DesignImageStatus::QUEUED, DesignImageStatus::CLAIMED, DesignImageStatus::RENDERING] as $status) {
            $project = VideoProject::create(['title' => 'TEST live '.uniqid()]);
            VideoDesignImage::create([
                'project_id' => $project->id,
                'image_code' => 'live_'.$status->value.'_'.uniqid(),
                'image_type' => 'identity_anchor',
                'prompt_spec_json' => ['quality' => 'low', 'size' => '1024x1536', 'variations' => 1],
                'status' => $status->value,
                'revision' => 1,
                'queued_at' => now()->subMinutes(3),
            ]);

            $html = $this->get(route('video-projects.anchor', $project->id))->assertOk()->getContent();

            $this->assertStringContainsString('worker', $html);
            $this->assertStringNotContainsString('data-target="#confirmRender', $html);
        }
    }

    public function test_a_failure_shows_its_reason_word_for_word(): void
    {
        // Khong bao gio de o hong ma nguoi dung chi thay chu `failed`.
        $this->cell(DesignImageStatus::FAILED->value, [
            'render_error' => 'openai tra 500 sau 3 lan thu',
        ]);

        $html = $this->screen();

        $this->assertStringContainsString('openai tra 500 sau 3 lan thu', $html);
        $this->assertStringContainsString('Render', $html);
    }

    public function test_every_rendered_image_gets_its_own_card(): void
    {
        $cell = $this->cell(DesignImageStatus::RENDERED->value);

        foreach ([1, 2] as $index) {
            VideoArtifact::create([
                'project_id' => $this->project->id,
                'design_image_id' => $cell->id,
                'artifact_type' => 'image',
                'role' => 'candidate',
                'storage_disk' => 'public',
                'storage_path' => '/renders/design-images/'.$cell->id.'/'.$index.'.png',
                'sha256' => hash('sha256', 'image '.$index),
                'width' => 1024,
                'height' => 1536,
            ]);
        }

        $html = $this->screen();

        $this->assertSame(2, preg_match_all('/CANDIDATE \d+/', $html));
        $this->assertStringContainsString('/renders/design-images/'.$cell->id.'/1.png', $html);
        $this->assertStringContainsString('1024×1536', $html);
        $this->assertStringNotContainsString(substr(hash('sha256', 'image 2'), 0, 12), $html);
        $this->assertStringNotContainsString('Manual review', $html);
        $this->assertStringNotContainsString('data-target="#confirmRender', $html);
    }

    public function test_candidate_panel_hides_render_costs_after_the_image_exists(): void
    {
        $cell = $this->cell(DesignImageStatus::RENDERED->value);

        foreach ([0.0412, 0.0412] as $cost) {
            VideoCostEntry::create([
                'project_id' => $this->project->id,
                'entity_type' => 'design_image',
                'entity_id' => $cell->id,
                'stage' => 'render',
                'provider' => 'openai',
                'model' => 'gpt-image-2',
                'usage_type' => 'image',
                'quantity' => 1,
                'unit' => 'render',
                'cost_usd' => $cost,
            ]);
        }

        $html = $this->screen();

        $this->assertStringContainsString($cell->image_code, $html);
        $this->assertStringContainsString('Rendered', $html);
        $this->assertStringNotContainsString('$0.0824', $html);
        $this->assertStringNotContainsString('sổ cái', $html);
    }

    public function test_pressing_render_holds_the_cell_before_calling_the_provider(): void
    {
        // Cau dao `VIDEO_PYTHON_RUNNER=false` chan tien trinh Python, nen luot nay
        // ket thuc o `failed` — dung the: nguoi dung thay ly do va bam lai duoc.
        $cell = $this->cell(DesignImageStatus::CANDIDATE->value);

        $this->from(route('video-projects.anchor', $this->project->id))
            ->post(route('video-projects.design-image-enqueue', [$this->project->id, $cell->id]))
            ->assertRedirect(route('video-projects.anchor', $this->project->id))
            ->assertSessionHas('error');

        $cell->refresh();

        $this->assertSame(DesignImageStatus::FAILED->value, $cell->status);
        $this->assertStringContainsString('VIDEO_PYTHON_RUNNER', $cell->render_error);
        $this->assertContains($cell->status, DesignImageStatus::enqueueableValues());
    }

    public function test_the_prompt_box_sends_nothing_and_the_form_sends_only_its_hash(): void
    {
        // O prompt khong co `name`, nen trinh duyet khong gui gi tu no du no nam
        // trong <form>: chuoi that duoc doc tu preview trong DB. Cai duoc gui la
        // sha256 — vua du de tu choi mot ban preview da bi dung lai o cho khac,
        // ma khong cho phep trinh duyet quyet dinh noi dung ta tra tien.
        $this->cell(DesignImageStatus::CANDIDATE->value);
        $html = $this->screen();

        $this->assertStringContainsString('data-v="a-main"', $html);
        $this->assertMatchesRegularExpression('/<textarea[^>]*data-v="a-main"(?![^>]*\bname=)[^>]*>/', $html);
        $this->assertStringContainsString('name="prompt_sha256"', $html);
    }

    public function test_a_cell_belonging_to_another_project_is_refused(): void
    {
        // Day o cua du an khac vao hang doi la tieu tien cua nguoi khac.
        $other = VideoProject::create(['title' => 'TEST other '.uniqid()]);
        $cell = VideoDesignImage::create([
            'project_id' => $other->id,
            'image_code' => 'other_'.uniqid(),
            'image_type' => 'identity_anchor',
            'status' => DesignImageStatus::CANDIDATE->value,
            'revision' => 1,
        ]);

        $this->from(route('video-projects.anchor', $this->project->id))
            ->post(route('video-projects.design-image-enqueue', [$this->project->id, $cell->id]))
            ->assertSessionHas('error', __('messages.anchor_image_not_found'));

        $this->assertSame(DesignImageStatus::CANDIDATE->value, $cell->refresh()->status);
    }

    public function test_a_cell_that_already_has_an_image_cannot_be_rendered_again(): void
    {
        $cell = $this->cell(DesignImageStatus::RENDERED->value);

        $this->from(route('video-projects.anchor', $this->project->id))
            ->post(route('video-projects.design-image-enqueue', [$this->project->id, $cell->id]))
            ->assertSessionHas('error');

        $this->assertSame(DesignImageStatus::RENDERED->value, $cell->refresh()->status);
    }

    public function test_an_unknown_cell_is_refused_without_touching_anything(): void
    {
        $this->from(route('video-projects.anchor', $this->project->id))
            ->post(route('video-projects.design-image-enqueue', [$this->project->id, (string) Str::uuid()]))
            ->assertSessionHas('error', __('messages.anchor_image_not_found'));
    }

    public function test_the_panel_no_longer_shows_invented_data(): void
    {
        // Bang QA va reference summary truoc day la so lieu bia dat trong blade.
        $this->cell(DesignImageStatus::CANDIDATE->value);
        $html = $this->screen();

        $this->assertStringNotContainsString('Proportions match', $html);
        $this->assertStringNotContainsString('Cost: $0.17', $html);
        $this->assertStringContainsString('CANDIDATE IMAGES', $html);
    }

    public function test_nothing_on_the_panel_looks_clickable_before_it_is_wired(): void
    {
        // Mot nut trong nhu that ma bam khong ra gi con te hon la khong co nut:
        // nguoi dung tuong minh da duyet anh roi. Duyet anh la viec cua 3.4.
        $this->cell(DesignImageStatus::RENDERED->value);
        $html = $this->screen();

        $this->assertStringNotContainsString('href=""', $html);
        $this->assertStringContainsString('Approve as Canonical Anchor', $html);
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'disabled title='));
    }

    public function test_a_project_with_no_cell_says_so_instead_of_showing_nothing(): void
    {
        $this->assertStringContainsString('Generate Anchor', $this->screen());
    }
}
