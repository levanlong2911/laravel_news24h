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
use Illuminate\Support\Facades\Log;
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

    /** @return array{0: LlmClient, 1: Article} */
    private function validConceptRun(bool $verboseDecision = false): array
    {
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

        $slotValue = function (array $spec) use (&$slotValue) {
            return match ($spec['type']) {
                'integer' => (int) $spec['min'],
                'number' => (float) $spec['min'],
                'enum' => $spec['values'][0],
                'object' => array_map($slotValue, $spec['fields']),
                default => 'a plain description',
            };
        };

        $identity = array_map($slotValue, $profile->identitySlots);

        $decisions = array_map(
            fn (string $aspect) => [
                'aspect' => $aspect,
                'provenance' => 'invented',
                'decision' => 'Decided independently of the reference.',
            ],
            $profile->inspectionAspects,
        );

        if ($verboseDecision) {
            $decisions[0]['decision'] = str_repeat('a', 300);
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
                'description' => 'a recessed bow facet',
                'visible_from' => ['front_three_quarter'],
            ]],
            'decisions' => $decisions,
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

        return [$llm, $article];
    }

    public function test_enabled_attaches_a_valid_concept_to_the_render_plan(): void
    {
        config(['video.creative_concept.mode' => 'enabled']);

        [$llm, $article] = $this->validConceptRun();

        $plan = $this->withCreativeConcept($llm, $article);

        $this->assertArrayHasKey('creative_concept', $plan);
        $this->assertSame(
            'One horizontal datum ties every deck together.',
            $plan['creative_concept']['design_thesis'],
        );
        $this->assertCount(
            count((new CreativeProfileResolver)->resolve('yacht')->inspectionAspects),
            $plan['creative_concept']['decisions'],
        );
    }

    public function test_warnings_reach_the_log_instead_of_disappearing_inside_the_validator(): void
    {
        config(['video.creative_concept.mode' => 'enabled']);

        $captured = [];
        Log::listen(function ($message) use (&$captured) {
            if ($message->message === 'video_creative_concept_warnings') {
                $captured[] = $message->context;
            }
        });

        [$llm, $article] = $this->validConceptRun(verboseDecision: true);

        $plan = $this->withCreativeConcept($llm, $article);

        $this->assertArrayHasKey('creative_concept', $plan);
        $this->assertCount(1, $captured, 'a verbose concept must still report its warnings');
        $this->assertSame(1, $captured[0]['attempts']);

        $warning = $captured[0]['warnings'][0];
        $this->assertSame('PROSE_EXCEEDS_RECOMMENDED_LENGTH', $warning['code']);
        $this->assertSame('decisions[0].decision', $warning['field']);
        $this->assertArrayNotHasKey('value', $warning, 'warnings must not carry field content into the log');
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
