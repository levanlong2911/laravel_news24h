<?php

namespace Tests\Feature\Video;

use App\Models\Article;
use App\Models\Category;
use App\Services\Admin\ClaudeWriterService;
use App\Services\VideoRenderPlanService;
use App\Video\Llm\ClaudeWriterAdapter;
use App\Video\Pipeline\VideoPipelineFactory;
use Tests\TestCase;
use Throwable;

/**
 * Bai truot pipeline KHONG duoc tra tien cho Haiku Inspiration va Sonnet
 * Concept. Test nay di qua `build()` that, khong goi thang helper private:
 * thu tu la thuoc tinh cua build(), khong phai cua helper.
 *
 * KHONG cham DB — `recordUsage()` thoat som khi khong co admin dang nhap.
 */
class CreativeConceptOrderingTest extends TestCase
{
    public function test_a_failing_pipeline_never_reaches_the_creative_models(): void
    {
        config(['video.creative_concept.mode' => 'enabled']);

        $instructions = [];
        $writer = $this->createMock(ClaudeWriterService::class);
        $writer->method('generate')->willReturnCallback(
            function (string $prompt, string $modelType, string $system) use (&$instructions) {
                $instructions[] = $system;

                throw new \RuntimeException('API down');
            },
        );

        $article = new Article(['title' => 'A vessel', 'content' => '<p>The hull is steel.</p>']);
        $article->id = 'article-1';
        $article->setRelation('category', new Category(['slug' => 'yacht']));

        $service = new VideoRenderPlanService(
            new ClaudeWriterAdapter($writer),
            new VideoPipelineFactory,
        );

        try {
            $service->build($article);
            $this->fail('a failing pipeline must not produce a render plan');
        } catch (Throwable) {
            // Thu tu moi la thu duoc khoa o day, khong phai loai exception.
        }

        $this->assertNotSame([], $instructions, 'the pipeline itself must have been attempted');

        foreach ($instructions as $instruction) {
            $this->assertStringNotContainsString('concept designer', mb_strtolower($instruction));
            $this->assertStringNotContainsString('source-inspiration brief', mb_strtolower($instruction));
        }
    }
}
