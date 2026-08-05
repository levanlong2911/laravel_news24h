<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ghi một dòng vào sổ cái cho mỗi lượt HTTP gửi tới nhà cung cấp LLM.
 *
 * Tách khỏi ClaudeWriterService có chủ ý: dịch vụ đó chỉ nên biết cách gọi API,
 * không nên biết kế toán. Đây cũng là chỗ nối sẵn cho decorator sau này bao
 * quanh mọi lời gọi LLM đa nhà cung cấp.
 *
 * ── Kế toán KHÔNG được phép làm hỏng pipeline ───────────────────────────────
 *
 * Mọi lỗi ghi sổ đều bị nuốt và chỉ log lại. Mất một dòng ledger là mất khả
 * năng đối soát; ném exception ở đây là mất cả bài viết. Đổi lấy cái nào thì
 * rõ rồi.
 */
final class RequestLedgerRecorder
{
    private const TABLE = 'claude_request_ledger';

    /**
     * @param  array<string, mixed>|null  $usageJson  nguyên bản $json['usage'] từ response
     */
    public function record(
        string  $callUuid,
        int     $attempt,
        string  $model,
        string  $modelType,
        string  $pricingVersion,
        string  $billed,
        \DateTimeInterface $startedAt,
        ?\DateTimeInterface $finishedAt = null,
        ?int    $latencyMs = null,
        ?int    $httpStatus = null,
        ?string $requestId = null,
        ?string $parentRequestId = null,
        ?string $articleId = null,
        ?string $pipelineRunId = null,
        ?string $phase = null,
        ?TokenUsage $usage = null,
        ?array  $usageJson = null,
        ?string $promptHash = null,
        ?string $responseHash = null,
        ?string $retryReason = null,
        ?string $error = null,
        string  $vendor = 'anthropic',
    ): void {
        try {
            DB::table(self::TABLE)->insert([
                'call_uuid'             => $callUuid,
                'attempt'               => $attempt,
                'parent_request_id'     => $parentRequestId,

                'article_id'            => $articleId,
                'pipeline_run_id'       => $pipelineRunId,
                'phase'                 => $phase,

                'vendor'                => $vendor,
                'model'                 => $model,
                'model_type'            => $modelType,
                'request_id'            => $requestId,

                'input_tokens'          => $usage?->inputTokens         ?? 0,
                'output_tokens'         => $usage?->outputTokens        ?? 0,
                'cache_creation_tokens' => $usage?->cacheCreationTokens ?? 0,
                'cache_read_tokens'     => $usage?->cacheReadTokens     ?? 0,
                'usage_json'            => $usageJson === null ? null : json_encode($usageJson, JSON_UNESCAPED_UNICODE),

                'pricing_version'       => $pricingVersion,

                'prompt_hash'           => $promptHash,
                'response_hash'         => $responseHash,

                'started_at'            => $startedAt,
                'finished_at'           => $finishedAt,
                'latency_ms'            => $latencyMs,

                'http_status'           => $httpStatus,
                'billed'                => $billed,
                'retry_reason'          => $retryReason,
                'error'                 => $error === null ? null : mb_substr($error, 0, 2000),

                'created_at'            => now(),
            ]);
        } catch (\Throwable $e) {
            // Nuốt có chủ ý — xem docblock đầu file.
            Log::error('[Ledger] Không ghi được dòng sổ cái', [
                'call_uuid' => $callUuid,
                'attempt'   => $attempt,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Request này có bị tính tiền không.
     *
     * Tách khỏi http_status vì đối soát cần đúng chiều này, không cần biết mã lỗi:
     *   200                 → đã sinh token, chắc chắn bị tính
     *   400 / 429           → bị chặn trước khi vào inference, không tính
     *   5xx / 529           → lỗi phía server, gần như chắc chắn không tính
     *   timeout / mất kết nối → KHÔNG BIẾT. Model có thể đã sinh xong và bị tính,
     *                          mà mình không bao giờ nhận được response để biết.
     */
    public static function billedFrom(?int $httpStatus): string
    {
        return match (true) {
            $httpStatus === 200            => 'yes',
            $httpStatus === null           => 'unknown',
            $httpStatus >= 400             => 'no',
            default                        => 'unknown',
        };
    }
}
