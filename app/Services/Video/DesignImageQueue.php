<?php

namespace App\Services\Video;

use App\Enums\DesignImageStatus;
use App\Models\VideoArtifact;
use App\Models\VideoCostEntry;
use App\Models\VideoDesignImage;
use App\Models\VideoProject;
use App\Models\VideoRender;
use App\Video\Media\RenderPriceBackfill;
use App\Video\Render\Enums\RenderStatus;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

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
    public function __construct(
        private readonly RenderPriceBackfill $backfill = new RenderPriceBackfill,
    ) {}

    public const COLLECTION = 'design-images';

    private const DIRECT_WORKER = 'laravel:direct';

    private const ENTITY_TYPE = 'design_image';

    private const REQUIRED_EVENT_KEYS = ['provider', 'model', 'render_kind', 'sent_prompt'];

    /**
     * Laravel la renderer duy nhat. Lease het han khong con worker Python nao nhat
     * lai, nen cell phai that bai ro rang de nguoi dung chu dong render lai.
     */
    public function reclaimExpiredLeases(): int
    {
        return VideoDesignImage::query()
            ->whereIn('status', DesignImageStatus::leasedValues())
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<=', now())
            ->update([
                'status' => DesignImageStatus::FAILED->value,
                'render_error' => 'Direct render exceeded its lease before reporting a result',
                'worker_id' => null,
                'claim_token' => null,
                'claimed_at' => null,
                'lease_expires_at' => null,
            ]);
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
     * @param  list<string>|null  $acceptStatuses  Thu hep tap trang thai duoc nhan.
     *                                             `null` giu nguyen hop dong cu.
     * @return array{0: ?VideoDesignImage, 1: ?string, 2: string} [$image, $claimToken, $reason]
     */
    public function claimForDirectRender(
        string $imageId,
        int $leaseSeconds = 90,
        ?array $acceptStatuses = null,
    ): array {
        $enqueueable = DesignImageStatus::enqueueableValues();
        $accept = $acceptStatuses === null
            ? $enqueueable
            : array_values(array_intersect($acceptStatuses, $enqueueable));

        if ($accept === []) {
            return [null, null, 'no_acceptable_status'];
        }

        $claimToken = (string) Str::uuid();

        try {
            [$image, $token, $reason] = DB::transaction(function () use ($imageId, $leaseSeconds, $claimToken, $accept, $enqueueable) {
                $projectId = VideoDesignImage::query()->whereKey($imageId)->value('project_id');

                if ($projectId === null) {
                    return [null, null, 'image_not_found'];
                }

                VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
                $image = VideoDesignImage::query()->whereKey($imageId)->firstOrFail();

                if (! in_array($image->status, $accept, true)) {
                    return [$image, null, in_array($image->status, $enqueueable, true)
                        ? 'retry_not_confirmed'
                        : 'not_enqueueable'];
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

        if ($token === null) {
            return [$image, null, $reason];
        }

        // Da cam claim, chua goi provider: day la cho duy nhat bo sung duoc gia cho o
        // tao truoc hop dong snapshot.
        [$priced, $why] = $this->backfill->apply($image, self::DIRECT_WORKER, $token);

        if (! $priced) {
            $released = $this->releaseWithError($image, self::DIRECT_WORKER, $token, $why);

            return [$image->refresh(), null, $released ? $why : 'claim_lost_before_pricing'];
        }

        return [$image, $token, $reason];
    }

    /**
     * Nha claim va danh that bai, nhung CHI khi o van con thuoc ve chinh claim nay VA
     * lease chua het han. Lease roi sang worker khac hoac het gio thi quyen danh that
     * bai cung mat theo — hang do khong duoc dung toi nua.
     *
     * @return bool ghi duoc hay khong; `false` nghia la claim khong con la cua minh
     */
    private function releaseWithError(
        VideoDesignImage $image,
        string $workerId,
        string $claimToken,
        string $reason,
    ): bool {
        return VideoDesignImage::query()
            ->whereKey($image->id)
            ->where('worker_id', $workerId)
            ->where('claim_token', $claimToken)
            ->whereIn('status', DesignImageStatus::leasedValues())
            ->where('lease_expires_at', '>', now())
            ->update([
                'status' => DesignImageStatus::FAILED->value,
                'render_error' => $reason,
                'worker_id' => null,
                'claim_token' => null,
                'claimed_at' => null,
                'lease_expires_at' => null,
            ]) === 1;
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
                    $this->assertConfirmedMoney($item);
                }

                foreach ($renders as $item) {
                    $this->record($image, $projectId, $item);
                }

                $this->finaliseImage($image, $success, $renderError);

                return [$image->refresh(), 'recorded'];
            });
        } catch (ModelNotFoundException) {
            return [null, 'image_not_found'];
        }
    }

    /**
     * Duong canonical: hang `video_renders` da do RenderDispatchService
     * tao va may trang thai Phan 14 dan toi `succeeded`, nen o day KHONG duoc tao
     * them hang nua — chi gan artifact vao hang do roi ket so o anh.
     *
     * @param  list<array<string, mixed>>  $artifacts
     * @return array{0: ?VideoDesignImage, 1: string}
     */
    public function recordCanonicalResult(
        string $imageId,
        ?string $claimToken,
        bool $success,
        ?string $renderError,
        ?VideoRender $render,
        array $artifacts,
    ): array {
        if ($success && ($render === null || $artifacts === [])) {
            return [null, 'result_success_without_renders'];
        }

        try {
            return DB::transaction(function () use ($imageId, $claimToken, $success, $renderError, $render, $artifacts) {
                $projectId = VideoDesignImage::query()->whereKey($imageId)->value('project_id');

                if ($projectId === null) {
                    return [null, 'image_not_found'];
                }

                VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
                $image = VideoDesignImage::query()->whereKey($imageId)->firstOrFail();

                // `$claimToken === null` la che do HOA GIAI: khong con lease nao
                // dang giu vi request truoc da chet. Bang chung thay the la hang
                // render DA o `succeeded` — ma dua no toi do la `complete()`, von
                // doi dung claim_token cua hang render va doi chieu ca manifest.
                // Chuoi bang chung khong dut, chi doi chu the.
                if ($claimToken === null) {
                    if ($render === null || $render->execution_status !== RenderStatus::SUCCEEDED) {
                        return [null, 'reconcile_requires_succeeded_render'];
                    }
                } elseif (! $this->ownsTheClaim($image, self::DIRECT_WORKER, $claimToken)) {
                    return [null, 'claim_not_owned_or_expired'];
                }

                foreach ($artifacts as $artifact) {
                    // Khoa phat lai la (render, sha256): mot chuoi byte chi duoc
                    // ghi vao bang mot lan du tien trinh chay lai bao nhieu luot.
                    $exists = VideoArtifact::query()
                        ->where('render_id', $render->id)
                        ->where('sha256', $artifact['sha256'])
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    VideoArtifact::create([
                        'project_id' => $projectId,
                        'design_image_id' => $image->id,
                        'render_id' => $render->id,
                        'artifact_type' => 'image',
                        'role' => 'candidate',
                        'storage_disk' => $artifact['storage_disk'],
                        'storage_path' => $artifact['storage_path'],
                        'mime_type' => $artifact['mime_type'] ?? 'image/png',
                        'file_size' => $artifact['file_size'] ?? null,
                        'sha256' => $artifact['sha256'],
                        'width' => $artifact['width'] ?? null,
                        'height' => $artifact['height'] ?? null,
                    ]);
                }

                $this->finaliseImage($image, $success, $renderError);

                return [$image->refresh(), 'recorded'];
            });
        } catch (ModelNotFoundException) {
            return [null, 'image_not_found'];
        }
    }

    private function finaliseImage(VideoDesignImage $image, bool $success, ?string $renderError): void
    {
        $image->update([
            'status' => ($success ? DesignImageStatus::RENDERED : DesignImageStatus::FAILED)->value,
            'render_error' => $success ? null : ($renderError ?: 'Render failed and the worker gave no reason'),
            'worker_id' => null,
            'claim_token' => null,
            'claimed_at' => null,
            'lease_expires_at' => null,
        ]);
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

    /**
     * `cost_usd` chi duoc mang tien DA XAC NHAN: provider bao so, hoac da doi soat.
     * Uoc tinh di duong `metadata_json.estimated_cost_usd`. Chot nay nam trong
     * transaction, nen vi pham thi ca luot ghi bi rollback chu khong de lai nua so.
     *
     * Ap cho CA HAI duong bao ket qua. Duong callback muon ghi tien that thi phai khai
     * `pricing: reported` — khong co cua sau nao cho mot con so khong ai xac nhan.
     *
     * Khong ep kieu truoc khi kiem: chuoi 'abc' ep thanh 0.0 va se lot qua.
     *
     * @param  array<string, mixed>  $item
     */
    private function assertConfirmedMoney(array $item): void
    {
        $cost = $item['cost'] ?? null;

        // Khai `reported` khong phai la mot tam ve: con so di kem van phai la mot so
        // tien doc duoc. Chuoi 'abc' ep thanh 0.0 va se lot qua neu kiem sau khi ep.
        if (in_array($item['pricing'] ?? null, ['reported', 'reconciled'], true)) {
            if ((is_int($cost) || is_float($cost)) && is_finite((float) $cost) && (float) $cost >= 0) {
                return;
            }

            throw new RuntimeException(
                'pricing reported/reconciled phai di kem mot so tien doc duoc, nhan duoc: '
                .var_export($cost, true),
            );
        }

        if ($cost === null || $cost === 0 || $cost === 0.0) {
            return;
        }

        throw new RuntimeException(
            'cost_usd cua design image chi duoc mang tien da xac nhan — uoc tinh nam trong metadata.',
        );
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
            'source_render_id' => $event['source_render_id'] ?? null,
            'request_json' => $event['request_json'] ?? null,
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
            // Cot NOT NULL, nen `unpriced` van phai ghi 0. `metadata_json` la cho
            // duy nhat phan biet duoc "chua dinh gia" voi "mien phi".
            'cost_usd' => (float) ($item['cost'] ?? 0),
            // So cai chi duoc THEM, nen no phai tu du de doi soat sau nay: gia don vi,
            // uoc tinh CUA RIENG DONG NAY, phien ban bang gia, va usage nguyen van.
            // Giu lai o `prompt_spec_json` thoi la khong du — o co the bi ghi de.
            'metadata_json' => array_filter([
                'pricing' => (string) ($item['pricing'] ?? 'estimated'),
                'unit_cost_usd' => $item['unit_cost_usd'] ?? null,
                'estimated_cost_usd' => $item['estimated_cost_usd'] ?? null,
                'pricing_version' => $item['pricing_version'] ?? null,
                'pricing_backfilled_at' => $item['pricing_backfilled_at'] ?? null,
                'provider_usage' => $item['provider_usage'] ?? null,
            ], static fn ($value): bool => $value !== null),
        ]);
    }
}
