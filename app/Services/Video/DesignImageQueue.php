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
use Illuminate\Support\Str;

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

    public const COLLECTION = 'design-images';

    private const DIRECT_WORKER = 'laravel:direct';

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
        ?string $imageId = null,
    ): iterable {
        // MariaDB 10.4 khong co SKIP LOCKED. Mot UPDATE ... LIMIT la nguyen tu:
        // hai worker khong the cung khop mot dong sau khi status doi.
        $limit = max(1, min($limit, self::CLAIM_MAX));
        $now = now();

        // `image_id` de nguoi bam nut tren man hinh nhan DUNG o cua ho, khong
        // vo tinh cam luon nhung o dang cho cua du an khac.
        $bindings = [
            DesignImageStatus::CLAIMED->value, $workerId, $claimToken, $now,
            $leaseExpiresAt, $now, DesignImageStatus::QUEUED->value,
        ];

        if ($imageId !== null) {
            $bindings[] = $imageId;
        }

        DB::update(
            'UPDATE video_design_images
             SET status = ?, worker_id = ?, claim_token = ?, claimed_at = ?,
                 lease_expires_at = ?, updated_at = ?
             WHERE status = ?'.($imageId === null ? '' : ' AND id = ?').'
             ORDER BY queued_at, id
             LIMIT '.$limit,
            $bindings,
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

    /**
     * Che do `queue` co worker nen nhat lai, nen tra o ve `queued` la dung. Che do
     * `direct` KHONG co worker nao ca — de `queued` thi man hinh trong nhu dang cho
     * ai do, ma khong ai chay, va o ket vinh vien. Danh `failed` kem ly do thi
     * nguoi dung thay ngay va bam render lai duoc.
     */
    public function reclaimExpiredLeases(): int
    {
        $direct = config('video.render_mode') === 'direct';

        return VideoDesignImage::query()
            ->whereIn('status', DesignImageStatus::leasedValues())
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<=', now())
            ->update([
                'status' => ($direct ? DesignImageStatus::FAILED : DesignImageStatus::QUEUED)->value,
                'render_error' => $direct ? 'Direct render exceeded its lease before reporting a result' : null,
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
                    'render_error' => $success ? null : ($renderError ?: 'Render failed and the worker gave no reason'),
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

    /**
     * Giu o TRUOC khi goi provider, ngay tren hang du lieu — dung giao thuc ma
     * `PlanningStageStore::claimProjectStage()` dung cho Haiku/Sonnet.
     *
     * Khong co buoc nay thi hai lan bam cach nhau 5 giay trong luc render deu
     * thay `candidate` va deu chay: TRA TIEN HAI LAN. Dedupe theo prompt hash
     * khong cuu duoc ca do — no chan tao o trung, khong chan render o cu hai lan.
     *
     * `lease_expires_at` de lenh thu hoi co san nhat len khi request chet giua
     * chung; khong co no thi o ket o `rendering` vinh vien.
     *
     * @return array{0: ?VideoDesignImage, 1: ?string, 2: string} [$image, $claimToken, $reason]
     */
    public function claimForDirectRender(string $imageId, int $leaseSeconds = 90): array
    {
        $claimToken = (string) Str::uuid();

        try {
            return DB::transaction(function () use ($imageId, $leaseSeconds, $claimToken) {
                $projectId = VideoDesignImage::query()->whereKey($imageId)->value('project_id');

                if ($projectId === null) {
                    return [null, null, 'image_not_found'];
                }

                VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
                $image = VideoDesignImage::query()->whereKey($imageId)->firstOrFail();

                if (! in_array($image->status, DesignImageStatus::enqueueableValues(), true)) {
                    return [$image, null, 'not_enqueueable'];
                }

                $now = now();
                $image->update([
                    'status' => DesignImageStatus::RENDERING->value,
                    'worker_id' => self::DIRECT_WORKER,
                    'claim_token' => $claimToken,
                    'claimed_at' => $now,
                    'lease_expires_at' => $now->copy()->addSeconds($leaseSeconds),
                    'queued_at' => $image->queued_at ?? $now,
                    'render_error' => null,
                ]);

                return [$image, $claimToken, 'claimed'];
            });
        } catch (ModelNotFoundException) {
            return [null, null, 'image_not_found'];
        }
    }

    /**
     * Ghi ket qua cua duong thang. Python khong goi nguoc Laravel, nen Laravel tu
     * ghi tu thu doc duoc tren stdout.
     *
     * VAN kiem claim token: lease co the da bi thu trong luc render (request cham,
     * lenh reclaim chay), va o co the da thuoc ve mot luot khac. Ghi de len ket qua
     * cua nguoi khac la lam hong ca hai.
     *
     * Dung lai DUNG `record()` cua duong hang doi. So cai chi duoc phep co MOT noi
     * ghi — hai ban cai dat thi som muon cung lech nhau ve tien.
     *
     * Mat gi so voi duong hang doi: khong outbox. Tien trinh chet SAU khi provider
     * da tinh tien thi khong co gi phat lai — xem `video:sweep-orphan-design-renders`.
     *
     * @param  list<array<string, mixed>>  $renders
     * @return array{0: ?VideoDesignImage, 1: string}
     */
    public function recordDirectResult(
        string $imageId,
        string $claimToken,
        bool $success,
        ?string $renderError,
        array $renders,
    ): array {
        if ($success && $renders === []) {
            return [null, 'result_success_without_renders'];
        }

        $invalid = $this->firstInvalidItem($renders);

        if ($invalid !== null) {
            return [null, $invalid];
        }

        try {
            return DB::transaction(function () use ($imageId, $claimToken, $success, $renderError, $renders) {
                $projectId = VideoDesignImage::query()->whereKey($imageId)->value('project_id');

                if ($projectId === null) {
                    return [null, 'image_not_found'];
                }

                VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
                $image = VideoDesignImage::query()->whereKey($imageId)->firstOrFail();

                if ($this->alreadyRecorded($image->id, $renders)) {
                    return [$image, 'replayed'];
                }

                if (! $this->ownsTheClaim($image, self::DIRECT_WORKER, $claimToken)) {
                    return [null, 'claim_not_owned_or_expired'];
                }

                foreach ($renders as $item) {
                    $this->record($image, $projectId, $item);
                }

                $image->update([
                    'status' => ($success ? DesignImageStatus::RENDERED : DesignImageStatus::FAILED)->value,
                    'render_error' => $success ? null : ($renderError ?: 'Render failed and the worker gave no reason'),
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
            // OpenAI KHONG tra ve so do. Giu nguyen van thu no NOI — day la thu
            // duy nhat sau nay doi chieu duoc `cost_usd` (uoc luong) voi hoa don
            // that. Vut di thi khong bao gio lay lai duoc.
            'provider_request_id' => $item['provider_request_id'] ?? null,
            'response_json' => is_array($item['provider_usage'] ?? null) ? $item['provider_usage'] : null,
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
