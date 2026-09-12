<?php

namespace Tests\Feature\Video;

use App\Enums\DesignImageStatus;
use App\Enums\ImageSize;
use App\Models\Admin;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Models\VideoProject;
use App\Models\VideoRender;
use App\Services\Video\DesignImageStore;
use App\Video\Reference\IdentityPreservationPrompt;
use App\Video\Reference\ReferenceEnvironment;
use App\Video\Reference\ReferenceView;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReferenceViewRenderTest extends TestCase
{
    use DatabaseTransactions;

    private const ANCHOR_PROMPT = "ASSET TYPE\nBare fabrication hull.\n\nCAMERA / VIEW\nModerate front three-quarter view from the port bow.";

    private const ANCHOR_BYTES = 'approved-anchor-bytes';

    private VideoProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('video_artifacts');

        config([
            'video.render_mode' => 'direct',
            'video.openai_image.disk' => 'video_artifacts',
            'canonical_concept.openai.api_key' => 'test-key',
            'canonical_concept.openai.base_url' => 'https://api.openai.com',
        ]);

        $roleId = (string) \Illuminate\Support\Str::uuid();

        DB::table('roles')->insert(['id' => $roleId, 'name' => 'admin']);

        $this->project = VideoProject::create(['title' => 'TEST reference '.uniqid()]);

        $this->actingAs(Admin::create([
            'name' => 'TEST reference admin '.uniqid(),
            'email' => 'test_reference_'.uniqid().'@example.com',
            'password' => bcrypt('secret'),
            'role_id' => $roleId,
        ]));
    }

    private function url(): string
    {
        return route('video-projects.reference', $this->project->id);
    }

    /** @return array<string, string|int> */
    private function settings(array $override = []): array
    {
        return $override + [
            'view' => ReferenceView::PORT_SIDE->value,
            'environment' => ReferenceEnvironment::NEUTRAL_STUDIO->value,
            'model' => 'gpt-image-2',
            'quality' => 'low',
            'size' => '1536x1024',
            'variations' => 1,
        ];
    }

    private function anchorCell(string $status = 'rendered', ?string $prompt = null): VideoDesignImage
    {
        return VideoDesignImage::create([
            'project_id' => $this->project->id,
            'image_code' => 'anchor_'.uniqid(),
            'image_type' => 'identity_anchor',
            'prompt_spec_json' => [
                'prompt' => $prompt ?? self::ANCHOR_PROMPT,
                'model' => 'gpt-image-2',
                'quality' => 'low',
                'size' => '1536x1024',
                'variations' => 1,
            ],
            'prompt_sha256' => hash('sha256', uniqid('', true)),
            'status' => $status,
            'revision' => 1,
        ]);
    }

    private function artifactFor(VideoDesignImage $image, ?string $bytes = null, ?string $projectId = null): VideoArtifact
    {
        $bytes ??= self::ANCHOR_BYTES;
        $path = 'anchors/'.uniqid().'.png';

        Storage::disk('video_artifacts')->put($path, $bytes);

        return VideoArtifact::create([
            'project_id' => $projectId ?? $this->project->id,
            'design_image_id' => $image->id,
            'artifact_type' => 'image',
            'role' => 'candidate',
            'storage_disk' => 'video_artifacts',
            'storage_path' => $path,
            'mime_type' => 'image/png',
            'sha256' => hash('sha256', $bytes),
            'width' => 1536,
            'height' => 1024,
        ]);
    }

    private function approvedAnchor(?string $prompt = null): VideoDesignImage
    {
        $anchor = $this->anchorCell('rendered', $prompt);
        $artifact = $this->artifactFor($anchor);

        $anchor->forceFill([
            'selected_artifact_id' => $artifact->id,
            'status' => DesignImageStatus::APPROVED->value,
            'approved_at' => now(),
        ])->save();

        return $anchor->refresh();
    }

    private function asymmetricPng(): string
    {
        $canvas = imagecreatetruecolor(8, 4);
        imagefilledrectangle($canvas, 0, 0, 7, 3, imagecolorallocate($canvas, 240, 240, 240));
        imagefilledrectangle($canvas, 0, 0, 1, 3, imagecolorallocate($canvas, 10, 10, 10));

        ob_start();
        imagepng($canvas);
        $bytes = (string) ob_get_clean();
        imagedestroy($canvas);

        return $bytes;
    }

    private function fakeEditReturns(?string $bytes = null): void
    {
        $bytes ??= $this->asymmetricPng();

        Http::fake([
            '*/v1/images/edits' => Http::response([
                'created' => 1,
                'size' => '1536x1024',
                'quality' => 'low',
                'output_format' => 'png',
                'usage' => ['total_tokens' => 10],
                'data' => [['b64_json' => base64_encode($bytes)]],
            ], 200),
        ]);
    }

    private function referenceCell(
        VideoDesignImage $anchor,
        VideoArtifact $artifact,
        string $status = 'candidate',
    ): VideoDesignImage {
        return VideoDesignImage::create([
            'project_id' => $this->project->id,
            'image_code' => 'ref_'.uniqid(),
            'image_type' => 'reference_view',
            'slot_index' => ReferenceView::PORT_SIDE->slot(),
            'source_image_id' => $anchor->id,
            'prompt_spec_json' => [
                'prompt' => self::ANCHOR_PROMPT,
                'operation' => 'edit',
                'view_key' => ReferenceView::PORT_SIDE->value,
                'environment' => ReferenceEnvironment::NEUTRAL_STUDIO->value,
                'model' => 'gpt-image-2',
                'quality' => 'low',
                'size' => '1536x1024',
                'variations' => 1,
                'source_artifact_id' => $artifact->id,
                'source_artifact_sha256' => (string) $artifact->sha256,
            ],
            'prompt_sha256' => hash('sha256', uniqid('', true)),
            'status' => $status,
            'revision' => 1,
        ]);
    }

    public function test_a_rendered_reference_can_be_approved_from_its_own_route(): void
    {
        $anchor = $this->approvedAnchor();
        $cell = $this->referenceCell($anchor, $anchor->artifact, 'rendered');
        $artifact = $this->artifactFor($cell, 'port-bytes');

        $this->from($this->url())
            ->post(route('video-projects.reference-approve', $this->project->id), [
                'artifact_id' => (string) $artifact->id,
            ])
            ->assertSessionHas('success', __('messages.reference_approved'));

        $this->assertSame(DesignImageStatus::APPROVED->value, $cell->fresh()->status);
        $this->assertSame((string) $artifact->id, (string) $cell->fresh()->selected_artifact_id);
    }

    public function test_approving_one_view_leaves_another_slot_alone(): void
    {
        $anchor = $this->approvedAnchor();

        $port = $this->referenceCell($anchor, $anchor->artifact, 'rendered');
        $portArtifact = $this->artifactFor($port, 'port-bytes');

        $bow = $this->referenceCell($anchor, $anchor->artifact, 'rendered');
        $bow->forceFill(['slot_index' => ReferenceView::BOW_FRONT->slot()])->save();
        $bowArtifact = $this->artifactFor($bow, 'bow-bytes');

        foreach ([$bowArtifact, $portArtifact] as $artifact) {
            $this->post(route('video-projects.reference-approve', $this->project->id), [
                'artifact_id' => (string) $artifact->id,
            ]);
        }

        $this->assertSame(DesignImageStatus::APPROVED->value, $port->fresh()->status);
        $this->assertSame(DesignImageStatus::APPROVED->value, $bow->fresh()->status);
    }

    public function test_the_reference_route_cannot_approve_the_anchor(): void
    {
        $anchor = $this->approvedAnchor();

        $this->from($this->url())
            ->post(route('video-projects.reference-approve', $this->project->id), [
                'artifact_id' => (string) $anchor->artifact->id,
            ])
            ->assertSessionHas('error', __('messages.reference_wrong_image_type'));
    }

    public function test_the_approve_control_sits_in_the_overlay_beside_the_status(): void
    {
        $anchor = $this->approvedAnchor();
        $cell = $this->referenceCell($anchor, $anchor->artifact, 'rendered');
        $this->artifactFor($cell, 'port-bytes');

        $html = $this->get($this->url())->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<span class="act">.*?Duyệt.*?<\/span>/su',
            $html,
            'the button must ride the .cap overlay, not the card flow where .meta covers it',
        );
    }

    public function test_a_rendered_reference_never_wears_the_approved_colour(): void
    {
        $anchor = $this->approvedAnchor();
        $cell = $this->referenceCell($anchor, $anchor->artifact, 'rendered');
        $this->artifactFor($cell, 'port-bytes');

        $html = $this->get($this->url())->assertOk()->getContent();

        $this->assertStringContainsString('class="blue">Rendered', $html);
        $this->assertStringNotContainsString('class="ok">Rendered', $html);
        $this->assertStringNotContainsString('va-tag ok">Rendered', $html);
    }

    public function test_an_approved_reference_wears_the_approved_colour(): void
    {
        $anchor = $this->approvedAnchor();
        $cell = $this->referenceCell($anchor, $anchor->artifact, 'rendered');
        $artifact = $this->artifactFor($cell, 'port-bytes');

        $this->post(route('video-projects.reference-approve', $this->project->id), [
            'artifact_id' => (string) $artifact->id,
        ]);

        $html = $this->get($this->url())->assertOk()->getContent();

        $this->assertStringContainsString('class="ok">Approved', $html);
        $this->assertStringNotContainsString('class="blue">Approved', $html);
    }

    public function test_a_live_status_is_not_painted_as_approved(): void
    {
        $anchor = $this->approvedAnchor();
        $cell = $this->referenceCell($anchor, $anchor->artifact, 'rendered');
        $this->artifactFor($cell, 'port-bytes');

        $cell->forceFill(['status' => DesignImageStatus::QUEUED->value])->save();

        $html = $this->get($this->url())->assertOk()->getContent();

        $this->assertStringContainsString('class="amber">Queued', $html);
        $this->assertStringNotContainsString('class="ok">Queued', $html);
    }

    public function test_the_same_view_in_two_environments_makes_two_cells(): void
    {
        $this->approvedAnchor();
        $this->fakeEditReturns();

        $this->from($this->url())->post($this->url(), $this->settings());
        $this->from($this->url())->post($this->url(), $this->settings([
            'environment' => ReferenceEnvironment::SHIPYARD_HALL->value,
        ]));

        $cells = VideoDesignImage::query()
            ->where('project_id', $this->project->id)
            ->where('image_type', 'reference_view')
            ->get();

        $this->assertCount(2, $cells);
        $this->assertCount(2, $cells->pluck('prompt_sha256')->unique());
        $this->assertCount(1, $cells->pluck('slot_index')->unique());
    }

    public function test_the_environment_never_lands_in_the_physical_state_column(): void
    {
        $this->approvedAnchor();
        $this->fakeEditReturns();

        $this->from($this->url())->post($this->url(), $this->settings([
            'environment' => ReferenceEnvironment::SHIPYARD_HALL->value,
        ]));

        $cell = VideoDesignImage::query()
            ->where('project_id', $this->project->id)
            ->where('image_type', 'reference_view')
            ->firstOrFail();

        $this->assertNull($cell->state);
        $this->assertSame(
            ReferenceEnvironment::SHIPYARD_HALL->value,
            $cell->prompt_spec_json['environment'],
        );
    }

    public function test_the_environment_block_only_rides_along_when_it_is_not_neutral(): void
    {
        $this->approvedAnchor();
        $this->fakeEditReturns();

        $this->from($this->url())->post($this->url(), $this->settings());
        $this->from($this->url())->post($this->url(), $this->settings([
            'environment' => ReferenceEnvironment::SHIPYARD_HALL->value,
        ]));

        $prompts = VideoDesignImage::query()
            ->where('project_id', $this->project->id)
            ->where('image_type', 'reference_view')
            ->get()
            ->mapWithKeys(fn (VideoDesignImage $cell) => [
                $cell->prompt_spec_json['environment'] => (string) $cell->prompt_spec_json['prompt'],
            ]);

        $block = ReferenceEnvironment::SHIPYARD_HALL->override();

        $this->assertStringNotContainsString($block, $prompts[ReferenceEnvironment::NEUTRAL_STUDIO->value]);
        $this->assertStringContainsString($block, $prompts[ReferenceEnvironment::SHIPYARD_HALL->value]);
    }

    public function test_the_page_never_ships_the_anchor_geometry_prompt(): void
    {
        $this->approvedAnchor();

        $html = $this->get($this->url())->assertOk()->getContent();

        $this->assertSame(0, substr_count($html, 'Bare fabrication hull'));
        $this->assertStringContainsString('authoritative record of this object', $html);
    }

    public function test_a_tampered_anchor_file_is_stopped_on_the_rerender_path(): void
    {
        $anchor = $this->approvedAnchor();
        $cell = $this->referenceCell($anchor, $anchor->artifact);

        Storage::disk('video_artifacts')->put($anchor->artifact->storage_path, 'tampered-bytes');
        Http::fake();

        $response = $this->from($this->url())
            ->post(route('video-projects.design-image-enqueue', [$this->project->id, $cell->id]));

        $response->assertSessionHas('error');
        Http::assertNothingSent();
        $this->assertSame(DesignImageStatus::FAILED->value, $cell->refresh()->status);
        $this->assertStringContainsString('checksum', (string) $cell->render_error);
    }

    public function test_a_reference_spec_without_a_recorded_sha_never_reaches_the_provider(): void
    {
        $anchor = $this->approvedAnchor();
        $cell = $this->referenceCell($anchor, $anchor->artifact);

        $spec = $cell->prompt_spec_json;
        unset($spec['source_artifact_sha256']);
        $cell->update(['prompt_spec_json' => $spec]);

        Http::fake();

        $this->from($this->url())
            ->post(route('video-projects.design-image-enqueue', [$this->project->id, $cell->id]));

        Http::assertNothingSent();
        $this->assertSame(DesignImageStatus::FAILED->value, $cell->refresh()->status);
    }

    public function test_an_untouched_anchor_file_passes_the_rerender_checksum_gate(): void
    {
        $anchor = $this->approvedAnchor();
        $cell = $this->referenceCell($anchor, $anchor->artifact);

        $this->fakeEditReturns();

        $this->from($this->url())
            ->post(route('video-projects.design-image-enqueue', [$this->project->id, $cell->id]));

        Http::assertSentCount(1);
        $this->assertSame(DesignImageStatus::RENDERED->value, $cell->refresh()->status);
    }

    public function test_a_duplicate_view_reports_a_sentence_not_a_reason_code(): void
    {
        $this->approvedAnchor();
        $this->fakeEditReturns();

        $this->from($this->url())->post($this->url(), $this->settings());
        $response = $this->from($this->url())->post($this->url(), $this->settings());

        $response->assertSessionMissing('error');
        $response->assertSessionHas('success', fn (string $message): bool => $message !== 'already_exists'
            && str_contains($message, 'already holds'));
    }

    public function test_approving_an_anchor_lands_on_the_reference_page(): void
    {
        $anchor = $this->anchorCell();
        $artifact = $this->artifactFor($anchor);

        $response = $this->from(route('video-projects.anchor', $this->project->id))
            ->post(route('video-projects.anchor-approve', $this->project->id), [
                'artifact_id' => $artifact->id,
            ]);

        $response->assertRedirect($this->url());
    }

    public function test_a_failed_approval_stays_on_the_anchor_page(): void
    {
        $anchor = $this->anchorCell('candidate');
        $artifact = $this->artifactFor($anchor);

        $response = $this->from(route('video-projects.anchor', $this->project->id))
            ->post(route('video-projects.anchor-approve', $this->project->id), [
                'artifact_id' => $artifact->id,
            ]);

        $response->assertRedirect(route('video-projects.anchor', $this->project->id));
        $response->assertSessionHas('error');
    }

    public function test_opening_the_reference_page_never_reaches_the_provider(): void
    {
        Http::fake();
        $this->approvedAnchor();

        $this->get($this->url())->assertOk();

        Http::assertNothingSent();
    }

    public function test_the_page_opens_before_any_anchor_is_approved(): void
    {
        Http::fake();

        $this->get($this->url())->assertOk();

        Http::assertNothingSent();
    }

    public function test_a_reference_is_refused_while_no_anchor_is_approved(): void
    {
        Http::fake();

        $response = $this->from($this->url())->post($this->url(), $this->settings());

        $response->assertSessionHas('error', __('messages.no_approved_anchor'));
        Http::assertNothingSent();
        $this->assertSame(0, VideoDesignImage::query()
            ->where('project_id', $this->project->id)
            ->where('image_type', 'reference_view')
            ->count());
    }

    public function test_a_rewritten_anchor_file_is_stopped_by_the_checksum_gate(): void
    {
        Http::fake();
        $anchor = $this->approvedAnchor();

        Storage::disk('video_artifacts')->put($anchor->artifact->storage_path, 'tampered-bytes');

        $response = $this->from($this->url())->post($this->url(), $this->settings());

        $response->assertSessionHas('error', __('messages.artifact_checksum_mismatch'));
        Http::assertNothingSent();
    }

    public function test_a_missing_anchor_file_is_stopped_before_the_call(): void
    {
        Http::fake();
        $anchor = $this->approvedAnchor();

        Storage::disk('video_artifacts')->delete($anchor->artifact->storage_path);

        $response = $this->from($this->url())->post($this->url(), $this->settings());

        $response->assertSessionHas('error', __('messages.artifact_file_not_found'));
        Http::assertNothingSent();
    }

    public function test_the_edits_endpoint_is_the_one_that_gets_called(): void
    {
        $this->approvedAnchor();
        $this->fakeEditReturns();

        $this->from($this->url())->post($this->url(), $this->settings());

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/images/edits'));
    }

    public function test_a_vertical_canvas_reaches_the_provider_and_stays_out_of_the_prompt(): void
    {
        $this->approvedAnchor();
        $this->fakeEditReturns();

        $this->from($this->url())->post($this->url(), $this->settings([
            'size' => ImageSize::VERTICAL_2K->value,
        ]));

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $body = (string) $request->body();

            return str_ends_with($request->url(), '/v1/images/edits')
                && str_contains($body, 'name="size"')
                && str_contains($body, ImageSize::VERTICAL_2K->value);
        });

        $spec = VideoDesignImage::query()
            ->where('project_id', $this->project->id)
            ->where('image_type', 'reference_view')
            ->firstOrFail()
            ->prompt_spec_json;

        $this->assertSame(ImageSize::VERTICAL_2K->value, $spec['size']);

        foreach (['1152', '2048', '9:16', 'vertical', 'portrait'] as $canvasWord) {
            $this->assertStringNotContainsStringIgnoringCase(
                $canvasWord,
                (string) $spec['prompt'],
                'the canvas is a render parameter and must never appear in the prompt text',
            );
        }
    }

    public function test_the_prompt_that_goes_out_is_a_delta_not_the_whole_geometry(): void
    {
        $this->approvedAnchor();
        $this->fakeEditReturns();

        $this->from($this->url())->post($this->url(), $this->settings());

        $cell = VideoDesignImage::query()
            ->where('project_id', $this->project->id)
            ->where('image_type', 'reference_view')
            ->firstOrFail();

        $sent = (string) $cell->prompt_spec_json['prompt'];

        $this->assertStringContainsString(IdentityPreservationPrompt::text(), $sent);
        $this->assertStringContainsString(ReferenceView::PORT_SIDE->cameraOverride(), $sent);
        $this->assertStringNotContainsString('Bare fabrication hull', $sent);
        $this->assertLessThan(3000, mb_strlen($sent));
    }

    public function test_a_reference_row_records_its_derivation_and_lock_slots(): void
    {
        $this->approvedAnchor();
        $this->fakeEditReturns();

        $this->from($this->url())->post($this->url(), $this->settings());

        $spec = VideoDesignImage::query()
            ->where('project_id', $this->project->id)
            ->where('image_type', 'reference_view')
            ->firstOrFail()
            ->prompt_spec_json;

        $this->assertSame('gpt_edit', $spec['derivation']);
        $this->assertSame(IdentityPreservationPrompt::VERSION, $spec['derivation_version']);
        $this->assertNull($spec['identity_lock_id']);
        $this->assertNull($spec['identity_lock_hash']);
    }

    public function test_the_mirror_view_never_reaches_the_provider(): void
    {
        $this->approvedAnchor();
        $this->fakeEditReturns();
        $this->from($this->url())->post($this->url(), $this->settings());

        Http::fake();

        $this->from($this->url())->post($this->url(), $this->settings([
            'view' => ReferenceView::STARBOARD_SIDE->value,
        ]));

        Http::assertNothingSent();

        $cell = VideoDesignImage::query()
            ->where('project_id', $this->project->id)
            ->where('image_type', 'reference_view')
            ->where('slot_index', ReferenceView::STARBOARD_SIDE->slot())
            ->firstOrFail();

        $this->assertSame(DesignImageStatus::RENDERED->value, $cell->status);
        $this->assertSame('horizontal_flip', $cell->prompt_spec_json['derivation']);
        $this->assertSame('mirror', $cell->prompt_spec_json['operation']);
        $this->assertSame(1, $cell->prompt_spec_json['variations']);
        $this->assertCount(1, $cell->artifacts);
    }

    public function test_the_mirror_ledger_row_traces_back_to_the_source_render(): void
    {
        $this->approvedAnchor();
        $this->fakeEditReturns();
        $this->from($this->url())->post($this->url(), $this->settings());

        $portCell = VideoDesignImage::query()
            ->where('project_id', $this->project->id)
            ->where('slot_index', ReferenceView::PORT_SIDE->slot())
            ->firstOrFail();
        $portRender = VideoRender::query()->where('design_image_id', $portCell->id)->firstOrFail();

        Http::fake();
        $this->from($this->url())->post($this->url(), $this->settings([
            'view' => ReferenceView::STARBOARD_SIDE->value,
        ]));

        $mirrorCell = VideoDesignImage::query()
            ->where('project_id', $this->project->id)
            ->where('slot_index', ReferenceView::STARBOARD_SIDE->slot())
            ->firstOrFail();
        $mirror = VideoRender::query()->where('design_image_id', $mirrorCell->id)->firstOrFail();

        $this->assertSame('reference_mirror', $mirror->render_kind);
        $this->assertSame('local', $mirror->provider);
        $this->assertSame('image', $mirror->source_kind);
        $this->assertSame($portRender->id, $mirror->source_render_id);
        $this->assertSame(0.0, (float) $mirror->cost_usd);
        $this->assertStringContainsString('horizontal_flip of artifact', (string) $mirror->sent_prompt);
        $this->assertStringContainsString(IdentityPreservationPrompt::VERSION, (string) $mirror->sent_prompt);
        $this->assertSame('horizontal_flip', $mirror->request_json['derivation']);
        $this->assertSame(
            (string) $portCell->artifacts->first()->sha256,
            $mirror->request_json['source_artifact_sha256'],
        );
    }

    public function test_the_mirrored_bytes_differ_from_the_source(): void
    {
        $this->approvedAnchor();
        $this->fakeEditReturns($this->asymmetricPng());
        $this->from($this->url())->post($this->url(), $this->settings());

        Http::fake();
        $this->from($this->url())->post($this->url(), $this->settings([
            'view' => ReferenceView::STARBOARD_SIDE->value,
        ]));

        $shas = VideoDesignImage::query()
            ->where('project_id', $this->project->id)
            ->where('image_type', 'reference_view')
            ->with('artifacts')
            ->get()
            ->flatMap(fn (VideoDesignImage $cell) => $cell->artifacts->pluck('sha256'))
            ->unique();

        $this->assertCount(2, $shas);
    }

    public function test_a_reference_row_carries_its_type_slot_and_source(): void
    {
        $anchor = $this->approvedAnchor();
        $this->fakeEditReturns();

        $this->from($this->url())->post($this->url(), $this->settings([
            'view' => ReferenceView::STERN_REAR->value,
        ]));

        $cell = VideoDesignImage::query()
            ->where('project_id', $this->project->id)
            ->where('image_type', 'reference_view')
            ->firstOrFail();

        $this->assertSame(ReferenceView::STERN_REAR->slot(), $cell->slot_index);
        $this->assertSame($anchor->id, $cell->source_image_id);
        $this->assertSame($anchor->artifact->id, $cell->prompt_spec_json['source_artifact_id']);
    }

    public function test_a_second_submit_does_not_create_a_second_row(): void
    {
        $this->approvedAnchor();
        $this->fakeEditReturns();

        $this->from($this->url())->post($this->url(), $this->settings());
        $this->from($this->url())->post($this->url(), $this->settings());

        $this->assertSame(1, VideoDesignImage::query()
            ->where('project_id', $this->project->id)
            ->where('image_type', 'reference_view')
            ->count());
    }

    public function test_a_provider_failure_is_reported_as_an_error(): void
    {
        $this->approvedAnchor();

        Http::fake([
            '*/v1/images/edits' => Http::response([
                'error' => ['code' => 'invalid_request_error', 'message' => 'nope'],
            ], 400),
        ]);

        $response = $this->from($this->url())->post($this->url(), $this->settings());

        $response->assertSessionHas('error');
        $response->assertSessionMissing('success');
    }

    public function test_an_artifact_from_another_project_never_gets_opened(): void
    {
        $foreign = VideoProject::create(['title' => 'TEST foreign '.uniqid()]);
        $anchor = $this->anchorCell();
        $artifact = $this->artifactFor($anchor, null, $foreign->id);

        $cell = VideoDesignImage::create([
            'project_id' => $this->project->id,
            'image_code' => 'ref_'.uniqid(),
            'image_type' => 'reference_view',
            'slot_index' => 1,
            'source_image_id' => $anchor->id,
            'prompt_spec_json' => [
                'prompt' => self::ANCHOR_PROMPT,
                'operation' => 'edit',
                'model' => 'gpt-image-2',
                'quality' => 'low',
                'size' => '1536x1024',
                'variations' => 1,
                'source_artifact_id' => $artifact->id,
                'source_artifact_sha256' => (string) $artifact->sha256,
            ],
            'prompt_sha256' => hash('sha256', uniqid('', true)),
            'status' => 'candidate',
            'revision' => 1,
        ]);

        Http::fake();

        $response = $this->from($this->url())
            ->post(route('video-projects.design-image-enqueue', [$this->project->id, $cell->id]));

        $response->assertSessionHas('error');
        Http::assertNothingSent();
        $this->assertSame(DesignImageStatus::FAILED->value, $cell->refresh()->status);
    }

    public function test_every_view_owns_one_slot_and_one_camera_sentence(): void
    {
        $slots = [];

        foreach (ReferenceView::cases() as $view) {
            $this->assertNotSame('', trim($view->cameraOverride()), $view->value.' has no camera sentence');
            $this->assertNotSame('', trim($view->label()), $view->value.' has no label');
            $slots[] = $view->slot();
        }

        $this->assertSame(range(1, count(ReferenceView::cases())), $slots);
    }

    public function test_a_camera_sentence_never_names_the_environment_or_the_canvas(): void
    {
        $forbidden = [
            'background', 'studio', 'hall', 'shipyard', 'sky', 'sea',
            'landscape', 'portrait', 'aspect', 'pixel', 'resolution',
        ];

        foreach (ReferenceView::cases() as $view) {
            $text = mb_strtolower($view->cameraOverride());

            foreach ($forbidden as $word) {
                $this->assertSame(
                    0,
                    preg_match('/\b'.preg_quote($word, '/').'\b/', $text),
                    $view->value.' camera sentence names "'.$word.'" — that belongs to the environment or the size parameter',
                );
            }

            $this->assertSame(
                0,
                preg_match('/\d+\s*[:x]\s*\d+/', $text),
                $view->value.' camera sentence states a canvas ratio or pixel size',
            );
        }
    }

    public function test_the_mirror_pair_points_the_bow_at_opposite_edges(): void
    {
        $this->assertSame(ReferenceView::STARBOARD_SIDE, ReferenceView::PORT_SIDE->mirrorPartner());
        $this->assertSame(ReferenceView::PORT_SIDE, ReferenceView::STARBOARD_SIDE->mirrorPartner());

        $this->assertStringContainsString('bow points to the left', ReferenceView::PORT_SIDE->cameraOverride());
        $this->assertStringContainsString('bow points to the right', ReferenceView::STARBOARD_SIDE->cameraOverride());

        foreach (ReferenceView::cases() as $view) {
            if (! in_array($view, [ReferenceView::PORT_SIDE, ReferenceView::STARBOARD_SIDE], true)) {
                $this->assertNull($view->mirrorPartner(), $view->value.' must not claim a mirror partner');
            }
        }
    }

    public function test_the_anchor_identity_hash_is_untouched_without_extra_keys(): void
    {
        $spec = [
            'prompt' => self::ANCHOR_PROMPT,
            'model' => 'gpt-image-2',
            'quality' => 'low',
            'size' => '1536x1024',
            'variations' => 1,
        ];

        $identity = $spec;
        ksort($identity);

        $this->assertSame(
            hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            app(DesignImageStore::class)->identityHash($spec),
        );
    }
}
