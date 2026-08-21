<?php

namespace App\Http\Controllers;

use App\Enums\DesignImageStatus;
use App\Enums\ImageModel;
use App\Enums\ImageQuality;
use App\Models\VideoDesignImage;
use App\Services\Video\DesignImageQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cong cho worker Python render o thiet ke anh. Controller MONG: no chi doc
 * request va dich ma may thanh ma HTTP — moi bat bien ve claim, idempotency va
 * tien nam trong `DesignImageQueue`.
 *
 * Token do middleware `video.token` giu, khong kiem lai o day.
 */
class VideoDesignImagesController extends Controller
{
    public function __construct(private DesignImageQueue $queue) {}

    public function queued()
    {
        [$usable, $broken] = VideoDesignImage::query()
            ->where('status', DesignImageStatus::QUEUED->value)
            ->orderBy('queued_at')
            ->orderBy('id')
            ->get()
            ->partition(fn (VideoDesignImage $image) => $this->isComplete($image));

        if ($broken->isNotEmpty()) {
            // Phat mot don hang thieu prompt/model cho worker la tra tien de lay
            // ve mot tam anh vo nghia. Giu lai, nhung phai KEU LEN — o bi bo qua
            // im lang se nam mai o `queued` va khong ai biet vi sao.
            Log::warning('video-design-images/queued: skipped cells with an incomplete prompt spec', [
                'image_codes' => $broken->pluck('image_code')->all(),
            ]);
        }

        return response()->json($usable->map(fn (VideoDesignImage $image) => $this->workOrder($image))->values()->all());
    }

    private function isComplete(VideoDesignImage $image): bool
    {
        $spec = $image->prompt_spec_json ?? [];

        foreach (['prompt', 'model', 'quality', 'size'] as $key) {
            if (($spec[$key] ?? '') === '') {
                return false;
            }
        }

        return ImageQuality::tryFrom((string) $spec['quality']) !== null
            && ImageModel::tryFrom((string) $spec['model']) !== null;
    }

    public function claim(Request $r)
    {
        $data = $r->validate([
            'worker_id' => 'required|string|max:100',
            'limit' => 'sometimes|integer|min:1|max:100',
            'lease_seconds' => 'sometimes|integer|min:60|max:3600',
            'image_id' => 'sometimes|uuid',
        ]);

        // Laravel sinh claim_token, Python chi gui worker_id — giong het
        // VideoSessionService::claimQueuedShots(). Khong tao bien the giao thuc moi.
        $claimToken = (string) Str::uuid();

        $claimed = $this->queue->claimForRender(
            $data['worker_id'],
            $claimToken,
            (int) ($data['limit'] ?? 10),
            now()->addSeconds((int) ($data['lease_seconds'] ?? 600)),
            $data['image_id'] ?? null,
        );

        return response()->json([
            'claim_token' => $claimToken,
            'images' => collect($claimed)->map(fn (VideoDesignImage $image) => $this->workOrder($image))->all(),
        ]);
    }

    public function heartbeat(Request $r, string $imageId)
    {
        $data = $r->validate([
            'worker_id' => 'required|string|max:100',
            'claim_token' => 'required|uuid',
            'lease_seconds' => 'sometimes|integer|min:60|max:3600',
        ]);

        $renewed = $this->queue->heartbeat(
            $imageId,
            $data['worker_id'],
            $data['claim_token'],
            now()->addSeconds((int) ($data['lease_seconds'] ?? 600)),
        );

        return $renewed
            ? response()->json(['status' => DesignImageStatus::RENDERING->value])
            : response()->json(['error' => 'claim_not_owned_or_expired'], 409);
    }

    public function result(Request $r, string $imageId)
    {
        $data = $r->validate([
            'success' => 'required|boolean',
            'render_error' => 'nullable|string|max:2000',
            'worker_id' => 'required|string|max:100',
            'claim_token' => 'required|uuid',
            'renders' => 'present|array|max:8',
            'renders.*' => 'array',
        ]);

        [$image, $reason] = $this->queue->reportResult(
            $imageId,
            $r->boolean('success'),
            $data['render_error'] ?? null,
            array_values($data['renders']),
            $data['worker_id'],
            $data['claim_token'],
        );

        if ($image === null) {
            // Ma may tra NGUYEN VAN, khong dich: Python can chuoi on dinh de
            // quyet dinh dead-letter hay thu lai, khong can cau chu dep.
            return response()->json(['error' => $reason], $this->statusFor($reason));
        }

        return response()->json(['status' => $image->status, 'result' => $reason]);
    }

    public function reclaimExpired()
    {
        return response()->json(['requeued' => $this->queue->reclaimExpiredLeases()]);
    }

    /**
     * Don hang cua mot o: du de render, khong hon. Nam gia tri thiet lap lay
     * thang tu `prompt_spec_json` ma man hinh da ghi — Python khong tu quyet
     * model, quality hay khung anh.
     *
     * @return array<string, mixed>
     */
    private function workOrder(VideoDesignImage $image): array
    {
        $spec = $image->prompt_spec_json ?? [];

        return [
            'id' => $image->id,
            'image_code' => $image->image_code,
            'project_id' => $image->project_id,
            'image_type' => $image->image_type,
            'prompt' => $spec['prompt'] ?? '',
            'model' => $spec['model'] ?? '',
            'quality' => $spec['quality'] ?? '',
            'size' => $spec['size'] ?? '',
            'variations' => (int) ($spec['variations'] ?? 1),
            'cost_estimate' => $this->costEstimate($spec),
            'prompt_sha256' => $image->prompt_sha256,
            'queued_at' => optional($image->queued_at)->toIso8601String(),
        ];
    }

    private function costEstimate(array $spec): float
    {
        return ImageQuality::fromSpecOrHigh($spec['quality'] ?? '')->estimatedCostUsd();
    }

    /**
     * 4xx nao la ngo cut, 4xx nao con cuu duoc. Python doc chinh ma nay de
     * quyet dinh giu hay bo callback trong outbox — sai mot ma la outbox quay
     * vong vinh vien hoac vut mat mot ket qua da ton tien.
     */
    private function statusFor(string $reason): int
    {
        return match ($reason) {
            'image_not_found' => 404,
            'claim_not_owned_or_expired' => 409,
            default => 422,
        };
    }
}
