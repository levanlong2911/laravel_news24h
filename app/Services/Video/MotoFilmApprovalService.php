<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Models\VideoFinal;
use App\Models\VideoProject;
use App\Models\VideoReviewDecision;
use App\Models\VideoSession;
use App\Models\VideoSessionEvent;
use App\Video\FinalComposition\CompositionReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A person approves a final film that was cut and checked; nothing approves one on its own.
 * The decision is bound to the film plan the final was cut from, to the final, and to the hash of its file, and the file is hashed
 * again when it is approved and again when it is handed on, so a file that changed on the disk is never passed on as approved.
 */
final class MotoFilmApprovalService
{
    public const ENTITY_TYPE = 'moto_film_final';

    public const SELECTION_KEY = 'moto_film_selection';

    public function __construct(
        private readonly MotoFilmPlanService $plans,
        private readonly MotoFilmRenderService $films,
    ) {}

    /**
     * @return array{0: VideoReviewDecision, 1: string} approved, or the decision an earlier send of the same operation took
     *
     * @throws RuntimeException when the final is not ready, was cut from another plan than the one in force, or its file changed
     */
    public function approve(string $projectId, string $finalId, string $expectedPlanHash, string $expectedSha, string $operationId, ?string $reviewerId): array
    {
        if (! Str::isUuid($operationId)) {
            throw new RuntimeException('Mã thao tác duyệt không hợp lệ — tải lại trang.');
        }

        return DB::transaction(function () use ($projectId, $finalId, $expectedPlanHash, $expectedSha, $operationId, $reviewerId): array {
            // Lock first, read after, and prove the plan was read from the revisions held: a source changed by a write that held the same locks is seen here, and one that comes later waits for this decision.
            $preview = $this->plans->lockedPreview($projectId);
            $project = VideoProject::query()->whereKey($projectId)->firstOrFail();
            $payloadHash = hash('sha256', json_encode([$projectId, $finalId, $expectedPlanHash, $expectedSha], JSON_THROW_ON_ERROR));
            $replayed = VideoSessionEvent::query()
                ->whereIn('session_id', VideoSession::query()->where('project_id', $projectId)->select('id'))
                ->where('operation_id', $operationId)
                ->first();

            if ($replayed !== null) {
                if ($replayed->event_type !== 'moto_film_approved' || ! hash_equals((string) $replayed->operation_payload_hash, $payloadHash)) {
                    throw new RuntimeException('Mã thao tác này đã dùng cho một yêu cầu khác — tải lại trang.');
                }

                return [VideoReviewDecision::query()->findOrFail($replayed->operation_result_json['decision_id'] ?? null), 'replayed'];
            }

            $final = $this->films->ownedFinal($projectId, $finalId);
            $film = (array) $final->plan_json['moto_film'];

            if ($final->status !== MotoFilmRenderService::READY) {
                throw new RuntimeException('Bản final này chưa dựng xong và kiểm đạt ('.$final->status.') — chỉ duyệt được file đã dựng và đã kiểm.');
            }

            if (! hash_equals((string) $film['film_plan_hash'], $expectedPlanHash)) {
                throw new RuntimeException('Bản final trên trang không phải bản này — tải lại trang.');
            }

            if (! $preview['ready'] || ! hash_equals((string) $preview['hash'], (string) $film['film_plan_hash'])) {
                throw new RuntimeException('Nguồn đã đổi sau khi dựng bản này (clip, kế hoạch shot, chính sách hoặc kiểu card) — bản này giữ trong lịch sử theo nguồn cũ, không duyệt được. Dựng lại theo nguồn mới.');
            }

            $file = $this->file($final);

            if ($file === null || ! hash_equals($file['sha256'], $expectedSha)) {
                throw new RuntimeException('File final không còn, không có receipt, hoặc khác hash đã kiểm — không duyệt được.');
            }

            $decision = VideoReviewDecision::create([
                'project_id' => $projectId,
                'session_id' => $final->session_id,
                'entity_type' => self::ENTITY_TYPE,
                'entity_id' => $final->id,
                'decision' => 'approved',
                'revision' => 1 + (int) VideoReviewDecision::query()->where('entity_type', self::ENTITY_TYPE)->where('entity_id', $final->id)->max('revision'),
                'reviewer_id' => $reviewerId,
                'reason' => 'Duyệt bản final của phim.',
                'metadata_json' => [
                    'operation_id' => $operationId,
                    'final_id' => (string) $final->id,
                    'film_plan_hash' => (string) $film['film_plan_hash'],
                    'film_plan_stage_id' => (string) $film['film_plan_stage_id'],
                    'clip_manifest_hash' => (string) $film['clip_manifest_hash'],
                    'manifest_hash' => (string) $final->manifest_hash,
                    'artifact_sha256' => $file['sha256'],
                ],
            ]);
            // Session events are append-only: the event is written once, after the decision it points to.
            VideoSessionEvent::create([
                'session_id' => $final->session_id,
                'event_type' => 'moto_film_approved',
                'entity_type' => 'final',
                'entity_id' => $final->id,
                'payload_json' => ['film_plan_hash' => $film['film_plan_hash'], 'artifact_sha256' => $file['sha256']],
                'operation_id' => $operationId,
                'operation_payload_hash' => $payloadHash,
                'operation_result_json' => ['status' => 'approved', 'decision_id' => (string) $decision->id],
            ]);
            $metadata = (array) ($project->metadata_json ?? []);
            $metadata[self::SELECTION_KEY] = [
                'final_id' => (string) $final->id,
                'decision_id' => (string) $decision->id,
                'film_plan_hash' => (string) $film['film_plan_hash'],
                'artifact_sha256' => $file['sha256'],
                'version' => 1 + (int) ($metadata[self::SELECTION_KEY]['version'] ?? 0),
                'selected_at' => now()->toIso8601String(),
            ];
            $project->forceFill(['metadata_json' => $metadata])->save();

            return [$decision, 'approved'];
        }, attempts: 3);
    }

    /**
     * @return array{final: VideoFinal, decision: VideoReviewDecision, path: string, sha256: string} the approved final, with its file hashed again now
     *
     * @throws RuntimeException when there is none, or its file is gone or changed
     */
    public function approvedFinal(string $projectId): array
    {
        $selection = $this->selection($projectId) ?? throw new RuntimeException('Dự án chưa có bản final được duyệt.');
        $final = $this->films->ownedFinal($projectId, (string) $selection['final_id']);
        $decision = VideoReviewDecision::query()->whereKey($selection['decision_id'])->where('entity_type', self::ENTITY_TYPE)->where('entity_id', $final->id)->where('decision', 'approved')->first();
        $file = $this->file($final);

        if ($decision === null || $file === null || ! hash_equals((string) $selection['artifact_sha256'], $file['sha256']) || ! hash_equals((string) $decision->metadata_json['artifact_sha256'], $file['sha256'])) {
            throw new RuntimeException('Bản final đã duyệt không còn khớp quyết định duyệt hoặc file của nó đã đổi — không bàn giao.');
        }

        return ['final' => $final, 'decision' => $decision, 'path' => $file['path'], 'sha256' => $file['sha256']];
    }

    /** @return ?array<string, mixed> */
    public function selection(string $projectId): ?array
    {
        $selection = VideoProject::query()->find($projectId)?->metadata_json[self::SELECTION_KEY] ?? null;

        return is_array($selection) ? $selection : null;
    }

    /**
     * @return ?array{path: string, sha256: string, bytes: int} the file of a cut final as its receipt describes it, hashed again now; null when the file or the receipt is gone, or they disagree
     */
    public function file(VideoFinal $final): ?array
    {
        $receipt = $this->receipt($final);

        if ($receipt === null) {
            return null;
        }

        $actual = hash_file('sha256', $receipt['path']);

        return $actual !== false && hash_equals($receipt['sha256'], $actual) ? ['path' => $receipt['path'], 'sha256' => $actual, 'bytes' => (int) filesize($receipt['path'])] : null;
    }

    /**
     * A copy of the file of a cut final for one response to send and delete, made only when the copy's bytes hash to what the receipt recorded.
     * The response sends the very bytes that were hashed, so a file changed on the disk is never sent, even between the check and the send.
     *
     * @return ?array{path: string, sha256: string, bytes: int} null when the file or its receipt is gone, or the bytes differ
     */
    public function servable(VideoFinal $final): ?array
    {
        $receipt = $this->receipt($final);

        if ($receipt === null) {
            return null;
        }

        $directory = rtrim((string) config('video.veo.compose_tmp_dir'), '/\\').DIRECTORY_SEPARATOR.'moto-film-serve';

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return null;
        }

        // A copy a broken connection never got to delete is swept on a later send.
        foreach (glob($directory.DIRECTORY_SEPARATOR.'*.mp4') ?: [] as $stale) {
            if ((int) @filemtime($stale) < time() - 3600) {
                @unlink($stale);
            }
        }

        $copy = $directory.DIRECTORY_SEPARATOR.Str::uuid()->toString().'.mp4';

        if (! @copy($receipt['path'], $copy)) {
            return null;
        }

        $actual = hash_file('sha256', $copy);

        if ($actual === false || ! hash_equals($receipt['sha256'], $actual)) {
            @unlink($copy);

            return null;
        }

        return ['path' => $copy, 'sha256' => $actual, 'bytes' => (int) filesize($copy)];
    }

    /**
     * @return ?array{path: string, sha256: string} the file of a cut final inside the folder of finals, with the hash its receipt recorded for this final and this manifest; it is not hashed again here, so it only describes the file: approving goes through file() and sending it through servable()
     */
    public function receipt(VideoFinal $final): ?array
    {
        $root = realpath((string) config('video.veo.compose_final_dir'));
        $path = $root === false || ! is_string($final->video_path) || $final->video_path === '' ? false : realpath($root.DIRECTORY_SEPARATOR.$final->video_path);

        if ($path === false || ! str_starts_with($path, rtrim($root, '/\\').DIRECTORY_SEPARATOR) || ! is_file($path)) {
            return null;
        }

        $receipt = CompositionReceipt::read(dirname($path).DIRECTORY_SEPARATOR.CompositionReceipt::FILE);
        $sha256 = $receipt['output']['sha256'] ?? null;

        if (! is_string($sha256) || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1 || ($receipt['final_id'] ?? null) !== (string) $final->id || ! hash_equals((string) $final->manifest_hash, (string) ($receipt['manifest_hash'] ?? ''))) {
            return null;
        }

        return ['path' => $path, 'sha256' => $sha256];
    }
}
