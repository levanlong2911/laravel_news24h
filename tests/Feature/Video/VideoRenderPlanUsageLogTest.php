<?php

namespace Tests\Feature\Video;

use App\Models\Article;
use App\Services\VideoRenderPlanService;
use App\Video\Llm\CostAccumulatingLlmClient;
use App\Video\Llm\LlmClient;
use App\Video\Llm\LlmRequest;
use App\Video\Llm\LlmResponse;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Ghi chi phí luồng video vào `claude_usage_logs` (§18.24).
 *
 * Trước 2026-07-30 luồng video KHÔNG ghi vào đó — chỉ có dòng `Claude OK` trong
 * `laravel.log`, muốn biết tốn bao nhiêu phải đọc log bằng tay. Bảng đó vốn chỉ
 * có luồng CMS (`send_to_claude`/`synthesize`).
 *
 * KHÔNG chạm DB thật ở đây: test này khoá phần TÍNH TOÁN và phần QUYẾT ĐỊNH
 * (có ghi hay không), không khoá Eloquent. Phần ghi thật đi qua
 * `Admin::incrementClaudeUsage()` vốn đã dùng cho CMS từ trước.
 */
class VideoRenderPlanUsageLogTest extends TestCase
{
    private function service(): VideoRenderPlanService
    {
        return app(VideoRenderPlanService::class);
    }

    /** Nhét sẵn một accumulator đã có số liệu vào `$lastRun` (private). */
    private function withRun(VideoRenderPlanService $service, ?CostAccumulatingLlmClient $run): void
    {
        $property = new ReflectionProperty(VideoRenderPlanService::class, 'lastRun');
        $property->setAccessible(true);
        $property->setValue($service, $run);
    }

    private function recordUsage(VideoRenderPlanService $service, Article $article): void
    {
        $method = new ReflectionMethod(VideoRenderPlanService::class, 'recordUsage');
        $method->setAccessible(true);
        $method->invoke($service, $article);
    }

    /** Client giả trả về ĐÚNG hình dạng LlmResponse mà adapter thật dựng. */
    private function accumulatorWith(int $in, int $out, float $cost, int $calls = 1): CostAccumulatingLlmClient
    {
        $accumulator = new CostAccumulatingLlmClient(new class($in, $out, $cost) implements LlmClient
        {
            public function __construct(private int $in, private int $out, private float $cost) {}

            public function complete(LlmRequest $request): LlmResponse
            {
                return new LlmResponse('{}', 'haiku', $this->in, $this->out, 120, $this->cost);
            }
        });

        for ($i = 0; $i < $calls; $i++) {
            $accumulator->complete(new LlmRequest('i', 'x', 'v1', 'haiku'));
        }

        return $accumulator;
    }

    private function article(): Article
    {
        $article = new Article;
        $article->id = 'article-1';
        $article->title = 'Tieu de bai viet';

        return $article;
    }

    public function test_totals_sum_the_real_numbers_across_every_call(): void
    {
        // Đây là cốt lõi của "chính xác đúng với API": con số KHÔNG phải ước
        // lượng. `CostAccumulatingLlmClient` cộng `LlmResponse->tokensIn/
        // tokensOut/costUsd`, mà ba trường đó lấy thẳng từ `usage.*` của
        // response Anthropic (ClaudeWriterService dòng 137-139).
        $accumulator = $this->accumulatorWith(in: 1000, out: 250, cost: 0.0018, calls: 9);

        $totals = $accumulator->totals();

        $this->assertSame(9, $totals['call_count']);
        $this->assertSame(9000, $totals['tokens_in']);
        $this->assertSame(2250, $totals['tokens_out']);
        $this->assertEqualsWithDelta(0.0162, $totals['cost_usd'], 1e-9);
    }

    public function test_nothing_is_logged_when_no_api_call_was_made(): void
    {
        // GUARD 1 chặn bài rỗng TRƯỚC cú gọi đầu tiên ⇒ chưa tốn đồng nào ⇒
        // không có gì để ghi. Ghi một hàng $0 chỉ làm nhiễu báo cáo.
        $service = $this->service();
        $this->withRun($service, $this->accumulatorWith(in: 0, out: 0, cost: 0.0, calls: 0));

        $before = \App\Models\ClaudeUsageLog::count();
        $this->recordUsage($service, $this->article());

        $this->assertSame($before, \App\Models\ClaudeUsageLog::count());
    }

    public function test_nothing_is_logged_when_build_was_never_called(): void
    {
        $service = $this->service();
        $this->withRun($service, null);

        $before = \App\Models\ClaudeUsageLog::count();
        $this->recordUsage($service, $this->article());

        $this->assertSame($before, \App\Models\ClaudeUsageLog::count());
    }

    public function test_cli_run_without_an_admin_is_skipped_not_crashed(): void
    {
        // `claude_usage_logs.admin_id` có khoá ngoại tới `admins` — không ghi
        // được hàng mồ côi. `video:benchmark` chạy CLI, không có ai đăng nhập.
        // Phải BỎ QUA im lặng (có log info), KHÔNG được ném lỗi làm hỏng cả
        // lượt chạy chỉ vì chuyện thống kê.
        $this->assertNull(auth()->user(), 'test chạy không đăng nhập — đúng bối cảnh CLI');

        $service = $this->service();
        $this->withRun($service, $this->accumulatorWith(in: 2593, out: 4979, cost: 0.02199));

        $before = \App\Models\ClaudeUsageLog::count();
        $this->recordUsage($service, $this->article());

        $this->assertSame($before, \App\Models\ClaudeUsageLog::count(), 'không admin ⇒ không ghi, và không nổ');
    }

    public function test_the_action_name_is_separate_from_the_cms_actions(): void
    {
        // Hai luồng dùng CHUNG bảng nhưng không được lẫn nhau — báo cáo phải
        // tách được chi phí video khỏi chi phí viết bài.
        $this->assertSame('video_renderplan', VideoRenderPlanService::USAGE_ACTION);
        $this->assertNotSame('send_to_claude', VideoRenderPlanService::USAGE_ACTION);
        $this->assertNotSame('synthesize', VideoRenderPlanService::USAGE_ACTION);
    }

    public function test_the_price_table_matches_the_official_anthropic_rates(): void
    {
        // ĐỎ 2026-07-31: bảng giá ghi haiku 0.80/4.00 — SAI, giá thật 1.00/5.00.
        // Phát hiện vì số trong trang Claude Usage không khớp
        // platform.claude.com/usage. Lệch đúng 1.25× ⇒ MỌI con số chi phí ghi
        // trước ngày đó đều thấp hơn thực tế 20%.
        //
        // Khoá bằng hằng số viết tay chứ không đọc lại chính bảng đó — đọc lại
        // thì test luôn xanh kể cả khi bảng sai, tức là không khoá gì cả.
        $this->assertSame(1.00, \App\Services\Admin\ClaudeWriterService::PRICE_INPUT['haiku']);
        $this->assertSame(5.00, \App\Services\Admin\ClaudeWriterService::PRICE_OUTPUT['haiku']);
        $this->assertSame(3.00, \App\Services\Admin\ClaudeWriterService::PRICE_INPUT['sonnet']);
        $this->assertSame(15.00, \App\Services\Admin\ClaudeWriterService::PRICE_OUTPUT['sonnet']);
    }

    public function test_measured_production_run_reproduces_the_known_cost(): void
    {
        // Số token THẬT đo từ log lần chạy 2026-07-30 10:00 (9 cú gọi Haiku).
        // Giá ĐÚNG: $1.00/1M in, $5.00/1M out.
        $expected = 10781 / 1e6 * 1.00 + 6168 / 1e6 * 5.00;

        $this->assertEqualsWithDelta(0.041621, $expected, 1e-6);
        $this->assertEqualsWithDelta(
            $expected,
            \App\Services\Admin\ClaudeWriterService::costUsd(10781, 6168, 'haiku'),
            1e-9,
            'costUsd() phải khớp bảng giá đã dùng để tính tay',
        );
    }

    public function test_cache_tokens_are_billed_at_their_own_rates(): void
    {
        // `usage.input_tokens` của Anthropic CHỈ là phần chưa cache. Ba loại
        // token đầu vào có ba đơn giá khác nhau — cộng gộp một giá là sai tiền.
        $inputOnly = \App\Services\Admin\ClaudeWriterService::costUsd(1000, 0, 'haiku');
        $cacheWrite = \App\Services\Admin\ClaudeWriterService::costUsd(0, 0, 'haiku', cacheWriteTokens: 1000);
        $cacheRead = \App\Services\Admin\ClaudeWriterService::costUsd(0, 0, 'haiku', cacheReadTokens: 1000);

        $this->assertEqualsWithDelta($inputOnly * 1.25, $cacheWrite, 1e-12, 'cache GHI đắt hơn input 25%');
        $this->assertEqualsWithDelta($inputOnly * 0.10, $cacheRead, 1e-12, 'cache ĐỌC rẻ hơn 10 lần');
    }

    public function test_cache_tokens_default_to_zero_so_old_callers_are_unaffected(): void
    {
        // ArticlePipelineService/HookEngine gọi costUsd() 3 tham số như cũ.
        $this->assertSame(
            \App\Services\Admin\ClaudeWriterService::costUsd(1000, 500, 'haiku'),
            \App\Services\Admin\ClaudeWriterService::costUsd(1000, 500, 'haiku', 0, 0),
        );
    }

    public function test_total_input_tokens_includes_the_cache_tokens(): void
    {
        // Lấy `inputTokens` một mình là ĐẾM THIẾU khi có cache.
        $response = new \App\Services\Admin\ClaudeResponse('x', 100, 50, 'end_turn', 200, 700);

        $this->assertSame(1000, $response->totalInputTokens());
    }
}
