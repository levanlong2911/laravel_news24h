<?php

namespace Tests\Feature\Video;

use App\Models\Article;
use App\Services\Admin\ClaudeResponse;
use App\Services\Admin\ClaudeWriterService;
use App\Services\VideoRenderPlanService;
use App\Video\Llm\ClaudeWriterAdapter;
use App\Video\Pipeline\VideoPipelineFactory;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PipelineWarningLogTest extends TestCase
{
    private const ARTICLE = 'The ISA Amarcord 82 measures 82 metres. She carries a fold-out beach club aft. '
        .'An infinity pool sits on the sun deck. The yacht has five decks. Her hull is dark blue. '
        .'The tender Amarcord Junior measures 12 metres. It carries a folding boarding ladder. '
        .'Its hull is white. It has two decks.';

    private const EXTRACTION = <<<'JSON'
    {
      "entities": [
        {
          "id": "isa_amarcord", "type": "vehicle",
          "name": "ISA Amarcord 82", "name_quote": "ISA Amarcord 82",
          "claims": [
            { "attribute": "length_metres", "value": "82 metres", "evidence_quote": "82 metres" },
            { "attribute": "beach_club", "value": "fold-out beach club", "evidence_quote": "fold-out beach club" },
            { "attribute": "pool_feature", "value": "infinity pool", "evidence_quote": "infinity pool" },
            { "attribute": "deck_count", "value": "five decks", "evidence_quote": "five decks" },
            { "attribute": "hull_colour", "value": "dark blue", "evidence_quote": "dark blue" }
          ]
        },
        {
          "id": "amarcord_junior", "type": "vehicle",
          "name": "Amarcord Junior", "name_quote": "Amarcord Junior",
          "claims": [
            { "attribute": "length_metres", "value": "12 metres", "evidence_quote": "12 metres" },
            { "attribute": "hull_colour", "value": "white", "evidence_quote": "white" }
          ]
        }
      ],
      "relations": [],
      "events": []
    }
    JSON;

    private const PRODUCER = '{"target_audience":"a","core_conflict":"b","visual_promise":"c","emotional_curve":["calm"]}';

    private function service(): VideoRenderPlanService
    {
        $writer = $this->createMock(ClaudeWriterService::class);

        $writer->method('generate')->willReturnCallback(
            function (string $prompt, string $model, string $system) {
                $text = match (true) {
                    str_contains($system, 'news article to decide why') => self::PRODUCER,
                    str_contains($system, 'documentary scene director') => '{}',
                    default => self::EXTRACTION,
                };

                return new ClaudeResponse($text, 10, 5, 'end_turn');
            },
        );

        return new VideoRenderPlanService(new ClaudeWriterAdapter($writer), new VideoPipelineFactory);
    }

    private function article(): Article
    {
        $article = new Article;
        $article->id = 'article-isa';
        $article->title = 'ISA Amarcord 82';
        $article->content = self::ARTICLE;

        return $article;
    }

    public function test_the_service_really_forwards_pipeline_warnings_to_the_log(): void
    {
        // Callback đúng payload ở tầng pipeline chưa chứng minh gì nếu service
        // quên truyền nó — production sẽ im lặng mà suite vẫn xanh.
        Log::spy();

        $this->service()->build($this->article(), 'session-42');

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'video_pipeline_director_selection_failed'
                    && $context['article_id'] === 'article-isa'
                    && $context['video_session_id'] === 'session-42'
                    && $context['reason'] === 'NO_VALID_INDEX_AFTER_RETRY'
                    && $context['attempts'] === 2;
            })
            ->once();
    }

    public function test_the_logged_context_never_leaks_the_prompt_or_the_candidates(): void
    {
        Log::spy();

        $this->service()->build($this->article(), 'session-42');

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context): bool {
                if ($message !== 'video_pipeline_director_selection_failed') {
                    return false;
                }

                $this->assertSame(
                    ['scene_id', 'scene_ordinal', 'reason', 'attempts', 'article_id', 'video_session_id'],
                    array_keys($context),
                );

                $flat = json_encode($context);

                foreach (['beach club', 'infinity pool', 'documentary scene director', 'FEATURE CANDIDATES'] as $leak) {
                    $this->assertStringNotContainsString($leak, $flat);
                }

                return true;
            })
            ->once();
    }
}
