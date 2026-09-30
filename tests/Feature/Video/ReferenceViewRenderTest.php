<?php

namespace Tests\Feature\Video;

use App\Enums\DesignImageStatus;
use App\Enums\ImageSize;
use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\Admin;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Services\Video\DesignImageStore;
use App\Services\Video\ReferencePromptWriter;
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

    private const SUBJECT = 'ch_vessel';

    private const GEOMETRY = 'The two volumes are joined across an open courtyard.';

    private VideoProject $project;

    private ?VideoPlanningStage $screenplay = null;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('video_artifacts');

        config([
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
        $view = ReferenceView::from((string) ($override['view'] ?? ReferenceView::PORT_SIDE->value));
        $environment = ReferenceEnvironment::from(
            (string) ($override['environment'] ?? ReferenceEnvironment::NEUTRAL_STUDIO->value),
        );
        $stage = VideoPlanningStage::query()
            ->where('project_id', $this->project->id)
            ->where('stage', PlanningStageName::REFERENCE_PROMPT->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->orderByDesc('planning_revision')
            ->get()
            ->first(fn (VideoPlanningStage $row): bool => ($row->input_json['view'] ?? null) === $view->value);
        $prompt = $stage === null ? '' : ReferencePromptWriter::assemble($stage->output_json['claims'], $view, $environment);

        return $override + [
            'reference_prompt_stage_id' => (string) $stage?->id,
            'prompt_sha256' => hash('sha256', $prompt),
            'view' => $view->value,
            'environment' => $environment->value,
            'model' => 'gpt-image-2',
            'quality' => 'low',
            'size' => '1536x1024',
            'variations' => 1,
        ];
    }

    private function screenplay(): VideoPlanningStage
    {
        return $this->screenplay ??= VideoPlanningStage::create([
            'project_id' => $this->project->id,
            'planning_revision' => 1,
            'stage' => PlanningStageName::SCREENPLAY->value,
            'status' => VideoPlanningStageStatus::SUCCEEDED->value,
            'input_json' => ['fingerprint' => 'x'],
            'input_hash' => hash('sha256', uniqid('screenplay', true)),
            'output_json' => [
                'logline' => 'A yard builds a vessel whose two volumes share one courtyard.',
                'design_thesis' => [
                    'central_idea' => self::GEOMETRY,
                    'visible_difference' => 'Two separate volumes instead of one superstructure.',
                    'spatial_consequence' => 'The courtyard sits between the volumes.',
                    'coherence' => 'Both volumes share one hull.',
                    'realization' => 'Steel bridges link the volumes.',
                ],
                'principal_dimensions' => ['length_m' => 120, 'beam_m' => 22.5, 'rationale' => 'Fixed by the brief.'],
                'characters' => [[
                    'id' => self::SUBJECT,
                    'name' => 'The vessel',
                    'role' => 'protagonist',
                    'kind' => 'object',
                    'description' => 'A steel hull carrying two volumes.',
                    'personality' => null,
                    'appearance' => 'Bare steel plating and an upright stem.',
                ]],
                'locations' => [['id' => 'loc_hall', 'name' => 'Build hall', 'description' => 'One long space under its roof.']],
                'scenes' => [[
                    'id' => 'sc_01',
                    'stage' => 'hull',
                    'location_id' => 'loc_hall',
                    'character_ids' => [self::SUBJECT],
                    'action' => 'Welders close the stern plating of the aft volume.',
                    'build_state' => null,
                ]],
            ],
            'finished_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function aiAnswer(array $override = []): array
    {
        return $override + [
            'claims' => [
                ['statement' => 'The aft volume ends in a plain steel face.', 'basis' => 'inference', 'source_paths' => []],
                ['statement' => self::GEOMETRY, 'basis' => 'source', 'source_paths' => ['design.design_thesis.central_idea']],
            ],
            'discrepancies' => [],
            'review_incomplete' => false,
        ];
    }

    private function fakeAi(?array $answer = null, ?array $usage = null): void
    {
        Http::fake([
            '*/v1/chat/completions' => Http::response([
                'model' => 'gpt-5.6-terra',
                'choices' => [[
                    'message' => ['content' => json_encode($answer ?? $this->aiAnswer())],
                    'finish_reason' => 'stop',
                ]],
                'usage' => $usage ?? [
                    'prompt_tokens' => 1000,
                    'completion_tokens' => 200,
                    'prompt_tokens_details' => ['cached_tokens' => 400],
                ],
            ], 200),
        ]);
    }

    private function writePrompt(ReferenceView $view = ReferenceView::PORT_SIDE, ?array $answer = null, ?array $usage = null)
    {
        $this->fakeAi($answer, $usage);

        return $this->from($this->url())->post(
            route('video-projects.reference-prompt', $this->project->id),
            ['view' => $view->value],
        );
    }

    private function editRequests(): int
    {
        return Http::recorded(fn ($request) => str_ends_with($request->url(), '/v1/images/edits'))->count();
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
            'prompt_spec_json' => $anchor->prompt_spec_json + [
                'character_id' => self::SUBJECT,
                'screenplay_stage_id' => (string) $this->screenplay()->id,
            ],
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
                'unit_cost_usd' => 0.015,
                'pricing_version' => 'openai-image-inherited-2026-09-14',
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
        $this->writePrompt();
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
        $this->writePrompt();
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
        $this->writePrompt();
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

    public function test_the_view_menu_speaks_vietnamese_but_posts_english_keys(): void
    {
        $this->approvedAnchor();

        $html = $this->get($this->url())->assertOk()->getContent();

        foreach (ReferenceView::menu() as $view) {
            $this->assertStringContainsString('value="'.$view->value.'"', $html);
            $this->assertStringContainsString('>'.$view->displayLabel().'</option>', $html);
        }

        $this->assertStringContainsString('<option value="" disabled>Tạo bộ ảnh tham chiếu từ ảnh hiện tại (chưa hỗ trợ)</option>', $html);
        $this->assertStringNotContainsString('>Deck Overview</option>', $html);
        $this->assertStringContainsString('looking straight down', ReferenceView::TOP_DOWN->cameraOverride());
    }

    public function test_the_page_shows_the_ai_prompt_and_never_the_anchor_geometry_prompt(): void
    {
        $this->approvedAnchor();
        $this->writePrompt();

        $html = $this->get($this->url())->assertOk()->getContent();

        $this->assertSame(0, substr_count($html, 'Bare fabrication hull'));
        $this->assertStringContainsString('authoritative record of every part of this object it shows', $html);
        $this->assertStringContainsString(self::GEOMETRY, $html);
    }

    private function failedAiCell(): VideoDesignImage
    {
        $this->approvedAnchor();
        $this->writePrompt();

        Http::fake([
            '*/v1/images/edits' => Http::sequence()
                ->push(['error' => ['code' => 'server_error', 'message' => 'busy']], 400)
                ->push([
                    'created' => 1,
                    'size' => '1536x1024',
                    'quality' => 'low',
                    'output_format' => 'png',
                    'usage' => ['total_tokens' => 10],
                    'data' => [['b64_json' => base64_encode($this->asymmetricPng())]],
                ], 200),
        ]);

        $this->from($this->url())->post($this->url(), $this->settings());

        return VideoDesignImage::query()
            ->where('project_id', $this->project->id)
            ->where('image_type', 'reference_view')
            ->sole();
    }

    public function test_a_legacy_reference_cannot_be_rendered_again_by_the_enqueue_path(): void
    {
        $anchor = $this->approvedAnchor();
        $cell = $this->referenceCell($anchor, $anchor->artifact);
        Http::fake();

        $response = $this->from($this->url())
            ->post(route('video-projects.design-image-enqueue', [$this->project->id, $cell->id]));

        $response->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'AI viết prompt'));
        Http::assertNothingSent();
        $this->assertSame('candidate', $cell->refresh()->status);
    }

    public function test_a_tampered_anchor_file_is_stopped_on_the_rerender_path(): void
    {
        $cell = $this->failedAiCell();
        $artifact = VideoArtifact::query()->findOrFail($cell->prompt_spec_json['source_artifact_id']);

        Storage::disk('video_artifacts')->put($artifact->storage_path, 'tampered-bytes');
        Http::fake();

        $response = $this->from($this->url())
            ->post(route('video-projects.design-image-enqueue', [$this->project->id, $cell->id]));

        $response->assertSessionHas('error');
        Http::assertNothingSent();
        $this->assertSame(DesignImageStatus::FAILED->value, $cell->refresh()->status);
        $this->assertStringContainsString('checksum', (string) $cell->render_error);
    }

    public function test_an_ai_reference_whose_prompt_was_changed_cannot_be_rendered_again(): void
    {
        $cell = $this->failedAiCell();
        $cell->update(['prompt_spec_json' => ['prompt' => 'edited by hand'] + $cell->prompt_spec_json]);
        Http::fake();

        $this->from($this->url())
            ->post(route('video-projects.design-image-enqueue', [$this->project->id, $cell->id]))
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_an_untouched_anchor_file_passes_the_rerender_checksum_gate(): void
    {
        $cell = $this->failedAiCell();

        $this->from($this->url())
            ->post(route('video-projects.design-image-enqueue', [$this->project->id, $cell->id]));

        $this->assertSame(2, $this->editRequests());
        $this->assertSame(DesignImageStatus::RENDERED->value, $cell->refresh()->status);
    }

    public function test_a_duplicate_view_reports_a_sentence_not_a_reason_code(): void
    {
        $this->approvedAnchor();
        $this->writePrompt();
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

    public function test_a_reference_prompt_is_refused_while_no_anchor_is_approved(): void
    {
        Http::fake();

        $response = $this->from($this->url())->post(
            route('video-projects.reference-prompt', $this->project->id),
            ['view' => ReferenceView::PORT_SIDE->value],
        );

        $response->assertSessionHas('error', __('messages.no_approved_anchor'));
        Http::assertNothingSent();
        $this->assertSame(0, VideoPlanningStage::query()
            ->where('project_id', $this->project->id)
            ->where('stage', PlanningStageName::REFERENCE_PROMPT->value)
            ->count());
    }

    public function test_a_render_without_an_ai_prompt_is_refused_before_any_call(): void
    {
        $this->approvedAnchor();
        Http::fake();

        $response = $this->from($this->url())->post($this->url(), $this->settings());

        $response->assertSessionHasErrors('reference_prompt_stage_id');
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

        $response = $this->from($this->url())->post(
            route('video-projects.reference-prompt', $this->project->id),
            ['view' => ReferenceView::PORT_SIDE->value],
        );

        $response->assertSessionHas('error', __('messages.artifact_checksum_mismatch'));
        Http::assertNothingSent();
    }

    public function test_a_missing_anchor_file_is_stopped_before_the_call(): void
    {
        Http::fake();
        $anchor = $this->approvedAnchor();

        Storage::disk('video_artifacts')->delete($anchor->artifact->storage_path);

        $response = $this->from($this->url())->post(
            route('video-projects.reference-prompt', $this->project->id),
            ['view' => ReferenceView::PORT_SIDE->value],
        );

        $response->assertSessionHas('error', __('messages.artifact_file_not_found'));
        Http::assertNothingSent();
    }

    public function test_the_edits_endpoint_is_the_one_that_gets_called(): void
    {
        $this->approvedAnchor();
        $this->writePrompt();
        $this->fakeEditReturns();

        $this->from($this->url())->post($this->url(), $this->settings());

        $this->assertSame(1, $this->editRequests());
    }

    public function test_a_vertical_canvas_reaches_the_provider_and_stays_out_of_the_prompt(): void
    {
        $this->approvedAnchor();
        $this->writePrompt();
        $this->fakeEditReturns();

        $this->from($this->url())->post($this->url(), $this->settings([
            'size' => ImageSize::VERTICAL_2K->value,
        ]));

        $this->assertSame(1, $this->editRequests());
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
        $this->writePrompt();
        $this->fakeEditReturns();

        $this->from($this->url())->post($this->url(), $this->settings());

        $cell = VideoDesignImage::query()
            ->where('project_id', $this->project->id)
            ->where('image_type', 'reference_view')
            ->firstOrFail();

        $sent = (string) $cell->prompt_spec_json['prompt'];

        $this->assertStringContainsString(IdentityPreservationPrompt::text(), $sent);
        $this->assertStringContainsString("VIEW GEOMETRY:\nThe aft volume ends in a plain steel face. ".self::GEOMETRY, $sent);
        $this->assertStringContainsString(ReferenceView::PORT_SIDE->cameraOverride(), $sent);
        $this->assertStringNotContainsString('Bare fabrication hull', $sent);
        $this->assertLessThan(3000, mb_strlen($sent));
    }

    public function test_a_reference_row_records_its_derivation_and_lock_slots(): void
    {
        $this->approvedAnchor();
        $this->writePrompt();
        $this->fakeEditReturns();

        $this->from($this->url())->post($this->url(), $this->settings());

        $spec = VideoDesignImage::query()
            ->where('project_id', $this->project->id)
            ->where('image_type', 'reference_view')
            ->firstOrFail()
            ->prompt_spec_json;

        $this->assertSame('gpt_edit', $spec['derivation']);
        $this->assertSame(ReferencePromptWriter::DERIVATION_VERSION, $spec['derivation_version']);
        $this->assertNull($spec['identity_lock_id']);
        $this->assertNull($spec['identity_lock_hash']);
        $this->assertSame($spec['source_artifact_id'], $spec['identity_anchor_artifact_id']);
        $this->assertSame($spec['source_artifact_sha256'], $spec['identity_anchor_sha256']);
        $this->assertNotEmpty($spec['reference_prompt_stage_id']);
    }

    public function test_the_starboard_view_goes_to_the_provider_and_is_never_flipped(): void
    {
        $this->approvedAnchor();
        $this->writePrompt();
        $this->writePrompt(ReferenceView::STARBOARD_SIDE);
        $this->fakeEditReturns();

        $this->from($this->url())->post($this->url(), $this->settings());
        $this->from($this->url())->post($this->url(), $this->settings([
            'view' => ReferenceView::STARBOARD_SIDE->value,
        ]));

        $cell = VideoDesignImage::query()
            ->where('project_id', $this->project->id)
            ->where('image_type', 'reference_view')
            ->where('slot_index', ReferenceView::STARBOARD_SIDE->slot())
            ->firstOrFail();

        $this->assertSame(2, $this->editRequests());
        $this->assertSame('edit', $cell->prompt_spec_json['operation']);
        $this->assertStringContainsString(ReferenceView::STARBOARD_SIDE->cameraOverride(), $cell->prompt_spec_json['prompt']);
    }

    public function test_a_reference_row_carries_its_type_slot_and_source(): void
    {
        $anchor = $this->approvedAnchor();
        $this->writePrompt(ReferenceView::STERN_REAR);
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
        $this->writePrompt();
        $this->fakeEditReturns();

        $this->from($this->url())->post($this->url(), $this->settings());
        $this->from($this->url())->post($this->url(), $this->settings());

        $this->assertSame(1, VideoDesignImage::query()
            ->where('project_id', $this->project->id)
            ->where('image_type', 'reference_view')
            ->count());
    }

    public function test_the_ai_prompt_is_written_once_and_priced_from_usage(): void
    {
        $this->approvedAnchor();
        $this->writePrompt()->assertSessionHas('success');
        $this->from($this->url())
            ->post(route('video-projects.reference-prompt', $this->project->id), ['view' => ReferenceView::PORT_SIDE->value])
            ->assertSessionHas('success');

        $this->assertSame(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/v1/chat/completions'))->count());
        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][1]['content'] ?? null;

            return str_ends_with($request->url(), '/v1/chat/completions')
                && $request->data()['model'] === 'gpt-5.6-terra'
                && is_array($content)
                && str_starts_with((string) ($content[1]['image_url']['url'] ?? ''), 'data:image/png;base64,');
        });

        $stage = VideoPlanningStage::query()
            ->where('project_id', $this->project->id)
            ->where('stage', PlanningStageName::REFERENCE_PROMPT->value)
            ->sole();

        $this->assertSame(VideoPlanningStageStatus::SUCCEEDED->value, $stage->status);
        $this->assertSame('estimated', $stage->input_json['_meta']['pricing']);
        $this->assertEqualsWithDelta((600 * 2.0 + 400 * 0.2 + 200 * 12.0) / 1_000_000, (float) $stage->cost_usd, 0.000001);
    }

    public function test_an_answer_without_usage_stays_unpriced(): void
    {
        $this->approvedAnchor();
        $this->writePrompt(usage: ['prompt_tokens' => 1000, 'completion_tokens' => 200]);

        $stage = VideoPlanningStage::query()
            ->where('project_id', $this->project->id)
            ->where('stage', PlanningStageName::REFERENCE_PROMPT->value)
            ->sole();

        $this->assertSame('unpriced', $stage->input_json['_meta']['pricing']);
        $this->assertSame(0.0, (float) $stage->cost_usd);
    }

    public function test_an_answer_that_breaks_the_contract_is_kept_but_never_rendered(): void
    {
        $this->approvedAnchor();
        $this->writePrompt(answer: $this->aiAnswer([
            'claims' => [['statement' => self::GEOMETRY, 'basis' => 'source', 'source_paths' => ['subject.name']]],
            'discrepancies' => [[
                'severity' => 'minor',
                'topic' => 'other',
                'image_observation' => 'The stem is raked.',
                'source_statement' => 'a raked stem',
                'source_path' => 'subject.appearance',
            ]],
        ]))->assertSessionHas('error');

        $stage = VideoPlanningStage::query()
            ->where('project_id', $this->project->id)
            ->where('stage', PlanningStageName::REFERENCE_PROMPT->value)
            ->sole();

        $this->assertSame(VideoPlanningStageStatus::FAILED->value, $stage->status);
        $this->assertNotEmpty($stage->raw_response);
        $this->assertSame(1000, $stage->output_json['usage']['prompt_tokens']);
        $this->assertStringContainsString('"subject.name" is not a citable path', $stage->error_message);
        $this->assertStringContainsString('not a word-for-word excerpt of subject.appearance', $stage->error_message);
        $this->assertSame('', $this->settings()['reference_prompt_stage_id']);
    }

    public function test_a_scene_path_alone_cannot_carry_a_claim(): void
    {
        $this->approvedAnchor();
        $this->writePrompt(answer: $this->aiAnswer([
            'claims' => [['statement' => 'The stern is plated.', 'basis' => 'source', 'source_paths' => ['scenes.sc_01.action']]],
        ]));

        $this->assertStringContainsString(
            'a scene action path needs a design or subject path beside it',
            (string) VideoPlanningStage::query()
                ->where('project_id', $this->project->id)
                ->where('stage', PlanningStageName::REFERENCE_PROMPT->value)
                ->value('error_message'),
        );
    }

    public function test_an_incomplete_review_cannot_be_rendered_even_when_acknowledged(): void
    {
        $this->approvedAnchor();
        $this->writePrompt(answer: $this->aiAnswer(['review_incomplete' => true]));
        $this->fakeEditReturns();

        $response = $this->from($this->url())->post($this->url(), $this->settings(['acknowledge_discrepancies' => 1]));

        $response->assertSessionHas('error');
        $this->assertSame(0, $this->editRequests());
    }

    public function test_a_major_discrepancy_needs_an_acknowledgement_that_is_recorded(): void
    {
        $this->approvedAnchor();
        $this->writePrompt(answer: $this->aiAnswer(['discrepancies' => [[
            'severity' => 'major',
            'topic' => 'connection',
            'image_observation' => 'The courtyard is open on both sides.',
            'source_statement' => 'joined across an open courtyard',
            'source_path' => 'design.design_thesis.central_idea',
        ]]]));
        $this->fakeEditReturns();

        $this->from($this->url())->post($this->url(), $this->settings())->assertSessionHas('error');
        $this->assertSame(0, $this->editRequests());

        $this->from($this->url())->post($this->url(), $this->settings(['acknowledge_discrepancies' => 1]));

        $spec = VideoDesignImage::query()
            ->where('project_id', $this->project->id)
            ->where('image_type', 'reference_view')
            ->sole()
            ->prompt_spec_json;

        $this->assertSame(1, $this->editRequests());
        $this->assertSame($spec['reference_prompt_stage_id'], $spec['discrepancy_ack']['stage_id']);
    }

    public function test_a_prompt_hash_from_another_environment_is_refused(): void
    {
        $this->approvedAnchor();
        $this->writePrompt();
        $this->fakeEditReturns();

        $hall = $this->settings(['environment' => ReferenceEnvironment::SHIPYARD_HALL->value]);

        $this->from($this->url())->post($this->url(), $this->settings([
            'prompt_sha256' => $hall['prompt_sha256'],
        ]))->assertSessionHas('error');

        $this->assertSame(0, $this->editRequests());
    }

    public function test_a_statement_cut_mid_sentence_is_refused_and_the_reason_is_shown(): void
    {
        $this->approvedAnchor();

        $this->writePrompt(answer: $this->aiAnswer([
            'claims' => [['statement' => 'Keep all visible bridge alignments coherent; do not add,', 'basis' => 'image', 'source_paths' => []]],
        ]))->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'Chi tiết:')
            && str_contains($message, 'claims[0]: statement is not a complete sentence'));
    }

    public function test_a_major_discrepancy_outside_the_four_geometry_topics_is_refused(): void
    {
        $this->approvedAnchor();

        $this->writePrompt(answer: $this->aiAnswer(['discrepancies' => [[
            'severity' => 'major',
            'topic' => 'other',
            'image_observation' => 'The window bays are open framing.',
            'source_statement' => 'Bare steel plating',
            'source_path' => 'subject.appearance',
        ]]]))->assertSessionHas('error', fn (string $message): bool => str_contains(
            $message, 'a major discrepancy must be one of mass_count, connection, proportion, primary_opening',
        ));
    }

    public function test_an_inference_with_sources_names_the_rule_it_breaks(): void
    {
        $this->approvedAnchor();

        $this->writePrompt(answer: $this->aiAnswer([
            'claims' => [['statement' => 'The aft face continues plainly.', 'basis' => 'inference', 'source_paths' => ['subject.description']]],
        ]))->assertSessionHas('error', fn (string $message): bool => str_contains(
            $message, 'basis inference requires source_paths to be empty',
        ));
    }

    public function test_the_schema_splits_claims_into_uncited_and_cited_shapes(): void
    {
        $shapes = ReferencePromptWriter::schema()['properties']['claims']['items']['anyOf'];

        $this->assertSame(['image', 'inference'], $shapes[0]['properties']['basis']['enum']);
        $this->assertSame(0, $shapes[0]['properties']['source_paths']['maxItems']);
        $this->assertSame(['source', 'image_and_source'], $shapes[1]['properties']['basis']['enum']);
        $this->assertSame(1, $shapes[1]['properties']['source_paths']['minItems']);
    }

    public function test_a_refusal_still_records_the_usage_it_was_charged_for(): void
    {
        $this->approvedAnchor();

        Http::fake([
            '*/v1/chat/completions' => Http::response([
                'model' => 'gpt-5.6-terra',
                'choices' => [['message' => ['content' => null, 'refusal' => 'I cannot help.'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 20, 'prompt_tokens_details' => ['cached_tokens' => 0]],
            ], 200),
        ]);

        $this->from($this->url())
            ->post(route('video-projects.reference-prompt', $this->project->id), ['view' => ReferenceView::PORT_SIDE->value])
            ->assertSessionHas('error');

        $stage = VideoPlanningStage::query()
            ->where('project_id', $this->project->id)
            ->where('stage', PlanningStageName::REFERENCE_PROMPT->value)
            ->sole();

        $this->assertSame(VideoPlanningStageStatus::FAILED->value, $stage->status);
        $this->assertSame(900, $stage->tokens_in);
        $this->assertSame('estimated', $stage->input_json['_meta']['pricing']);
        $this->assertGreaterThan(0, (float) $stage->cost_usd);
    }

    public function test_the_page_lists_each_claim_with_its_basis_and_source_text(): void
    {
        $this->approvedAnchor();
        $this->writePrompt();

        $html = $this->get($this->url())->assertOk()->getContent();

        $this->assertStringContainsString('"basis":"inference"', $html);
        $this->assertStringContainsString('"path":"design.design_thesis.central_idea"', $html);
        $this->assertStringContainsString('suy diễn phần chưa xác định', $html);
    }

    public function test_a_provider_failure_is_reported_as_an_error(): void
    {
        $this->approvedAnchor();
        $this->writePrompt();

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
        $this->assertSame('candidate', $cell->refresh()->status);
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
