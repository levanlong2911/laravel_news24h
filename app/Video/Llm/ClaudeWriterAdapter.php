<?php

namespace App\Video\Llm;

use App\Services\Admin\ClaudeWriterService;
use Throwable;

/**
 * Nối LlmClient của Semantic OS với ClaudeWriterService của CMS.
 *
 * ĐÂY LÀ FILE DUY NHẤT trong app/Video/ biết CMS tồn tại. Nếu mai ClaudeWriterService
 * đổi chữ ký, đổi tên, hay bị thay bằng thứ khác — chỉ file này hỏng. Truth Layer
 * không biết và không cần build lại. Đó là toàn bộ lý do adapter này tồn tại.
 *
 * KHÔNG viết lại service đó. Nó đã encode những thứ trả giá mới có: retry backoff,
 * xử lý riêng lỗi 529 Overloaded (30s/60s/90s), giới hạn 40 request/phút và 8 luồng
 * đồng thời tới Anthropic. Viết lại là mất sạch, y như với providers Kling.
 */
final class ClaudeWriterAdapter implements LlmClient
{
    /**
     * @param string $modelType Khoá trong bảng model của ClaudeWriterService ('sonnet'|'haiku').
     *                          KHÔNG phải model id đầy đủ — service tự map.
     */
    public function __construct(
        private readonly ClaudeWriterService $writer,
        private readonly string $modelType = 'sonnet',
    ) {
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $startedAt = microtime(true);

        try {
            // instruction → system, input → user. Tách ra để instruction được
            // cache và để bài báo không lẫn vào chỉ dẫn.
            $response = $this->writer->generate($request->input, $this->modelType, $request->instruction);
        } catch (Throwable $e) {
            throw new LlmUnavailable(
                "Claude không gọi được ({$this->modelType}): {$e->getMessage()}",
                previous: $e,
            );
        }

        if (trim($response->text) === '') {
            throw new LlmUnavailable('Claude trả về rỗng — coi là lỗi, KHÔNG coi là "bài báo không có sự thật nào"');
        }

        return new LlmResponse(
            $response->text,
            $this->modelType,
            $response->inputTokens,
            $response->outputTokens,
            (int) round((microtime(true) - $startedAt) * 1000),
            ClaudeWriterService::costUsd($response->inputTokens, $response->outputTokens, $this->modelType),
            $response->text,
        );
    }
}
