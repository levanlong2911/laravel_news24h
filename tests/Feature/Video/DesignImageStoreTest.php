<?php

namespace Tests\Feature\Video;

use App\Enums\DesignImageStatus;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Models\VideoProject;
use App\Models\VideoRenderScene;
use App\Services\Video\DesignImageStore;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DesignImageStoreTest extends TestCase
{
    use DatabaseTransactions;

    private DesignImageStore $store;

    private VideoProject $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new DesignImageStore;
        $this->project = VideoProject::create(['title' => 'TEST design image '.uniqid()]);
    }

    private function scene(int $revision, string $code, string $provesState): VideoRenderScene
    {
        return VideoRenderScene::create([
            'project_id' => $this->project->id,
            'revision' => $revision,
            'scene_index' => 1,
            'scene_code' => $code,
            'scene_type' => 'rough_build',
            'title' => 'Test Scene',
            'purpose' => 'A step in the build.',
            'state_json' => ['state_before' => 'before', 'scene_state' => $provesState],
            'delta_prompt' => 'Change only the hull.',
            'prompt_version' => 'scene-plan-v3',
            'transition_mode' => 'hard_cut_edit',
            'continuity_group' => 'g_'.$code,
        ]);
    }

    private function keyframe(?VideoRenderScene $scene, ?string $provesState, string $status): VideoDesignImage
    {
        $image = VideoDesignImage::create([
            'project_id' => $this->project->id,
            'render_scene_id' => $scene?->id,
            'image_type' => DesignImageStore::SCENE_KEYFRAME_TYPE,
            'image_code' => 'kf_'.uniqid(),
            'proves_state' => $provesState,
            'prompt_spec_json' => $this->spec(),
            'prompt_sha256' => hash('sha256', uniqid('', true)),
            'status' => $status,
        ]);

        $artifact = VideoArtifact::create([
            'project_id' => $this->project->id,
            'design_image_id' => $image->id,
            'artifact_type' => 'image',
            'storage_disk' => 'local',
            'storage_path' => 'test/'.$image->id.'.png',
            'mime_type' => 'image/png',
            'file_size' => 1,
            'sha256' => hash('sha256', $image->id),
        ]);

        $image->forceFill(['selected_artifact_id' => $artifact->id])->save();

        return $image->refresh();
    }

    public function test_approving_a_keyframe_supersedes_the_earlier_one_of_the_same_scene(): void
    {
        $scene = $this->scene(6, 'hull_frames_rise', 'framed_hull');

        $first = $this->keyframe($scene, 'framed_hull', DesignImageStatus::APPROVED->value);
        $second = $this->keyframe($scene, 'framed_hull', DesignImageStatus::RENDERED->value);

        [$ok] = $this->store->approve($this->project->id, $second->selected_artifact_id, null);

        $this->assertTrue($ok);
        $this->assertSame(DesignImageStatus::SUPERSEDED->value, $first->refresh()->status);
        $this->assertSame(DesignImageStatus::APPROVED->value, $second->refresh()->status);
    }

    /**
     * `proves_state` trung nhau giua hai scene va giua hai revision la binh
     * thuong, nen truoc khi co `render_scene_id` mot lan duyet se ha nham.
     */
    public function test_approving_a_keyframe_leaves_another_scene_and_revision_alone(): void
    {
        $sceneA = $this->scene(6, 'hull_frames_rise', 'framed_hull');
        $sceneB = $this->scene(6, 'shell_plating_closed', 'framed_hull');
        $sceneOld = $this->scene(5, 'hull_frames_rise', 'framed_hull');

        $otherScene = $this->keyframe($sceneB, 'framed_hull', DesignImageStatus::APPROVED->value);
        $otherRevision = $this->keyframe($sceneOld, 'framed_hull', DesignImageStatus::APPROVED->value);
        $mine = $this->keyframe($sceneA, 'framed_hull', DesignImageStatus::RENDERED->value);

        [$ok] = $this->store->approve($this->project->id, $mine->selected_artifact_id, null);

        $this->assertTrue($ok);
        $this->assertSame(DesignImageStatus::APPROVED->value, $otherScene->refresh()->status);
        $this->assertSame(DesignImageStatus::APPROVED->value, $otherRevision->refresh()->status);
    }

    public function test_approving_a_keyframe_leaves_the_anchor_alone(): void
    {
        $scene = $this->scene(6, 'hull_frames_rise', 'framed_hull');

        $anchor = $this->keyframe(null, null, DesignImageStatus::APPROVED->value);
        $anchor->forceFill(['image_type' => DesignImageStore::ANCHOR_TYPE])->save();

        $keyframe = $this->keyframe($scene, 'framed_hull', DesignImageStatus::RENDERED->value);

        [$ok] = $this->store->approve($this->project->id, $keyframe->selected_artifact_id, null);

        $this->assertTrue($ok);
        $this->assertSame(DesignImageStatus::APPROVED->value, $anchor->refresh()->status);
    }

    public function test_an_anchor_is_still_superseded_by_the_next_approved_anchor(): void
    {
        $first = $this->keyframe(null, null, DesignImageStatus::APPROVED->value);
        $second = $this->keyframe(null, null, DesignImageStatus::RENDERED->value);

        foreach ([$first, $second] as $image) {
            $image->forceFill(['image_type' => DesignImageStore::ANCHOR_TYPE])->save();
        }

        [$ok] = $this->store->approve($this->project->id, $second->selected_artifact_id, null);

        $this->assertTrue($ok);
        $this->assertSame(DesignImageStatus::SUPERSEDED->value, $first->refresh()->status);
    }

    private function costEntry(string $entityId): string
    {
        $id = (string) Str::uuid();

        DB::table('video_cost_entries')->insert([
            'id' => $id,
            'project_id' => $this->project->id,
            'entity_type' => 'design_image',
            'entity_id' => $entityId,
            'provider' => 'openai',
            'model' => 'gpt-image-2',
            'usage_type' => 'image_output',
            'quantity' => 1,
            'unit' => 'image',
            'cost_usd' => 0,
            'cost_idempotency_key' => 'test_'.$id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_deleting_a_scene_drops_the_candidate_and_keeps_artifact_cost_and_file(): void
    {
        Storage::fake('local');

        $scene = $this->scene(6, 'hull_frames_rise', 'framed_hull');
        $keyframe = $this->keyframe($scene, 'framed_hull', DesignImageStatus::RENDERED->value);
        $artifactId = $keyframe->selected_artifact_id;
        $costId = $this->costEntry($keyframe->id);

        $path = (string) VideoArtifact::find($artifactId)->storage_path;
        Storage::disk('local')->put($path, 'rendered-bytes');

        $anchor = $this->keyframe(null, null, DesignImageStatus::APPROVED->value);
        $anchor->forceFill(['image_type' => DesignImageStore::ANCHOR_TYPE])->save();

        DB::table('video_render_scenes')->where('id', $scene->id)->delete();

        $this->assertNull(VideoDesignImage::find($keyframe->id));

        $survivor = VideoDesignImage::find($anchor->id);
        $this->assertNotNull($survivor);
        $this->assertSame(DesignImageStore::ANCHOR_TYPE, $survivor->image_type);

        $artifact = VideoArtifact::find($artifactId);
        $this->assertNotNull($artifact);
        $this->assertNull($artifact->design_image_id);
        Storage::disk('local')->assertExists($path);

        $cost = DB::table('video_cost_entries')->where('id', $costId)->first();
        $this->assertNotNull($cost);
        $this->assertSame(
            $this->project->id,
            $cost->project_id,
            'the project still exists, so the ledger keeps pointing at it',
        );
    }

    public function test_deleting_the_project_keeps_the_artifact_and_the_cost_entry(): void
    {
        $scene = $this->scene(6, 'hull_frames_rise', 'framed_hull');
        $keyframe = $this->keyframe($scene, 'framed_hull', DesignImageStatus::RENDERED->value);
        $artifactId = $keyframe->selected_artifact_id;
        $costId = $this->costEntry($keyframe->id);

        DB::table('video_projects')->where('id', $this->project->id)->delete();

        $this->assertNull(VideoDesignImage::find($keyframe->id));
        $this->assertNull(VideoRenderScene::find($scene->id));

        $artifact = VideoArtifact::find($artifactId);
        $this->assertNotNull($artifact);
        $this->assertNull($artifact->project_id);

        $cost = DB::table('video_cost_entries')->where('id', $costId)->first();
        $this->assertNotNull($cost, 'the money ledger is not deleted with the project');
        $this->assertNull($cost->project_id);
    }

    /** `down()` nem TRUOC khi doi schema, nen phep thu nay khong bo cot nao. */
    public function test_the_migration_refuses_to_roll_back_while_a_scene_candidate_exists(): void
    {
        $scene = $this->scene(6, 'hull_frames_rise', 'framed_hull');
        $this->keyframe($scene, 'framed_hull', DesignImageStatus::RENDERED->value);

        $migration = require database_path(
            'migrations/2026_09_10_120000_add_render_scene_to_design_images.php'
        );

        try {
            $migration->down();
            $this->fail('down() must refuse while a scene candidate exists');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/con anh gan vao scene/', $e->getMessage());
        }

        $this->assertTrue(
            Schema::hasColumn('video_design_images', 'render_scene_id'),
            'the guard runs before any DDL, so the column must survive',
        );

        $this->assertSame(
            1,
            count(DB::select(
                'SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                   AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
                ['video_design_images', 'render_scene_id'],
            )),
        );
    }

    /** @return array<string, mixed> */
    public function test_an_unpriced_spec_offers_no_cost_estimate(): void
    {
        $this->pricedCell(DesignImageStore::ENVIRONMENT_TYPE, ['pricing' => 'unpriced', 'quality' => null]);

        $cell = $this->store->environmentCellsFor($this->project->id)[0];

        $this->assertSame('unpriced', $cell['pricing']);
        $this->assertNull($cell['cost_unit']);
        $this->assertNull($cell['cost_estimate']);
    }

    public function test_a_ledger_row_marked_unpriced_flags_the_recorded_cost(): void
    {
        $image = $this->pricedCell(DesignImageStore::ENVIRONMENT_TYPE, ['pricing' => 'unpriced', 'quality' => null]);
        $this->ledgerRow($image->id, 'unpriced');

        $cell = $this->store->environmentCellsFor($this->project->id)[0];

        $this->assertTrue($cell['cost_recorded_unpriced']);
        $this->assertSame(0.0, $cell['cost_recorded']);
    }

    public function test_a_ledger_row_marked_estimated_does_not_flag_the_recorded_cost(): void
    {
        $image = $this->pricedCell(DesignImageStore::ENVIRONMENT_TYPE);
        $this->ledgerRow($image->id, 'estimated');

        $cell = $this->store->environmentCellsFor($this->project->id)[0];

        $this->assertFalse($cell['cost_recorded_unpriced']);
        $this->assertIsFloat($cell['cost_estimate']);
    }

    public function test_a_reference_cell_is_never_shown_as_unpriced(): void
    {
        $image = $this->pricedCell(DesignImageStore::REFERENCE_TYPE);
        $this->ledgerRow($image->id, 'estimated');

        $cell = $this->store->referenceCellsFor($this->project->id)[0];

        $this->assertFalse($cell['cost_recorded_unpriced']);
        $this->assertSame('estimated', $cell['pricing']);
        $this->assertIsFloat($cell['cost_estimate']);
    }

    /** @param array<string, mixed> $override */
    private function pricedCell(string $type, array $override = []): VideoDesignImage
    {
        return VideoDesignImage::create([
            'project_id' => $this->project->id,
            'image_type' => $type,
            'image_code' => 'priced_'.uniqid(),
            'environment_key' => $type === DesignImageStore::ENVIRONMENT_TYPE ? 'paint_shed' : null,
            'prompt_spec_json' => $this->spec($override),
            'prompt_sha256' => hash('sha256', uniqid('', true)),
            'status' => DesignImageStatus::RENDERED->value,
        ]);
    }

    private function ledgerRow(string $entityId, string $pricing): void
    {
        $id = (string) Str::uuid();

        DB::table('video_cost_entries')->insert([
            'id' => $id,
            'project_id' => $this->project->id,
            'entity_type' => 'design_image',
            'entity_id' => $entityId,
            'provider' => 'test',
            'model' => 'test-model',
            'usage_type' => 'render',
            'quantity' => 1,
            'unit' => 'render',
            'cost_usd' => 0,
            'metadata_json' => json_encode(['pricing' => $pricing]),
            'cost_idempotency_key' => 'test_'.$id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function spec(array $override = []): array
    {
        return $override + [
            'prompt' => 'CAMERA: front three-quarter. SUBJECT: a hull.',
            'model' => 'gpt-image-2',
            'quality' => 'medium',
            'size' => '1024x1536',
            'variations' => 2,
        ];
    }

    public function test_a_first_submit_opens_a_candidate_cell(): void
    {
        [$image, $reason] = $this->store->createCandidate($this->project->id, 'Van Long', $this->spec());

        $this->assertSame('created', $reason);
        $this->assertSame('candidate', $image->status);
        $this->assertSame('identity_anchor', $image->image_type);
        $this->assertSame($this->spec(), $image->prompt_spec_json);
        $this->assertStringStartsWith('master_vessel_van_long_', $image->image_code);
        $this->assertLessThanOrEqual(100, strlen($image->image_code));
    }

    public function test_changing_the_variation_count_opens_a_second_cell(): void
    {
        [$first] = $this->store->createCandidate($this->project->id, 'Van Long', $this->spec());

        [$second, $reason] = $this->store->createCandidate(
            $this->project->id, 'Van Long', $this->spec(['variations' => 1]),
        );

        $this->assertSame('created', $reason);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, $first->prompt_spec_json['variations']);
        $this->assertSame(1, $second->prompt_spec_json['variations']);
        $this->assertSame(2, VideoDesignImage::where('project_id', $this->project->id)->count());
    }

    public function test_changing_the_quality_opens_a_second_cell(): void
    {
        [$first] = $this->store->createCandidate($this->project->id, 'Van Long', $this->spec());

        [$second, $reason] = $this->store->createCandidate(
            $this->project->id, 'Van Long', $this->spec(['quality' => 'high']),
        );

        $this->assertSame('created', $reason);
        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->image_code, $second->image_code);
    }

    public function test_changing_the_resolution_opens_a_second_cell(): void
    {
        [$first] = $this->store->createCandidate($this->project->id, 'Van Long', $this->spec());

        [$second, $reason] = $this->store->createCandidate(
            $this->project->id, 'Van Long', $this->spec(['size' => '1024x1024']),
        );

        $this->assertSame('created', $reason);
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_an_unknown_project_is_reported_not_written(): void
    {
        [$image, $reason] = $this->store->createCandidate((string) Str::uuid(), 'Van Long', $this->spec());

        $this->assertNull($image);
        $this->assertSame('project_not_found', $reason);
    }

    public function test_a_double_submit_leaves_exactly_one_cell(): void
    {
        [$first, $firstReason] = $this->store->createCandidate($this->project->id, 'Van Long', $this->spec());
        [$second, $secondReason] = $this->store->createCandidate($this->project->id, 'Van Long', $this->spec());

        $this->assertSame('created', $firstReason);
        $this->assertSame('already_exists', $secondReason);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, VideoDesignImage::where('project_id', $this->project->id)->count());
    }

    public function test_the_identity_hash_ignores_key_order_but_not_the_prompt(): void
    {
        $ordered = $this->store->identityHash([
            'prompt' => 'A', 'model' => 'gpt-image-2', 'quality' => 'low', 'size' => '1024x1536', 'variations' => 2,
        ]);
        $shuffled = $this->store->identityHash([
            'variations' => 2, 'size' => '1024x1536', 'quality' => 'low', 'model' => 'gpt-image-2', 'prompt' => 'A',
        ]);

        $this->assertSame($ordered, $shuffled);
        $this->assertNotSame($ordered, $this->store->identityHash([
            'prompt' => 'B', 'model' => 'gpt-image-2', 'quality' => 'low', 'size' => '1024x1536', 'variations' => 2,
        ]));
    }

    public function test_an_identity_spec_missing_a_key_is_refused_instead_of_hashed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('identityHash: missing quality');

        $this->store->identityHash(['prompt' => 'A', 'model' => 'gpt-image-2', 'size' => '1024x1536']);
    }

    public function test_a_creator_without_usable_letters_is_refused_before_any_write(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store->nextImageCode($this->project->id, '   ');
    }
}
