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
        $this->assertStringContainsString('Render Anchor →', $html);
        $this->assertStringContainsString('TÁC VỤ NÀY TÍNH TIỀN', $html);
    }

    public function test_the_confirmation_names_the_estimate_and_calls_it_an_estimate(): void
    {
        // 0.041 la gia mot anh medium; o nay xin 2 anh.
        $this->cell(DesignImageStatus::CANDIDATE->value);
        $html = $this->screen();

        $this->assertStringContainsString('ước lượng $0.082', $html);
        $this->assertStringNotContainsString('thực tế $0.082', $html);
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

            $this->assertStringContainsString('Đang chờ worker', $html);
            $this->assertStringNotContainsString('Render Anchor →', $html);
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
        $this->assertStringContainsString('Render lại →', $html);
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

        $this->assertSame(2, substr_count($html, 'CANDIDATE '));
        $this->assertStringContainsString('/renders/design-images/'.$cell->id.'/1.png', $html);
        $this->assertStringContainsString(substr(hash('sha256', 'image 2'), 0, 12), $html);
        $this->assertStringNotContainsString('Render Anchor →', $html);
    }

    public function test_the_money_already_spent_comes_from_the_ledger_not_the_estimate(): void
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

        $this->assertStringContainsString('sổ cái ghi $0.0824', $this->screen());
    }

    public function test_pressing_render_puts_the_cell_in_the_queue(): void
    {
        $cell = $this->cell(DesignImageStatus::CANDIDATE->value);

        $this->from(route('video-projects.anchor', $this->project->id))
            ->post(route('video-projects.design-image-enqueue', [$this->project->id, $cell->id]))
            ->assertRedirect(route('video-projects.anchor', $this->project->id))
            ->assertSessionHas('success');

        $cell->refresh();

        $this->assertSame(DesignImageStatus::QUEUED->value, $cell->status);
        $this->assertNotNull($cell->queued_at);
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
        $this->assertStringContainsString('chưa nối', $html);
    }

    public function test_nothing_on_the_panel_looks_clickable_before_it_is_wired(): void
    {
        // Mot nut trong nhu that ma bam khong ra gi con te hon la khong co nut:
        // nguoi dung tuong minh da duyet anh roi. Duyet anh la viec cua 3.4.
        $this->cell(DesignImageStatus::RENDERED->value);
        $html = $this->screen();

        $this->assertStringNotContainsString('href=""', $html);
        $this->assertStringContainsString(
            'disabled title="Approval is step 3.4"', $html,
        );
        $this->assertSame(2, substr_count($html, 'Not connected yet'));
    }

    public function test_a_project_with_no_cell_says_so_instead_of_showing_nothing(): void
    {
        $this->assertStringContainsString('Chưa có ô nào', $this->screen());
    }
}
