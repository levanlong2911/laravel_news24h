<?php

declare(strict_types=1);

namespace App\Video\Render\Controllers;

use App\Models\VideoRender;
use App\Video\Render\Attempts\RenderAttemptService;
use App\Video\Render\Claims\RenderClaimService;
use App\Video\Render\Claims\RenderLeaseService;
use App\Video\Render\DTO\RenderAttemptUsage;
use App\Video\Render\Enums\RenderFailureClass;
use App\Video\Render\RenderArtifactRecoveryService;
use App\Video\Render\RenderCheckpointService;
use App\Video\Render\RenderFailureService;
use App\Video\Render\RenderRetryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class RenderWorkerController
{
    public function claim(Request $request, RenderClaimService $claims): Response|JsonResponse
    {
        $validated = $request->validate([
            'worker_id' => ['required', 'string', 'max:190'],
        ]);

        $claim = $claims->claimNext($validated['worker_id']);

        if ($claim === null) {
            return response(status: 204);
        }

        return response()->json([
            'render_id' => $claim->renderId,
            'claim_token' => $claim->claimToken,
            'claim_generation' => $claim->claimGeneration,
            'attempt_no' => $claim->attemptNo,
            'lease_expires_at' => $claim->leaseExpiresAt->format(DATE_ATOM),
            'request_hash' => $claim->requestHash,
            'render_request_json' => $claim->renderRequestJson,
        ]);
    }

    public function heartbeat(Request $request, VideoRender $render, RenderLeaseService $leases): JsonResponse
    {
        $data = $request->validate([
            'claim_token' => ['required', 'uuid'],
            'worker_id' => ['required', 'string', 'max:190'],
        ]);

        $leases->heartbeat($render->id, $data['claim_token'], $data['worker_id']);

        return response()->json(['ok' => true]);
    }

    public function checkpoint(Request $request, VideoRender $render, RenderAttemptService $attempts): JsonResponse
    {
        $data = $request->validate([
            'claim_token' => ['required', 'uuid'],
            'stage' => ['required', 'string', 'in:preparing,submitting'],
        ]);

        if ($data['stage'] === 'preparing') {
            $attempts->markPreparing($render->id, $data['claim_token']);
        } else {
            $attempts->markSubmitting($render->id, $data['claim_token']);
        }

        return response()->json(['ok' => true]);
    }

    public function submitted(Request $request, VideoRender $render, RenderAttemptService $attempts): JsonResponse
    {
        $data = $request->validate([
            'claim_token' => ['required', 'uuid'],
            'provider_request_id' => ['nullable', 'string', 'max:255'],
        ]);

        $attempts->markSubmitted($render->id, $data['claim_token'], $data['provider_request_id'] ?? null);

        return response()->json(['ok' => true]);
    }

    public function complete(Request $request, VideoRender $render, RenderCheckpointService $service): JsonResponse
    {
        $data = $request->validate([
            'claim_token' => ['required', 'uuid'],
            'request_hash' => ['required', 'string', 'size:64'],
            'provider_request_id' => ['nullable', 'string', 'max:255'],
            'artifact_manifest' => ['required', 'array'],
            'usage' => ['required', 'array'],
        ]);

        $service->complete(
            renderId: $render->id,
            claimToken: $data['claim_token'],
            requestHash: $data['request_hash'],
            providerRequestId: $data['provider_request_id'] ?? null,
            artifactManifest: $data['artifact_manifest'],
            usage: RenderAttemptUsage::fromArray($data['usage']),
        );

        return response()->json(['ok' => true]);
    }

    public function retry(Request $request, VideoRender $render, RenderRetryService $service): JsonResponse
    {
        $data = $request->validate([
            'claim_token' => ['required', 'uuid'],
            'failure_class' => ['required', 'string'],
            'error_code' => ['required', 'string', 'max:190'],
            'message' => ['required', 'string', 'max:5000'],
            'retry_after_seconds' => ['required', 'numeric', 'min:0', 'max:3600'],
        ]);

        $service->schedule(
            renderId: $render->id,
            claimToken: $data['claim_token'],
            failureClass: RenderFailureClass::from($data['failure_class']),
            errorCode: $data['error_code'],
            message: $data['message'],
            retryAfterSeconds: (float) $data['retry_after_seconds'],
        );

        return response()->json(['ok' => true]);
    }

    public function ambiguous(Request $request, VideoRender $render, RenderAttemptService $attempts): JsonResponse
    {
        $data = $request->validate([
            'claim_token' => ['required', 'uuid'],
            'error_code' => ['required', 'string', 'max:190'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $attempts->markAmbiguous($render->id, $data['claim_token'], $data['error_code'], $data['message']);

        return response()->json(['ok' => true]);
    }

    public function fail(Request $request, VideoRender $render, RenderFailureService $service): JsonResponse
    {
        $data = $request->validate([
            'claim_token' => ['required', 'uuid'],
            'failure_class' => ['required', 'string'],
            'error_code' => ['required', 'string', 'max:190'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $service->fail(
            renderId: $render->id,
            claimToken: $data['claim_token'],
            failureClass: RenderFailureClass::from($data['failure_class']),
            errorCode: $data['error_code'],
            message: $data['message'],
        );

        return response()->json(['ok' => true]);
    }

    public function recoverArtifacts(
        Request $request,
        VideoRender $render,
        RenderArtifactRecoveryService $service,
    ): JsonResponse {
        $data = $request->validate([
            'worker_id' => ['required', 'string', 'max:190'],
            'attempt_no' => ['required', 'integer', 'min:1'],
            'request_hash' => ['required', 'string', 'size:64'],
            'manifest_hash' => ['required', 'string', 'size:64'],
        ]);

        $claim = $service->claim(
            renderId: $render->id,
            attemptNo: (int) $data['attempt_no'],
            requestHash: $data['request_hash'],
            workerId: $data['worker_id'],
        );

        return response()->json([
            'render_id' => $claim->renderId,
            'attempt_no' => $claim->attemptNo,
            'claim_token' => $claim->claimToken,
            'claim_generation' => $claim->claimGeneration,
            'lease_expires_at' => $claim->leaseExpiresAt->format(DATE_ATOM),
        ]);
    }
}

