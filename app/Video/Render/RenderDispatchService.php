<?php

declare(strict_types=1);

namespace App\Video\Render;

use App\Models\VideoRender;
use App\Video\Render\Enums\RenderStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class RenderDispatchService
{
    public function create(
        ?string $sessionId,
        string $assetId,
        string $provider,
        string $model,
        string $idempotencyKey,
        string $requestJson,
        string $requestHash,
        string $canonicalRevisionId,
        string $canonicalHash,
        string $projectionHash,
        string $constraintSetHash,
        string $promptSpecHash,
        string $providerPromptPlanHash,
        string $promptHash,
        int $maxAttempts = 3,
        ?string $designImageId = null,
        ?string $renderId = null,
        string $renderKind = 'image',
        ?string $shotId = null,
        string $executionPurpose = 'production',
    ): VideoRender {
        if (! preg_match('/^[a-f0-9]{64}$/', $requestHash)) {
            throw new RuntimeException('Invalid request hash.');
        }

        $payload = json_decode($requestJson, true);
        $sentPrompt = is_array($payload)
            && isset($payload['compiled_prompt']['prompt'])
            && is_string($payload['compiled_prompt']['prompt'])
                ? $payload['compiled_prompt']['prompt']
                : '';
        $legacyPromptHash = hash('sha256', $sentPrompt);

        return DB::transaction(function () use (
            $sessionId,
            $assetId,
            $designImageId,
            $provider,
            $model,
            $idempotencyKey,
            $requestJson,
            $requestHash,
            $canonicalRevisionId,
            $canonicalHash,
            $projectionHash,
            $constraintSetHash,
            $promptSpecHash,
            $providerPromptPlanHash,
            $promptHash,
            $maxAttempts,
            $renderId,
            $renderKind,
            $shotId,
            $executionPurpose,
            $sentPrompt,
            $legacyPromptHash,
        ): VideoRender {
            // Bang co unique rieng cho tung lan: (video_session_id, idempotency_key)
            // va (design_image_id, idempotency_key). Cau tra phai khop dung cap
            // rang buoc dang chi phoi, khong thi no doc mot lan roi dam vao lan kia.
            $existing = VideoRender::query()
                ->where(fn ($query) => $sessionId === null
                    ? $query->whereNull('video_session_id')
                    : $query->where('video_session_id', $sessionId))
                ->where(fn ($query) => $designImageId === null
                    ? $query->whereNull('design_image_id')
                    : $query->where('design_image_id', $designImageId))
                ->where(fn ($query) => $shotId === null
                    ? $query->whereNull('shot_id')
                    : $query->where('shot_id', $shotId))
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (! hash_equals((string) $existing->request_hash, $requestHash)) {
                    throw new RuntimeException('Idempotency key reused with different request.');
                }

                return $existing;
            }

            // `attempt_no` la bo dem cua duong cu (`DesignImageQueue::record()`),
            // duoc unique (design_image_id, attempt_no) bao ve. Lan Phan 14 truoc
            // day khong co design_image_id nen chua bao gio phai dat cot nay. Dung
            // dung mot cong thuc voi duong cu de hai lan khong danh so lech nhau.
            // Unique (shot_id, attempt_no) va (design_image_id, attempt_no) deu ton tai,
            // nen so thu tu phai dem theo DUNG chu so huu cua hang. De nguyen 1 thi
            // lan render thu hai cua cung mot shot khong bao gio vao duoc bang.
            $owner = match (true) {
                $designImageId !== null => ['design_image_id', $designImageId],
                $shotId !== null => ['shot_id', $shotId],
                default => null,
            };

            $attemptNo = $owner === null
                ? 1
                : ((int) VideoRender::query()->where($owner[0], $owner[1])->max('attempt_no')) + 1;

            // `id` khong nam trong $fillable, ma `verifyManifest()` lai doi
            // `manifest.render_id === $render->id`. De model tu sinh uuid thi hai
            // ben khong bao gio khop, va cu va cham do xay ra SAU khi da tra tien.
            $render = new VideoRender();

            $render->forceFill([
                'id' => $renderId ?? (string) Str::uuid(),
                'video_session_id' => $sessionId,
                'design_image_id' => $designImageId,
                'attempt_no' => $attemptNo,
                'asset_id' => $assetId,
                'provider' => $provider,
                'model' => $model,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'render_request_json' => $requestJson,
                'canonical_concept_revision_id' => $canonicalRevisionId,
                'canonical_hash' => $canonicalHash,
                'projection_hash' => $projectionHash,
                'constraint_set_hash' => $constraintSetHash,
                'prompt_spec_hash' => $promptSpecHash,
                'provider_prompt_plan_hash' => $providerPromptPlanHash,
                'prompt_hash' => $promptHash,
                'execution_status' => RenderStatus::QUEUED,
                'attempt_count' => 0,
                'max_attempts' => $maxAttempts,
                'execution_version' => 0,
                'render_kind' => $renderKind,
                'execution_purpose' => $executionPurpose,
                'shot_id' => $shotId,
                'sent_prompt' => $sentPrompt,
                'prompt_sha256' => $legacyPromptHash,
                'request_sha256' => $requestHash,
            ])->save();

            return $render;
        }, attempts: 3);
    }
}
