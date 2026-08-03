<?php

namespace Tests\Video\Llm;

use App\Services\Admin\ClaudeResponse;
use App\Services\Admin\ClaudeWriterService;
use App\Video\Llm\ClaudeWriterAdapter;
use App\Video\Llm\LlmRequest;
use App\Video\Llm\LlmUnavailable;
use PHPUnit\Framework\TestCase;

/**
 * Adapter phai TON TRONG $request->model, khong duoc tu ap model rieng.
 *
 * Bug that (phat hien 2026-07-29): adapter giu mot $modelType rieng va phot lo
 * $request->model. Hau qua co that, do duoc: ClaudeProducer da khai
 * `$model = 'haiku'` tu 2026-07-23 kem comment "user chot" — va suot 6 ngay no
 * KHONG he co hieu luc, moi cu goi Producer van chay Sonnet va bi tinh gia
 * Sonnet. Test nay giu cho bug do khong quay lai.
 */
class ClaudeWriterAdapterTest extends TestCase
{
    private function request(string $model): LlmRequest
    {
        return new LlmRequest('instruction', 'input', 'v1', $model);
    }

    /** @param string[] $capturedModels Nhan tham chieu — ghi lai model that su duoc goi. */
    private function writerSpy(array &$capturedModels, ?array &$capturedMaxTokens = null): ClaudeWriterService
    {
        $writer = $this->createMock(ClaudeWriterService::class);
        $writer->method('generate')
            ->willReturnCallback(function (string $prompt, string $modelType, string $system, ?int $maxTokens = null) use (&$capturedModels, &$capturedMaxTokens) {
                $capturedModels[] = $modelType;
                if ($capturedMaxTokens !== null) {
                    $capturedMaxTokens[] = $maxTokens;
                }

                return new ClaudeResponse('ket qua', 100, 50, 'end_turn');
            });

        return $writer;
    }

    public function test_forwards_the_model_requested_by_the_caller(): void
    {
        $captured = [];
        $adapter = new ClaudeWriterAdapter($this->writerSpy($captured));

        $adapter->complete($this->request('haiku'));

        $this->assertSame(['haiku'], $captured);
    }

    public function test_a_different_requested_model_reaches_the_service(): void
    {
        // Diem cot loi: doi model o NGUOI GOI phai co hieu luc that.
        $captured = [];
        $adapter = new ClaudeWriterAdapter($this->writerSpy($captured));

        $adapter->complete($this->request('sonnet'));

        $this->assertSame(['sonnet'], $captured);
    }

    public function test_response_reports_the_model_actually_used(): void
    {
        $captured = [];
        $adapter = new ClaudeWriterAdapter($this->writerSpy($captured));

        $response = $adapter->complete($this->request('haiku'));

        // Truoc day LlmResponse->model bao model cua adapter, khong phai model
        // that su goi — nen log/benchmark ghi sai.
        $this->assertSame('haiku', $response->model);
    }

    public function test_unknown_model_fails_loudly_instead_of_silently_falling_back(): void
    {
        // generate() roi ve 'haiku' con costUsd() roi ve 'sonnet' — hai fallback
        // lech nhau, nen mot khoa sai se goi Haiku ma ghi hoa don gia Sonnet.
        // Phai nem loi, khong duoc chay tiep.
        $captured = [];
        $adapter = new ClaudeWriterAdapter($this->writerSpy($captured));

        $this->expectException(LlmUnavailable::class);
        $this->expectExceptionMessageMatches('/opus/');

        $adapter->complete($this->request('opus'));
    }

    public function test_unknown_model_never_reaches_the_service(): void
    {
        $captured = [];
        $adapter = new ClaudeWriterAdapter($this->writerSpy($captured));

        try {
            $adapter->complete($this->request('khong-ton-tai'));
        } catch (LlmUnavailable) {
            // mong doi
        }

        // Chan TRUOC khi goi API — khong ton dong nao.
        $this->assertSame([], $captured);
    }

    public function test_empty_response_is_an_error_not_an_empty_world(): void
    {
        $writer = $this->createMock(ClaudeWriterService::class);
        $writer->method('generate')->willReturn(new ClaudeResponse('   ', 10, 0));
        $adapter = new ClaudeWriterAdapter($writer);

        $this->expectException(LlmUnavailable::class);

        $adapter->complete($this->request('haiku'));
    }

    public function test_cost_is_computed_with_the_requested_model_price(): void
    {
        $captured = [];
        $adapter = new ClaudeWriterAdapter($this->writerSpy($captured));

        $haiku = $adapter->complete($this->request('haiku'));
        $sonnet = $adapter->complete($this->request('sonnet'));

        // Cung so token nhung Haiku re hon — chung minh gia duoc tinh theo model
        // THAT SU dung, khong phai theo mot model co dinh.
        $this->assertSame(
            ClaudeWriterService::costUsd(100, 50, 'haiku'),
            $haiku->costUsd,
        );
        $this->assertGreaterThan($haiku->costUsd, $sonnet->costUsd);
    }

    // ---- max_tokens + cắt trần (2026-07-30) ----
    //
    // Bug thật, CÙNG LỚP với bug $request->model ở trên: `LlmRequest` đã khai
    // `maxTokens = 8192` nhưng adapter không truyền xuống, nên mọi cú gọi rơi
    // về bảng MAX_TOKENS của service (4096 cho haiku). Trích xuất bài 2851 ký
    // tự vượt 4096 ⇒ JSON cắt cụt ⇒ MalformedExtraction ở tận parser, với
    // thông báo chỉ SAI CHỖ. Log: 5 lần cắt trần, 3 lần khớp từng giây với
    // MalformedExtraction, ~$0.09 trả cho dữ liệu bị vứt.

    public function test_forwards_max_tokens_from_the_request(): void
    {
        $models = [];
        $maxTokens = [];
        $adapter = new ClaudeWriterAdapter($this->writerSpy($models, $maxTokens));

        $adapter->complete(new LlmRequest('instruction', 'input', 'v1', 'haiku', maxTokens: 12000));

        $this->assertSame([12000], $maxTokens, 'maxTokens của request PHẢI xuống tới service');
    }

    public function test_the_default_request_max_tokens_is_forwarded_too(): void
    {
        // Mặc định của LlmRequest là 8192 — GẤP ĐÔI trần 4096 mà service tự áp
        // khi adapter không truyền gì. Đây chính là chỗ bug cũ nằm.
        $models = [];
        $maxTokens = [];
        $adapter = new ClaudeWriterAdapter($this->writerSpy($models, $maxTokens));

        $adapter->complete($this->request('haiku'));

        $this->assertSame([8192], $maxTokens);
    }

    public function test_truncated_output_fails_loudly_instead_of_returning_partial_text(): void
    {
        // Điểm cốt lõi: KHÔNG được trả bản cắt dở xuống tầng dưới. Parser sẽ
        // báo "JSON không hợp lệ" — đúng hiện tượng, sai nguyên nhân.
        $writer = $this->createMock(ClaudeWriterService::class);
        $writer->method('generate')->willReturn(
            new ClaudeResponse('{"entities": [{"id": "cut_off_he', 2000, 4096, 'max_tokens'),
        );
        $adapter = new ClaudeWriterAdapter($writer);

        $this->expectException(LlmUnavailable::class);
        $this->expectExceptionMessageMatches('/CẮT ở trần/u');

        $adapter->complete($this->request('haiku'));
    }

    public function test_truncation_message_tells_the_operator_what_to_change(): void
    {
        $writer = $this->createMock(ClaudeWriterService::class);
        $writer->method('generate')->willReturn(new ClaudeResponse('cut', 10, 4096, 'max_tokens'));
        $adapter = new ClaudeWriterAdapter($writer);

        try {
            $adapter->complete(new LlmRequest('i', 'x', 'v1', 'haiku', maxTokens: 4096));
            $this->fail('phải ném LlmUnavailable');
        } catch (LlmUnavailable $e) {
            $this->assertStringContainsString('4096', $e->getMessage(), 'phải nêu trần hiện tại');
            $this->assertStringContainsString('ĐÃ TỐN PHÍ', $e->getMessage(), 'phải nói rõ cú gọi này đã mất tiền');
            $this->assertStringContainsString('maxTokens', $e->getMessage(), 'phải chỉ ra chỗ cần sửa');
        }
    }

    public function test_a_normal_stop_reason_is_not_treated_as_truncation(): void
    {
        // Guard chặn nhầm còn tệ hơn không có guard.
        $writer = $this->createMock(ClaudeWriterService::class);
        $writer->method('generate')->willReturn(new ClaudeResponse('day du', 10, 20, 'end_turn'));
        $adapter = new ClaudeWriterAdapter($writer);

        $this->assertSame('day du', $adapter->complete($this->request('haiku'))->text);
    }

    public function test_unknown_stop_reason_is_not_treated_as_truncation(): void
    {
        // Chuỗi rỗng = "không biết" (vd đường lỗi 400 của service). Không được
        // suy thành "bị cắt" — sẽ chặn nhầm những cú gọi bình thường.
        $writer = $this->createMock(ClaudeWriterService::class);
        $writer->method('generate')->willReturn(new ClaudeResponse('day du', 10, 20));
        $adapter = new ClaudeWriterAdapter($writer);

        $this->assertSame('day du', $adapter->complete($this->request('haiku'))->text);
    }
}
