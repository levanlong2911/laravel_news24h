<?php

namespace App\Video\Llm;

use App\Services\Admin\ClaudeWriterService;
use Throwable;


final class ClaudeWriterAdapter implements LlmClient
{
    public function __construct(
        private readonly ClaudeWriterService $claudeWriterService,
    ) {}

    public function complete(LlmRequest $request): LlmResponse
    {
        $modelType = $request->model;

        if (! ClaudeWriterService::supports($modelType)) {
            throw new LlmUnavailable(
                "Model không hỗ trợ: {$modelType} — xem ClaudeWriterService::MODEL_CATALOG",
            );
        }

        $startedAt = microtime(true);

        try {
            $response = $this->claudeWriterService->generate(
                $request->input,
                $modelType,
                $request->instruction,
                $request->maxTokens,
                $request->temperature,
            );
        } catch (Throwable $e) {
            throw new LlmUnavailable(
                "Claude không gọi được ({$modelType}): {$e->getMessage()}",
                previous: $e,
            );
        }

        $llmResponse = new LlmResponse(
            $response->text,
            $modelType,
            $response->totalInputTokens(),
            $response->outputTokens,
            (int) round((microtime(true) - $startedAt) * 1000),
            ClaudeWriterService::costUsd(
                $response->inputTokens,
                $response->outputTokens,
                $modelType,
                $response->cacheWriteTokens,
                $response->cacheReadTokens,
            ),
            $response->text,
            $response->providerModel,
            $response->thinkingTokens,
        );

        if ($response->wasTruncated()) {
            throw new LlmUnavailable(sprintf(
                'Claude bị CẮT ở trần %d token output (đã sinh %d, trong đó %d là thinking và %d là chữ) '
                    .'nên kết quả không hoàn chỉnh — ĐÃ TỐN PHÍ cú gọi này. Có thể do bài dài, hoặc do model '
                    .'dùng adaptive thinking trong cùng max_tokens. Tăng LlmRequest::$maxTokens (hiện %d) '
                    .'hoặc probe cấu hình thinking riêng trước khi chạy lại. Bấm lại nguyên trạng sẽ hỏng '
                    .'y hệt và mất thêm tiền.',
                $request->maxTokens,
                $response->outputTokens,
                $response->thinkingTokens,
                max(0, $response->outputTokens - $response->thinkingTokens),
                $request->maxTokens,
            ), $llmResponse);
        }

        if (trim($response->text) === '') {
            throw new LlmUnavailable(
                'Claude trả về rỗng — coi là lỗi, KHÔNG coi là "bài báo không có sự thật nào"',
                $llmResponse,
            );
        }

        return $llmResponse;
    }
}
