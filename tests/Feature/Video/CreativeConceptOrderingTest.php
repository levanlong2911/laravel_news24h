<?php

namespace Tests\Feature\Video;

use App\Models\Article;
use App\Models\Category;
use App\Services\Admin\ClaudeResponse;
use App\Services\Admin\ClaudeWriterService;
use App\Services\Video\CreativeProfileResolver;
use App\Services\VideoRenderPlanService;
use App\Video\Llm\ClaudeWriterAdapter;
use App\Video\Pipeline\VideoPipelineFactory;
use Tests\TestCase;
use Throwable;

/**
 * Enabled la mot pipeline creative doc lap. No phai re nhanh truoc factory cu;
 * neu Inspiration hong thi Extractor/Producer/Director khong duoc goi.
 *
 * KHONG cham DB — `recordUsage()` thoat som khi khong co admin dang nhap.
 */
class CreativeConceptOrderingTest extends TestCase
{
    public function test_enabled_reaches_creative_before_any_evidence_bound_stage(): void
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

        $this->assertCount(1, $instructions, 'only Inspiration should have been attempted');
        $this->assertStringContainsString('source-inspiration brief', mb_strtolower($instructions[0]));

        foreach ($instructions as $instruction) {
            $this->assertStringNotContainsString('candidate world graph', mb_strtolower($instruction));
            $this->assertStringNotContainsString('producer', mb_strtolower($instruction));
            $this->assertStringNotContainsString('director', mb_strtolower($instruction));
        }
    }

    public function test_enabled_builds_a_dynamic_arc_without_constructing_the_old_pipeline(): void
    {
        config([
            'video.creative_concept.mode' => 'enabled',
            'video.llm_cost_ceiling_usd' => 1.0,
        ]);

        $profile = (new CreativeProfileResolver)->resolve('yacht');
        $slotValue = function (array $spec) use (&$slotValue) {
            return match ($spec['type']) {
                'integer' => (int) $spec['min'],
                'number' => (float) $spec['min'],
                'enum' => $spec['values'][0],
                'object' => array_map($slotValue, $spec['fields']),
                default => 'compact technical description',
            };
        };

        $identity = array_map($slotValue, $profile->identitySlots);
        $identity['design_length_m'] = 120.0;
        $identity['design_beam_m'] = 20.0;
        $identity['length_to_beam_ratio'] = 6.0;

        $brief = [
            'article_patterns' => [$profile->articlePatterns[0]],
            'article_focus' => 'A source about a large vessel.',
            'source_insights' => [[
                'aspect' => $profile->inspectionAspects[0],
                'summary' => 'The source describes a steel hull.',
                'source_quotes' => ['The hull is steel.'],
            ]],
            'excluded_context' => [],
        ];
        $concept = [
            'design_thesis' => 'One line integrates every volume.',
            'design_identity' => $identity,
            'form_relationships' => [
                'governing_line' => 'One line runs through the form.',
                'massing_rhythm' => 'Volumes reduce in measured steps.',
                'feature_integration' => 'Features grow from existing faces.',
            ],
            'signature_features' => [[
                'description' => 'A recessed observation terrace.',
                'visible_from' => ['side'],
            ]],
            'decisions' => array_map(fn (string $aspect) => [
                'aspect' => $aspect,
                'provenance' => 'invented',
                'decision' => 'An original decision for this aspect.',
            ], $profile->inspectionAspects),
        ];
        $scene = fn (string $stage, string $purpose) => [
            'stage' => $stage,
            'purpose' => $purpose,
            'objective' => "Show {$stage}.",
            'motion_intent' => 'LOW',
            'camera' => ['framing' => 'WIDE', 'movement' => 'STATIC', 'speed' => 'SLOW'],
            'aesthetic' => ['emotion' => 'CALM', 'composition' => 'CENTERED', 'light_intensity' => 'SOFT', 'light_grade' => 'NEUTRAL'],
            'composition_note' => "The {$stage} state fills the frame.",
            'micro_physics' => [],
        ];
        $arc = ['scenes' => [
            $scene('design', 'ESTABLISH'),
            $scene('construction', 'PROCESS'),
            $scene('construction', 'DETAIL'),
            $scene('completion', 'REVEAL'),
            $scene('operation', 'RESOLUTION'),
        ]];

        $replies = [json_encode($brief), json_encode($concept), json_encode($arc)];
        $instructions = [];
        $writer = $this->createMock(ClaudeWriterService::class);
        $writer->method('generate')->willReturnCallback(
            function (string $input, string $model, string $instruction) use (&$replies, &$instructions) {
                $instructions[] = $instruction;

                return new ClaudeResponse((string) array_shift($replies), 10, 10, 'end_turn');
            },
        );

        $article = new Article(['title' => 'A vessel', 'content' => '<p>The hull is steel.</p>']);
        $article->id = '22222222-2222-4222-8222-222222222222';
        $article->setRelation('category', new Category(['slug' => 'yacht']));
        $service = new VideoRenderPlanService(new ClaudeWriterAdapter($writer), new VideoPipelineFactory);

        $plan = $service->build($article);

        $this->assertCount(3, $instructions);
        $this->assertCount(5, $plan['scenes'], 'Sonnet arc output owns the scene count');
        $this->assertArrayHasKey('creative_concept', $plan);
        $this->assertArrayNotHasKey('producer', $plan);
        $this->assertSame('creation_05_operation', $plan['scenes'][4]['id']);
    }
}
