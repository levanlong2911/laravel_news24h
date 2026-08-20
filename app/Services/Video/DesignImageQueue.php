<?php

namespace App\Services\Video;

use App\Enums\DesignImageStatus;
use App\Models\VideoArtifact;
use App\Models\VideoCostEntry;
use App\Models\VideoDesignImage;
use App\Models\VideoProject;
use App\Models\VideoRender;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Hang doi render cho o thiet ke anh — song song voi hang doi shot, khong dung
 * chung: `video_shots.session_id` la NOT NULL, ma anh neo thuoc PROJECT chu chua
 * co session nao.
 *
 * Bon chot giu tien, xep theo thu tu phong thu:
 *   1. `enqueueableValues()` chan `rendered`/`approved` vao lai hang doi
 *   2. claim phai con hieu luc moi ghi duoc bat cu gi
 *   3. `idempotency_key` BAT BUOC — unique khong chan duoc nhieu dong NULL
 *   4. CHECK `video_renders_one_owner` o tang DB, code khong di vong duoc
 */
class DesignImageQueue
{
    private const CLAIM_MAX = 100;

    private const ENTITY_TYPE = 'design_image';

    private const REQUIRED_EVENT_KEYS = ['provider', 'model', 'render_kind', 'sent_prompt'];

    /** @return array{0: ?VideoDesignImage, 1: string} */
    public function enqueue(string $imageId): array
    {
        try {
            return DB::transaction(function () use ($imageId) {
                $projectId = VideoDesignImage::query()->whereKey($imageId)->value('project_id');

                if ($projectId === null) {
                    return [null, 'image_not_found'];
                }

                VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
                $image = VideoDesignImage::query()->whereKey($imageId)->firstOrFail();

                if ($image->status === DesignImageStatus::QUEUED->value) {
                    return [$image, 'already_queued'];
                }

                if (! in_array($image->status, DesignImageStatus::enqueueableValues(), true)) {
                    return [$image, 'not_enqueueable'];
                }

                $image->update([
                    'status' => DesignImageStatus::QUEUED->value,
                    'queued_at' => now(),
                    'render_error' => null,
                    'worker_id' => null,
                    'claim_token' => null,
                    'claimed_at' => null,
                    'lease_expires_at' => null,
                ]);

                return [$image, 'queued'];
            });
        } catch (ModelNotFoundException) {
            return [null, 'image_not_found'];
        }
    }

    /** @return iterable<VideoDesignImage> */
    public function claimForRender(
        string $workerId,
        string $claimToken,
        int $limit,
        DateTimeInterface $leaseExpiresAt,
    ): iterable {
        // MariaDB 10.4 khong co SKIP LOCKED. Mot UPDATE ... LIMIT la nguyen tu:
        // hai worker khong the cung khop mot dong sau khi status doi.
        $limit = max(1, min($limit, self::CLAIM_MAX));
        $now = now();

        DB::update(
            'UPDATE video_design_images
             SET status = ?, worker_id = ?, claim_token = ?, claimed_at = ?,
                 lease_expires_at = ?, updated_at = ?
             WHERE status = ?
             ORDER BY queued_at, id
             LIMIT '.$limit,
            [
                DesignImageStatus::CLAIMED->value, $workerId, $claimToken, $now,
                $leaseExpiresAt, $now, DesignImageStatus::QUEUED->value,
            ],
        );

        return VideoDesignImage::query()
            ->where('claim_token', $claimToken)
            ->orderBy('queued_at')
            ->orderBy('id')
            ->get();
    }

    public function heartbeat(
        string $imageId,
        string $workerId,
        string $claimToken,
        DateTimeInterface $leaseExpiresAt,
    ): bool {
        // Dap dau tien nang CLAIMED -> RENDERING. Khong co endpoint rieng cho
        // buoc do, giong het VideoShotRepository::heartbeat().
        return VideoDesignImage::query()
            ->whereKey($imageId)
            ->where('worker_id', $workerId)
            ->where('claim_token', $claimToken)
            ->whereIn('status', DesignImageStatus::leasedValues())
            ->where('lease_expires_at', '>', now())
            ->update([
                'status' => DesignImageStatus::RENDERING->value,
                'lease_expires_at' => $leaseExpiresAt,
            ]) === 1;
    }

    public function reclaimExpiredLeases(): int
    {
        return VideoDesignImage::query()
            ->whereIn('status', DesignImageStatus::leasedValues())
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<=', now())
            ->update([
                'status' => DesignImageStatus::QUEUED->value,
                'worker_id' => null,
                'claim_token' => null,
                'claimed_at' => null,
                'lease_expires_at' => null,
            ]);
    }

    /**
     * `$workerId` va `$claimToken` BAT BUOC — khong co duong bao ket qua nao ma
     * khong cam claim. Duong shot co cua null de runner cu chua gui worker_id van
     * chay duoc; o day khong co runner cu nao, nen cua do la no vo co.
     *
     * @param  list<array<string, mixed>>  $renders
     * @return array{0: ?VideoDesignImage, 1: string}
     */
    public function reportResult(
        string $imageId,
        bool $success,
        ?string $renderError,
        array $renders,
        string $workerId,
        string $claimToken,
    ): array {
        if ($success && $renders === []) {
            // Thanh cong ma khong co item nao la trang thai vo nghia: o se mang
            // `rendered` nhung khong co artifact, khong co so cai, khong co tien.
            return [null, 'result_success_without_renders'];
        }

        $invalid = $this->firstInvalidItem($renders);

        if ($invalid !== null) {
            return [null, $invalid];
        }

        try {
            return DB::transaction(function () use (
                $imageId, $success, $renderError, $renders, $workerId, $claimToken,
            ) {
                $projectId = VideoDesignImage::query()->whereKey($imageId)->value('project_id');

                if ($projectId === null) {
                    return [null, 'image_not_found'];
                }

                VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
                $image = VideoDesignImage::query()->whereKey($imageId)->firstOrFail();

                // Nhan dien PHAT LAI truoc cong claim. Sau luot bao cao dau tien
                // claim da duoc tra lai, nen outbox cua Python phat lai se dam vao
                // `claim_not_owned_or_expired` va thu lai vo tan. Duong shot khong
                // dinh vi no kiem idempotency truoc quyen so huu — thu tu do moi dung.
                if ($this->alreadyRecorded($image->id, $renders)) {
                    return [$image, 'replayed'];
                }

                if (! $this->ownsTheClaim($image, $workerId, $claimToken)) {
                    return [null, 'claim_not_owned_or_expired'];
                }

                foreach ($renders as $item) {
                    $this->record($image, $projectId, $item);
                }

                $image->update([
                    'status' => ($success ? DesignImageStatus::RENDERED : DesignImageStatus::FAILED)->value,
                    'render_error' => $success ? null : ($renderError ?: 'render that bai, worker khong noi ly do'),
                    'worker_id' => null,
                    'claim_token' => null,
                    'claimed_at' => null,
                    'lease_expires_at' => null,
                ]);

                return [$image->refresh(), 'recorded'];
            });
        } catch (ModelNotFoundException) {
            return [null, 'image_not_found'];
        }
    }

    /** @param list<array<string, mixed>> $renders */
    private function firstInvalidItem(array $renders): ?string
    {
        foreach ($renders as $index => $item) {
            if (! is_array($item)) {
                return "render_item_{$index}_not_an_object";
            }

            if (($item['idempotency_key'] ?? '') === '') {
                // Unique (design_image_id, idempotency_key) KHONG chan duoc nhieu
                // dong NULL. Thieu khoa nay thi outbox phat lai = tra tien hai lan.
                return "render_item_{$index}_missing_idempotency_key";
            }

            $event = is_array($item['render'] ?? null) ? $item['render'] : [];

            foreach (self::REQUIRED_EVENT_KEYS as $key) {
                if (($event[$key] ?? '') === '') {
                    // So cai co tien ma khong biet da goi model nao voi prompt nao
                    // thi dung bang khong — do la ly do bang nay ton tai.
                    return "render_item_{$index}_missing_render_{$key}";
                }
            }

            $hasPath = ($item['storage_path'] ?? '') !== '';
            $hasSha = ($item['artifact_sha256'] ?? '') !== '';

            if ($hasPath !== $hasSha) {
                return "render_item_{$index}_needs_both_storage_path_and_artifact_sha256";
            }

            if ($hasSha && preg_match('/^[0-9a-f]{64}$/', (string) $item['artifact_sha256']) !== 1) {
                return "render_item_{$index}_artifact_sha256_is_not_a_sha256";
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $renders */
    private function alreadyRecorded(string $imageId, array $renders): bool
    {
        if ($renders === []) {
            return false;
        }

        $keys = array_column($renders, 'idempotency_key');

        return VideoRender::query()
            ->where('design_image_id', $imageId)
            ->whereIn('idempotency_key', $keys)
            ->count() === count($keys);
    }

    private function ownsTheClaim(VideoDesignImage $image, string $workerId, string $claimToken): bool
    {
        return $workerId !== ''
            && $claimToken !== ''
            && $image->worker_id === $workerId
            && $image->claim_token === $claimToken
            && in_array($image->status, DesignImageStatus::leasedValues(), true)
            && $image->lease_expires_at !== null
            && $image->lease_expires_at->isFuture();
    }

    /** @param array<string, mixed> $item */
    private function record(VideoDesignImage $image, string $projectId, array $item): void
    {
        // Phat lai MOT PHAN: khong xay ra khi ca lo ghi trong mot transaction,
        // nhung giu lai vi day la hang rao cuoi truoc unique cua DB.
        if (VideoRender::query()
            ->where('design_image_id', $image->id)
            ->where('idempotency_key', $item['idempotency_key'])
            ->exists()) {
            return;
        }

        $event = $item['render'];
        $sentPrompt = (string) $event['sent_prompt'];
        $hasArtifact = ($item['artifact_sha256'] ?? '') !== '';

        $render = VideoRender::create([
            'design_image_id' => $image->id,
            'attempt_no' => ((int) VideoRender::query()->where('design_image_id', $image->id)->max('attempt_no')) + 1,
            'idempotency_key' => $item['idempotency_key'],
            'render_kind' => $event['render_kind'],
            'provider' => $event['provider'],
            'model' => $event['model'],
            'sent_prompt' => $sentPrompt,
            // Bam LAI tu chinh chuoi vua ghi, khong nhan sha tu payload: bat bien
            // cua RenderLedgerIntegrityTest khong duoc phu thuoc vao worker trung thuc.
            'prompt_sha256' => hash('sha256', $sentPrompt),
            'request_sha256' => $event['request_sha256'] ?? null,
            'negative_prompt' => $event['negative_prompt'] ?? null,
            'source_kind' => $event['source_kind'] ?? 'text',
            'artifact_path' => $item['storage_path'] ?? null,
            'artifact_dir' => $event['artifact_dir'] ?? null,
            'width' => $item['width'] ?? null,
            'height' => $item['height'] ?? null,
            'bytes' => $item['bytes'] ?? null,
            'cost_usd' => (float) ($item['cost'] ?? 0),
            'provider_ms' => $event['provider_ms'] ?? null,
            'status' => $hasArtifact ? 'succeeded' : 'failed',
            'error_message' => $item['error'] ?? null,
            'proof_verified' => false,
        ]);

        if ($hasArtifact) {
            VideoArtifact::create([
                'project_id' => $projectId,
                'design_image_id' => $image->id,
                'render_id' => $render->id,
                'artifact_type' => 'image',
                'role' => 'candidate',
                'storage_disk' => $item['storage_disk'] ?? 'public',
                'storage_path' => $item['storage_path'],
                'mime_type' => $item['mime_type'] ?? 'image/png',
                'file_size' => $item['bytes'] ?? null,
                'sha256' => $item['artifact_sha256'],
                'width' => $item['width'] ?? null,
                'height' => $item['height'] ?? null,
            ]);
        }

        VideoCostEntry::create([
            'project_id' => $projectId,
            'session_id' => null,
            'entity_type' => self::ENTITY_TYPE,
            'entity_id' => $image->id,
            'stage' => 'render',
            'provider' => $event['provider'],
            'model' => $event['model'],
            'usage_type' => $event['render_kind'],
            'quantity' => 1,
            'unit' => 'render',
            'cost_usd' => (float) ($item['cost'] ?? 0),
        ]);
    }
}
