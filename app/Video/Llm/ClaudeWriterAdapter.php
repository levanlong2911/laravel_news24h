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

        if (trim($response->text) === '') {
            throw new LlmUnavailable('Claude trả về rỗng — coi là lỗi, KHÔNG coi là "bài báo không có sự thật nào"');
        }

        if ($response->wasTruncated()) {
            throw new LlmUnavailable(sprintf(
                'Claude bị CẮT ở trần %d token output (đã sinh %d) nên kết quả không hoàn chỉnh — '
                    .'ĐÃ TỐN PHÍ cú gọi này. Bài quá dài so với trần: tăng LlmRequest::$maxTokens '
                    .'(hiện %d) rồi chạy lại. Bấm lại nguyên trạng sẽ hỏng y hệt và mất thêm tiền.',
                $request->maxTokens,
                $response->outputTokens,
                $request->maxTokens,
            ));
        }

        return new LlmResponse(
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
        );
    }
}
