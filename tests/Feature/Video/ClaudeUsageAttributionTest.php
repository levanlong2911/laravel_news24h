<?php

namespace Tests\Feature\Video;

use App\Models\Admin;
use App\Models\Article;
use App\Models\ClaudeUsageLog;
use App\Models\VideoProject;
use App\Models\VideoSession;
use App\Services\VideoRenderPlanService;
use App\Video\Llm\CostAccumulatingLlmClient;
use App\Video\Llm\LlmClient;
use App\Video\Llm\LlmRequest;
use App\Video\Llm\LlmResponse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class ClaudeUsageAttributionTest extends TestCase
{
    use DatabaseTransactions;

    private function article(): Article
    {
        $keywordId = DB::table('keywords')->value('id');

        return Article::create([
            'keyword_id' => $keywordId,
            'source_url' => 'https://example.com/'.uniqid(),
            'source_url_hash' => md5(uniqid('', true)),
            'source_title' => 'TEST attribution source',
            'title' => 'TEST attribution article '.uniqid(),
            'slug' => 'test-attribution-article-'.uniqid(),
            'content' => 'noi dung test',
            'status' => 'pending',
        ]);
    }

    private function admin(): Admin
    {
        $roleId = DB::table('roles')->value('id');

        return Admin::create([
            'name' => 'TEST attribution admin '.uniqid(),
            'email' => 'test_attr_'.uniqid().'@example.com',
            'password' => bcrypt('secret'),
            'role_id' => $roleId,
        ]);
    }

    private function videoSession(): VideoSession
    {
        $project = VideoProject::create(['title' => 'TEST attribution '.uniqid()]);

        return VideoSession::create([
            'project_id' => $project->id,
            'code' => 'test_attribution_'.uniqid(),
            'status' => 'planning',
        ]);
    }

    public function test_increment_claude_usage_records_article_and_video_session_when_given(): void
    {
        $admin = $this->admin();
        $article = $this->article();
        $session = $this->videoSession();

        $admin->incrementClaudeUsage('t', 'https://x', 'video_renderplan', 100, 0.5, $article->id, $session->id);

        $log = ClaudeUsageLog::latest('id')->first();
        $this->assertSame($article->id, $log->article_id);
        $this->assertSame($session->id, $log->video_session_id);
    }

    public function test_increment_claude_usage_leaves_attribution_null_by_default(): void
    {
        $admin = $this->admin();

        $admin->incrementClaudeUsage('t', 'https://x', 'send_to_claude', 100, 0.5);

        $log = ClaudeUsageLog::latest('id')->first();
        $this->assertNull($log->article_id);
        $this->assertNull($log->video_session_id);
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

    private function withRun(VideoRenderPlanService $service, CostAccumulatingLlmClient $run): void
    {
        $property = new ReflectionProperty(VideoRenderPlanService::class, 'lastRun');
        $property->setAccessible(true);
        $property->setValue($service, $run);
    }

    private function recordUsage(VideoRenderPlanService $service, Article $article, ?string $videoSessionId): void
    {
        $method = new ReflectionMethod(VideoRenderPlanService::class, 'recordUsage');
        $method->setAccessible(true);
        $method->invoke($service, $article, $videoSessionId);
    }

    public function test_video_render_plan_service_attributes_usage_to_the_article_and_session(): void
    {
        $admin = $this->admin();
        $article = $this->article();
        $session = $this->videoSession();
        $this->actingAs($admin);

        $service = app(VideoRenderPlanService::class);
        $this->withRun($service, $this->accumulatorWith(in: 100, out: 50, cost: 0.01));

        $this->recordUsage($service, $article, $session->id);

        $log = ClaudeUsageLog::latest('id')->first();
        $this->assertSame($article->id, $log->article_id);
        $this->assertSame($session->id, $log->video_session_id);
        $this->assertSame(VideoRenderPlanService::USAGE_ACTION, $log->action);
    }

    public function test_video_render_plan_service_leaves_session_null_when_not_given(): void
    {
        $admin = $this->admin();
        $article = $this->article();
        $this->actingAs($admin);

        $service = app(VideoRenderPlanService::class);
        $this->withRun($service, $this->accumulatorWith(in: 100, out: 50, cost: 0.01));

        $this->recordUsage($service, $article, null);

        $log = ClaudeUsageLog::latest('id')->first();
        $this->assertSame($article->id, $log->article_id);
        $this->assertNull($log->video_session_id);
    }
}
