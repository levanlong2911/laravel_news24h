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
 *
 * ── Vì sao tự json_encode ───────────────────────────────────────────────────
 *
 * Bảng này chưa có Eloquent model nên không có tầng $casts để nhờ. DB::table()
 * là tầng persistence ở đây, và nó không encode mảng giúp. Ngày nào bảng cần
 * đọc nhiều và sinh model thì chuyển sang cast 'array' — không encode hai lần,
 * vì hiện chỉ encode đúng một lần.
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
        Billing $billed,
        \DateTimeInterface $startedAt,
        ?\DateTimeInterface $finishedAt = null,
        ?int    $latencyMs = null,
        ?int    $httpStatus = null,
        ?string $requestId = null,
        ?string $previousRequestId = null,
        ?RequestContext $context = null,
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
                'previous_request_id'   => $previousRequestId,

                'article_id'            => $context?->articleId,
                'pipeline_run_id'       => $context?->pipelineRunId,
                'phase'                 => $context?->phase?->value,

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
                'billed'                => $billed->value,
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
}
