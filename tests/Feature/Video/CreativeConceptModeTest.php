<?php

namespace Tests\Feature\Video;

use App\Models\Article;
use App\Models\Category;
use App\Services\Admin\ClaudeWriterService;
use App\Services\Video\CreativeProfileResolver;
use App\Services\VideoRenderPlanService;
use App\Video\Article\RawArticle;
use App\Video\Llm\ClaudeWriterAdapter;
use App\Video\Llm\LlmClient;
use App\Video\Llm\LlmRequest;
use App\Video\Llm\LlmResponse;
use App\Video\Pipeline\VideoPipelineFactory;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Cong nang luc cho luong ton phi. `disabled` la mac dinh: code moi chua co
 * luot render production nao.
 *
 * KHONG cham DB va KHONG goi Claude — LlmClient gia tra san hai phan hoi.
 */
class CreativeConceptModeTest extends TestCase
{
    private function article(string $categorySlug = 'yacht'): Article
    {
        $article = new Article(['title' => 'A vessel', 'content' => 'The hull is steel.']);
        $article->id = 'article-1';
        $article->setRelation('category', new Category(['slug' => $categorySlug]));

        return $article;
    }

    /** @return array<string, mixed> */
    private function withCreativeConcept(LlmClient $llm, Article $article, string $category = 'yacht'): array
    {
        $service = new VideoRenderPlanService(
            new ClaudeWriterAdapter($this->createMock(ClaudeWriterService::class)),
            new VideoPipelineFactory,
        );

        $method = new ReflectionMethod(VideoRenderPlanService::class, 'withCreativeConcept');
        $method->setAccessible(true);

        return $method->invoke(
            $service,
            ['scenes' => []],
            (string) config('video.creative_concept.mode'),
            new RawArticle('article-1', 'A vessel', '<p>The hull is steel.</p>'),
            $category,
            $llm,
            $article,
            'session-1',
        );
    }

    public function test_disabled_is_the_default_and_spends_nothing(): void
    {
        $this->assertSame('disabled', config('video.creative_concept.mode'));

        $llm = new class implements LlmClient
        {
            public int $calls = 0;

            public function complete(LlmRequest $request): LlmResponse
            {
                $this->calls++;

                return new LlmResponse('{}', 'sonnet');
            }
        };

        $plan = $this->withCreativeConcept($llm, $this->article());

        $this->assertSame(0, $llm->calls);
        $this->assertArrayNotHasKey('creative_concept', $plan);
    }

    public function test_a_misspelled_mode_is_refused_before_the_pipeline_spends_anything(): void
    {
        config(['video.creative_concept.mode' => 'obesrve']);

        $calls = 0;
        $writer = $this->createMock(ClaudeWriterService::class);
        $writer->method('generate')->willReturnCallback(function () use (&$calls) {
            $calls++;

            throw new \RuntimeException('must never be reached');
        });

        $service = new VideoRenderPlanService(
            new ClaudeWriterAdapter($writer),
            new VideoPipelineFactory,
        );

        try {
            $service->build($this->article());
            $this->fail('an unknown mode must not fall through to enabled');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('obesrve', $e->getMessage());
        }

        $this->assertSame(0, $calls, 'a bad mode must be caught before the pipeline pays for anything');
    }

    public function test_an_unconfigured_category_spends_nothing_even_when_enabled(): void
    {
        config(['video.creative_concept.mode' => 'enabled']);

        $llm = new class implements LlmClient
        {
            public int $calls = 0;

            public function complete(LlmRequest $request): LlmResponse
            {
                $this->calls++;

                return new LlmResponse('{}', 'sonnet');
            }
        };

        $plan = $this->withCreativeConcept($llm, $this->article('politics'), 'politics');

        $this->assertSame(0, $llm->calls);
        $this->assertArrayNotHasKey('creative_concept', $plan);
    }

    public function test_observe_calls_the_models_but_never_touches_the_render_plan(): void
    {
        config(['video.creative_concept.mode' => 'observe']);

        $llm = new class implements LlmClient
        {
            public int $calls = 0;

            public function complete(LlmRequest $request): LlmResponse
            {
                $this->calls++;

                return new LlmResponse('{"not":"valid"}', 'sonnet');
            }
        };

        $plan = $this->withCreativeConcept($llm, $this->article());

        $this->assertGreaterThan(0, $llm->calls);
        $this->assertArrayNotHasKey('creative_concept', $plan);
    }

    public function test_enabled_attaches_a_valid_concept_to_the_render_plan(): void
    {
        config(['video.creative_concept.mode' => 'enabled']);

        $profile = (new CreativeProfileResolver)->resolve('yacht');
        $quote = 'The hull is steel.';

        $brief = json_encode([
            'article_patterns' => [$profile->articlePatterns[0]],
            'article_focus' => 'A long low vessel built around one horizontal line.',
            'source_insights' => [[
                'aspect' => $profile->inspectionAspects[0],
                'summary' => 'The reference vessel uses a steel hull.',
                'source_quotes' => [$quote],
            ]],
            'excluded_context' => [],
        ]);

        $identity = [];
        foreach ($profile->identitySlots as $name => $spec) {
            $identity[$name] = match ($spec['type']) {
                'integer' => 4,
                'number' => 6.0,
                default => 'a plain description',
            };
        }

        $concept = json_encode([
            'design_thesis' => 'One horizontal datum ties every deck together.',
            'design_identity' => $identity,
            'form_relationships' => [
                'governing_line' => 'One line runs bow to stern.',
                'massing_rhythm' => 'Volumes taper toward both ends.',
                'feature_integration' => 'Features grow out of the main volume.',
            ],
            'signature_features' => [[
                'description' => 'a cantilevered bow fin',
                'visible_from' => ['front_three_quarter'],
            ]],
            'decisions' => array_map(
                fn (string $aspect) => [
                    'aspect' => $aspect,
                    'provenance' => 'invented',
                    'decision' => 'Decided independently of the reference.',
                ],
                $profile->inspectionAspects,
            ),
        ]);

        $llm = new class($brief, $concept) implements LlmClient
        {
            /** @var list<string> */
            private array $replies;

            public function __construct(string ...$replies)
            {
                $this->replies = $replies;
            }

            public function complete(LlmRequest $request): LlmResponse
            {
                return new LlmResponse((string) array_shift($this->replies), $request->model);
            }
        };

        $article = new Article(['title' => 'A vessel', 'content' => '<p>'.$quote.'</p>']);
        $article->id = 'article-1';
        $article->setRelation('category', new Category(['slug' => 'yacht']));

        $plan = $this->withCreativeConcept($llm, $article);

        $this->assertArrayHasKey('creative_concept', $plan);
        $this->assertSame(
            'One horizontal datum ties every deck together.',
            $plan['creative_concept']['design_thesis'],
        );
        $this->assertCount(
            count($profile->inspectionAspects),
            $plan['creative_concept']['decisions'],
        );
    }

    public function test_enabled_lets_a_failed_concept_fail_the_build(): void
    {
        // KHONG catch-and-continue: nguoi dung xin video sang tao ma nhan
        // pipeline cu la fallback am tham.
        config(['video.creative_concept.mode' => 'enabled']);

        $llm = new class implements LlmClient
        {
            public function complete(LlmRequest $request): LlmResponse
            {
                return new LlmResponse('{"not":"valid"}', 'sonnet');
            }
        };

        $this->expectException(\RuntimeException::class);

        $this->withCreativeConcept($llm, $this->article());
    }
}
