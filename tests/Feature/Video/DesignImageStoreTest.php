<?php

namespace Tests\Feature\Video;

use App\Models\VideoDesignImage;
use App\Models\VideoProject;
use App\Services\Video\DesignImageStore;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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

    /** @return array<string, mixed> */
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
