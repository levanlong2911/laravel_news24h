<?php

namespace Tests\Video\Pipeline;

use App\Video\Article\RawArticle;
use App\Video\Director\ActionSelection;
use App\Video\Director\DirectorInterface;
use App\Video\Extraction\CandidateClaim;
use App\Video\Extraction\CandidateEntity;
use App\Video\Extraction\CandidateRelation;
use App\Video\Extraction\CandidateWorldGraph;
use App\Video\Extraction\FakeExtractor;
use App\Video\Pipeline\VideoPlanningPipeline;
use App\Video\Producer\FakeProducer;
use App\Video\Producer\ProducerOutput;
use App\Video\RenderPlan\RenderPlanMeta;
use App\Video\World\VerifiedWorldGraph;
use PHPUnit\Framework\TestCase;

/**
 * 2026-07-23: Director giữ "nhật ký" tối đa 3 scene GẦN NHẤT (xem
 * DirectorInterface::select() docblock) — cảnh sau biết cảnh trước đã chọn
 * hero/emotion/composition gì, thay vì quyết từng shot độc lập.
 *
 * SpyDirector ghi lại đúng $priorScenes nó nhận được ở mỗi lần gọi, KHÔNG
 * đụng logic thật của ClaudeDirector (không gọi mạng) — test này chỉ khoá
 * hành vi của VideoPlanningPipeline (tích luỹ + cắt còn 3).
 */
class VideoPlanningPipelineDirectorMemoryTest extends TestCase
{
    /** @var list<array{ordinal: int, totalScenes: int, priorScenes: array}> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->calls = [];
    }

    public function recordCall(array $call): void
    {
        $this->calls[] = $call;
    }

    private function spyDirector(): DirectorInterface
    {
        return new class($this) implements DirectorInterface {
            public function __construct(private readonly VideoPlanningPipelineDirectorMemoryTest $test)
            {
            }

            public function select(array $candidates, VerifiedWorldGraph $world, ?ProducerOutput $producer, int $sceneOrdinal = 1, int $totalScenes = 1, array $priorScenes = []): ActionSelection
            {
                $this->test->recordCall([
                    'ordinal'     => $sceneOrdinal,
                    'totalScenes' => $totalScenes,
                    'priorScenes' => $priorScenes,
                ]);

                return new ActionSelection(
                    '',
                    0,
                    [],
                    "emotion_{$sceneOrdinal}",
                    'immediate',
                    "note_{$sceneOrdinal}",
                );
            }
        };
    }

    /**
     * Fixture đã dò qua Tinker (2026-07-23): crane1/blockA/blockB/inspector +
     * 3 quan hệ lifts/secures/inspects (khớp ACTION_KEYWORDS) → 7 act, 7 scene,
     * MỌI scene đều có action_candidates không rỗng → Director bị gọi đủ 7
     * lần, đủ để kiểm tra tích luỹ + cắt còn 3.
     */
    private function candidates(): CandidateWorldGraph
    {
        return new CandidateWorldGraph(
            [
                new CandidateEntity('crane1', 'physical_object', [
                    new CandidateClaim('crane1', 'weight_tons', 500, 'The crane weighed 500 tons'),
                ]),
                new CandidateEntity('blockA', 'physical_object', [
                    new CandidateClaim('blockA', 'material', 'steel', 'Block A was made of steel'),
                ]),
                new CandidateEntity('blockB', 'physical_object', [
                    new CandidateClaim('blockB', 'material', 'steel', 'Block B was made of steel'),
                ]),
                new CandidateEntity('inspector', 'human', [
                    new CandidateClaim('inspector', 'occupation', 'site inspector', 'The site inspector arrived on site'),
                ]),
            ],
            [
                new CandidateRelation('r1', 'crane1', 'blockA', 'lifts', 'The crane lifted block A into place'),
                new CandidateRelation('r2', 'crane1', 'blockB', 'secures', 'The crane secured block B to the hull'),
                new CandidateRelation('r3', 'inspector', 'crane1', 'inspects', 'The inspector inspected the crane'),
            ],
        );
    }

    private function article(): RawArticle
    {
        return new RawArticle('art_1', 'Test', 'The crane weighed 500 tons. Block A was made of steel. Block B was made of steel. The site inspector arrived on site. The crane lifted block A into place. The crane secured block B to the hull. The inspector inspected the crane.');
    }

    private function meta(): RenderPlanMeta
    {
        return new RenderPlanMeta(
            '0198f3a1-4b2c-4d3e-8f10-2a3b4c5d6e7f',
            '7c9e6679-7425-40de-944b-e07fc1f90ae7',
            'Crane test',
            'en',
            '2026-07-23T00:00:00Z',
        );
    }

    public function test_first_scene_gets_no_prior_scenes(): void
    {
        $pipeline = new VideoPlanningPipeline(
            new FakeExtractor($this->candidates()),
            new FakeProducer(new ProducerOutput('a', 'b', 'c', [])),
            $this->spyDirector(),
        );

        $pipeline->plan($this->article(), $this->meta());

        $this->assertNotEmpty($this->calls);
        $this->assertSame([], $this->calls[0]['priorScenes']);
    }

    public function test_second_scene_sees_exactly_the_first_scenes_log_entry(): void
    {
        $pipeline = new VideoPlanningPipeline(
            new FakeExtractor($this->candidates()),
            new FakeProducer(new ProducerOutput('a', 'b', 'c', [])),
            $this->spyDirector(),
        );

        $pipeline->plan($this->article(), $this->meta());

        $this->assertGreaterThanOrEqual(2, count($this->calls));
        $this->assertCount(1, $this->calls[1]['priorScenes']);
        $this->assertSame([
            'ordinal'          => $this->calls[0]['ordinal'],
            'hero'             => '',
            'emotion'          => "emotion_{$this->calls[0]['ordinal']}",
            'composition_note' => "note_{$this->calls[0]['ordinal']}",
        ], $this->calls[1]['priorScenes'][0]);
    }

    public function test_log_never_exceeds_three_prior_scenes(): void
    {
        $pipeline = new VideoPlanningPipeline(
            new FakeExtractor($this->candidates()),
            new FakeProducer(new ProducerOutput('a', 'b', 'c', [])),
            $this->spyDirector(),
        );

        $pipeline->plan($this->article(), $this->meta());

        $this->assertGreaterThanOrEqual(5, count($this->calls), 'fixture phải cho đủ scene để kiểm tra cắt log');

        foreach ($this->calls as $call) {
            $this->assertLessThanOrEqual(3, count($call['priorScenes']));
        }

        // Từ scene thứ 5 trở đi, log phải LUÔN đầy đủ 3 (đã đủ lịch sử để cắt).
        for ($i = 4; $i < count($this->calls); $i++) {
            $this->assertCount(3, $this->calls[$i]['priorScenes']);
        }
    }

    public function test_prior_scenes_are_kept_in_chronological_order(): void
    {
        $pipeline = new VideoPlanningPipeline(
            new FakeExtractor($this->candidates()),
            new FakeProducer(new ProducerOutput('a', 'b', 'c', [])),
            $this->spyDirector(),
        );

        $pipeline->plan($this->article(), $this->meta());

        $lastCall = end($this->calls);
        $ordinals = array_column($lastCall['priorScenes'], 'ordinal');

        $sorted = $ordinals;
        sort($sorted);
        $this->assertSame($sorted, $ordinals, 'log phải giữ thứ tự cũ->mới, không đảo lộn');
    }
}
