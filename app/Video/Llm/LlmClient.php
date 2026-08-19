<?php

namespace App\Video\Llm;

interface LlmClient
{
    /**
     * @throws LlmUnavailable khi mô hình không gọi được
     * @throws \App\Video\Llm\ApprovalRequired khi cú gọi tốn phí chưa được duyệt
     */
    public function complete(LlmRequest $request): LlmResponse;
}
