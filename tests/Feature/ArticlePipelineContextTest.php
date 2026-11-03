<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Services\Admin\ArticlePipelineService;
use App\Services\Admin\ClaudeResponse;
use App\Services\Admin\ClaudeWriterService;
use App\Services\Admin\Phase;
use App\Services\Admin\RequestContext;
use App\Services\Admin\TokenUsage;
use Database\Seeders\PromptSystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Khoá lại: RequestContext của sổ cái phải đi tới được biên gọi Claude.
 *
 * ── Lỗi có thật, không phải giả định ────────────────────────────────────────
 *
 * ArticlePipelineService::run() nhận tham số ?RequestContext $context, rồi vài
 * dòng sau gán đè chính biến đó bằng CategoryContext::forCategory(). PHP không
 * cảnh báo khi gán đè tham số, nên mọi lượt gọi phía dưới truyền context: rồi
 * gọi withPhase() lên một Eloquent model:
 *
 *     Call to undefined method App\Models\CategoryContext::withPhase()
 *
 * Chết ngay lượt gọi Claude ĐẦU TIÊN — tức toàn bộ pipeline "Send Claude"
 * hỏng, mọi bài đều failed. Vậy mà nó vẫn merge được vào main và deploy lên
 * production, vì không có test nào chạm tới run().
 *
 * ── Vì sao dừng ở biên LLM, không chạy trọn pipeline ────────────────────────
 *
 * Chạy tới cùng thì test phải làm hài lòng PostGuard: đủ REQUIRED_FIELDS và
 * vượt ngưỡng confidence. Lúc đó ai tinh chỉnh ngưỡng là test này đỏ, vì một
 * lý do chẳng liên quan gì tới thứ nó canh. Test canh đúng một tính chất — cái
 * gì đi tới generate() — nên chỉ khẳng định đúng chừng đó.
 *
 * Các bước sau biên này được phép ném lỗi; chúng có lưới an toàn riêng.
 */
class ArticlePipelineContextTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<mixed> context của từng lượt gọi generate(), theo thứ tự. */
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PromptSystemSeeder::class);

        $this->mock(ClaudeWriterService::class, function (MockInterface $mock): void {
            // Tham số cố ý KHÔNG khai kiểu: gặp sai kiểu thì test phải khẳng
            // định rõ ràng, chứ không chết bằng TypeError của chính mock.
            $mock->shouldReceive('generate')->andReturnUsing(
                function ($prompt, $modelType = 'haiku', $system = '', $context = null): ClaudeResponse {
                    $this->captured[] = $context;

                    return new ClaudeResponse(
                        $this->fakeReply($modelType),
                        new TokenUsage('claude-test', $modelType, 10, 20),
                    );
                }
            );
        });
    }

    /**
     * Mọi lượt gọi Claude phải nhận RequestContext, và lượt đầu phải mang đúng
     * article_id cùng phase của bước đó.
     *
     * Khẳng định "đã gọi ít nhất một lần" trước là bắt buộc: chính lỗi này làm
     * pipeline chết TRƯỚC khi generate() kịp chạy, nên nếu chỉ duyệt mảng rỗng
     * thì test xanh trong khi pipeline hỏng hoàn toàn.
     */
    public function test_every_claude_call_receives_a_ledger_context(): void
    {
        $articleId = 'article-under-test';

        $this->runPipeline(new RequestContext(articleId: $articleId));

        $this->assertNotEmpty(
            $this->captured,
            'Pipeline chưa gọi Claude lần nào — nhiều khả năng chết trước khi tới biên LLM.',
        );

        foreach ($this->captured as $index => $context) {
            $this->assertInstanceOf(
                RequestContext::class,
                $context,
                "Lượt gọi #{$index} nhận sai kiểu context.",
            );
        }

        $this->assertSame($articleId, $this->captured[0]->articleId);
        $this->assertSame(Phase::FactExtraction, $this->captured[0]->phase);
    }

    /**
     * Gọi run() không kèm context vẫn phải sinh RequestContext mặc định.
     *
     * Khoá dòng `$context ??= new RequestContext()`. Bỏ nó đi thì null lọt tới
     * biên và mọi lượt gọi Claude ném lỗi trên null — cùng một kiểu hỏng, khác
     * đường tới.
     */
    public function test_pipeline_supplies_a_context_when_caller_omits_one(): void
    {
        $this->runPipeline(null);

        $this->assertNotEmpty($this->captured, 'Pipeline chưa gọi Claude lần nào.');
        $this->assertInstanceOf(RequestContext::class, $this->captured[0]);
        $this->assertNull($this->captured[0]->articleId);
    }

    // ── Hỗ trợ ────────────────────────────────────────────────────────────────

    /**
     * Chạy pipeline và nuốt lỗi phát sinh SAU biên LLM.
     *
     * Nuốt lỗi ở đây không che được lỗi cần bắt: khi biến bị gán đè, pipeline
     * chết trong lúc dựng tham số cho lượt gọi đầu, nên $captured rỗng và
     * assertNotEmpty() ở mỗi test sẽ đỏ.
     */
    private function runPipeline(?RequestContext $context): void
    {
        $categoryId = Category::where('slug', 'nfl')->firstOrFail()->id;

        try {
            app(ArticlePipelineService::class)->run(
                $this->rawHtml(),
                'Green Bay Packers',
                $categoryId,
                $context,
            );
        } catch (\Throwable) {
            // PostGuard / PromptGuard có lưới riêng — ngoài phạm vi test này.
        }
    }

    /** Haiku trả facts đủ dài (>150 ký tự) kèm HOOKS_JSON; sonnet trả JSON bài viết. */
    private function fakeReply(string $modelType): string
    {
        if ($modelType === 'sonnet') {
            return json_encode([
                'title'   => 'Packers Face A Decision On Their Starting Back',
                'content' => str_repeat('Green Bay must choose before Thursday. ', 20),
                'excerpt' => 'A roster decision with real consequences.',
            ], JSON_UNESCAPED_UNICODE);
        }

        return "FACTS:\n"
            . "- Green Bay Packers play the Dallas Cowboys in Week 6 at Lambeau Field.\n"
            . "- Micah Parsons said returning for that game is realistic after his injury.\n"
            . "- The Packers are 3-2 and need the win to stay in the division race.\n"
            . 'HOOKS_JSON:["Parsons eyes a Lambeau return","Green Bay waits on one answer","A Week 6 call that decides the race"]';
    }

    private function rawHtml(): string
    {
        return '<p>' . str_repeat(
            'The Green Bay Packers travelled to face the Dallas Cowboys with a roster decision still open. ',
            8,
        ) . '</p>';
    }
}
