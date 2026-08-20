<?php

namespace Tests\Feature\Video;

use App\Enums\DesignImageStatus;
use App\Models\VideoDesignImage;
use App\Models\VideoProject;
use App\Models\VideoRender;
use App\Models\VideoShot;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DesignImageStatusTest extends TestCase
{
    use DatabaseTransactions;

    public function test_every_status_in_the_database_is_a_known_case(): void
    {
        // Doc DU LIEU THAT: enum bo sot mot gia tri da ton tai thi moi cho doc
        // `status` nem ValueError — fixture khong bat duoc chuyen do.
        $unknown = DB::table('video_design_images')
            ->distinct()
            ->pluck('status')
            ->reject(fn ($status) => in_array($status, DesignImageStatus::values(), true))
            ->values()
            ->all();

        $this->assertSame([], $unknown, 'Trang thai co trong DB nhung khong co trong enum: '.implode(', ', $unknown));
    }

    public function test_the_legacy_states_survive_the_new_enum(): void
    {
        $this->assertContains('approved', DesignImageStatus::values());
        $this->assertContains('superseded', DesignImageStatus::values());
    }

    public function test_a_cell_that_already_has_an_image_can_never_be_pushed_back_into_the_queue(): void
    {
        $this->assertNotContains(DesignImageStatus::RENDERED->value, DesignImageStatus::enqueueableValues());
        $this->assertNotContains(DesignImageStatus::APPROVED->value, DesignImageStatus::enqueueableValues());
        $this->assertContains(DesignImageStatus::FAILED->value, DesignImageStatus::enqueueableValues());
    }

    public function test_the_new_columns_start_empty_so_a_fresh_cell_holds_no_lease(): void
    {
        $project = VideoProject::create(['title' => 'TEST lease columns '.uniqid()]);

        $image = VideoDesignImage::create([
            'project_id' => $project->id,
            'image_code' => 'test_lease_'.uniqid(),
            'image_type' => 'identity_anchor',
            'status' => DesignImageStatus::CANDIDATE->value,
            'revision' => 1,
        ]);

        $this->assertNull($image->worker_id);
        $this->assertNull($image->claim_token);
        $this->assertNull($image->claimed_at);
        $this->assertNull($image->lease_expires_at);
        $this->assertNull($image->queued_at);
        $this->assertNull($image->render_error);
    }

    /** @return array<string, mixed> */
    private function ledgerRow(array $override): array
    {
        return $override + [
            'attempt_no' => 1,
            'render_kind' => 'image',
            'provider' => 'openai',
            'model' => 'gpt-image-2',
            'sent_prompt' => 'CAMERA: front three-quarter.',
            'prompt_sha256' => hash('sha256', 'CAMERA: front three-quarter.'),
            'cost_usd' => 0.015,
            'status' => 'succeeded',
            'proof_verified' => false,
        ];
    }

    private function designImage(): VideoDesignImage
    {
        $project = VideoProject::create(['title' => 'TEST ledger owner '.uniqid()]);

        return VideoDesignImage::create([
            'project_id' => $project->id,
            'image_code' => 'test_owner_'.uniqid(),
            'image_type' => 'identity_anchor',
            'status' => DesignImageStatus::RENDERED->value,
            'revision' => 1,
        ]);
    }

    public function test_a_ledger_row_owned_by_a_design_image_is_accepted(): void
    {
        $render = VideoRender::create($this->ledgerRow(['design_image_id' => $this->designImage()->id]));

        $this->assertNotNull($render->id);
        $this->assertNull($render->shot_id);
    }

    public function test_a_ledger_row_owned_by_a_shot_is_still_accepted(): void
    {
        $shotId = VideoShot::query()->value('id');

        if ($shotId === null) {
            $this->markTestSkipped('Chua co shot nao tren may nay de kiem chieu con lai.');
        }

        $render = VideoRender::create($this->ledgerRow(['shot_id' => $shotId]));

        $this->assertNull($render->design_image_id);
    }

    public function test_a_ledger_row_without_an_owner_is_refused_by_the_database(): void
    {
        // Neu CHECK bi viet sai cu phap hoac khong duoc kich hoat, dong nay se
        // ghi duoc — va bat bien "moi khoan tien deu truy duoc chu" chi con la
        // loi noi. Day la ly do test nay INSERT that thay vi doc bang.
        $this->expectException(QueryException::class);

        VideoRender::create($this->ledgerRow([]));
    }

    public function test_a_ledger_row_with_two_owners_is_refused_by_the_database(): void
    {
        $shotId = VideoShot::query()->value('id');

        if ($shotId === null) {
            $this->markTestSkipped('Chua co shot nao tren may nay de dung lam chu thu hai.');
        }

        $this->expectException(QueryException::class);

        VideoRender::create($this->ledgerRow([
            'shot_id' => $shotId,
            'design_image_id' => $this->designImage()->id,
        ]));
    }

    public function test_two_attempts_of_the_same_number_cannot_share_a_design_image(): void
    {
        // Unique cu khoa tren `shot_id`; voi shot_id NULL thi MariaDB cho lap vo
        // han. Khong co cap unique rieng nay thi bam hai lan = tra tien hai lan.
        $image = $this->designImage();

        VideoRender::create($this->ledgerRow(['design_image_id' => $image->id]));

        $this->expectException(QueryException::class);

        VideoRender::create($this->ledgerRow(['design_image_id' => $image->id]));
    }

    public function test_one_idempotency_key_cannot_be_replayed_against_a_design_image(): void
    {
        $image = $this->designImage();
        $key = (string) \Illuminate\Support\Str::uuid();

        VideoRender::create($this->ledgerRow(['design_image_id' => $image->id, 'idempotency_key' => $key]));

        $this->expectException(QueryException::class);

        VideoRender::create($this->ledgerRow([
            'design_image_id' => $image->id,
            'attempt_no' => 2,
            'idempotency_key' => $key,
        ]));
    }
}
