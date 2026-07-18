<?php

namespace Tests\Video\RenderPlan;

use App\Video\Evidence\Evidence;
use App\Video\Evidence\EvidenceSource;
use App\Video\Evidence\ProvenanceLevel;
use App\Video\Intent\IntentPlanner;
use App\Video\RenderPlan\RenderPlanAssembler;
use App\Video\RenderPlan\RenderPlanMeta;
use App\Video\Scene\ScenePlanner;
use App\Video\Story\StoryPlanner;
use App\Video\Timeline\TimelinePlanner;
use App\Video\World\Entity;
use App\Video\World\EntityType;
use App\Video\World\Event;
use App\Video\World\Identity;
use App\Video\World\Relation;
use App\Video\World\VerifiedAttribute;
use App\Video\World\VerifiedWorldGraph;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Assembler ráp toàn pipeline → RenderPlan. Phép thử quyết định: document ráp ra
 * phải PASS đúng schema mà Python sẽ dùng để gác cổng. Nếu Assembler sinh ra thứ
 * schema từ chối thì Python sẽ từ chối, và pipeline đứt ở ranh giới.
 */
class RenderPlanAssemblerTest extends TestCase
{
    private const SCHEMA = __DIR__ . '/../../../contracts/renderplan/v1.0/schema.json';

    private function ev(string $q = 'x'): Evidence
    {
        return new Evidence($q, EvidenceSource::Body, 0, ProvenanceLevel::Direct);
    }

    private function attr(string $n, mixed $v): array
    {
        return [new VerifiedAttribute($n, $v, $this->ev(), ProvenanceLevel::Direct)];
    }

    private function multiAttr(string $n, array $vals): array
    {
        return array_map(fn ($v) => new VerifiedAttribute($n, $v, $this->ev(), ProvenanceLevel::Direct), $vals);
    }

    private function graph(): VerifiedWorldGraph
    {
        return new VerifiedWorldGraph(
            [
                new Entity('moonrise', EntityType::Vehicle, [
                    'length_meters'   => $this->attr('length_meters', 99.95),
                    'hull_color'      => $this->attr('hull_color', 'grey'),
                    'onboard_amenity' => $this->multiAttr('onboard_amenity', ['spa', 'helipad']),
                ], new Identity('Moonrise', true, $this->ev())),
                new Entity('jan_koum', EntityType::Human, [
                    'occupation' => $this->attr('occupation', 'founder'),
                ], new Identity('Jan Koum', false, $this->ev())),
                new Entity('feadship', EntityType::Building, [], new Identity('Feadship', true, $this->ev())),
            ],
            [
                new Relation('r1', 'feadship', 'moonrise', 'built', $this->ev()),
                new Relation('r2', 'jan_koum', 'moonrise', 'original_owner', $this->ev()),
            ],
            [new Event('e1', 'sale', 'moonrise', $this->ev())],
        );
    }

    private function assemble(): array
    {
        $world  = $this->graph();
        $story  = (new StoryPlanner(maxActs: 8))->plan($world);
        $scenes = (new ScenePlanner())->plan($story, $world);
        $intent = (new IntentPlanner())->plan($scenes);
        $timed  = (new TimelinePlanner())->plan($intent, 60.0);

        return (new RenderPlanAssembler())->assemble(
            $world, $story, $timed,
            new RenderPlanMeta(
                '0198f3a1-4b2c-4d3e-8f10-2a3b4c5d6e7f',
                '7c9e6679-7425-40de-944b-e07fc1f90ae7',
                'Moonrise sold',
                'en',
                '2026-07-18T00:00:00Z',
            ),
        );
    }

    public function test_assembled_plan_validates_against_the_real_schema(): void
    {
        $plan   = json_decode(json_encode($this->assemble()), false);
        $schema = json_decode(file_get_contents(self::SCHEMA), false);

        $result = (new Validator())->validate($plan, $schema);

        if ($result->hasError()) {
            $this->fail('RenderPlan ráp ra KHÔNG pass schema:' . "\n"
                . json_encode((new ErrorFormatter())->format($result->error()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $this->addToAssertionCount(1);
    }

    public function test_scenes_carry_aesthetic_but_omit_world_when_truth_is_silent(): void
    {
        $plan = $this->assemble();

        foreach ($plan['scenes'] as $scene) {
            $this->assertArrayHasKey('aesthetic', $scene, 'aesthetic luôn có (Editorial)');
            $this->assertArrayNotHasKey('world', $scene, 'world VẮNG vì Truth chưa có environment fact — §13, không bịa');
        }
    }

    public function test_timeline_aligns_one_to_one_with_scenes_and_covers_target(): void
    {
        $plan = $this->assemble();

        $this->assertSameSize($plan['scenes'], $plan['timeline']);
        $this->assertSame(0.0, $plan['timeline'][0]['start_sec']);
        $this->assertSame(60.0, end($plan['timeline'])['end_sec']);
    }

    public function test_assets_are_projection_of_unique_subjects(): void
    {
        $plan = $this->assemble();

        $assetEntities = array_column($plan['assets'], 'entity_id');

        $this->assertContains('moonrise', $assetEntities);
        $this->assertSame(count($assetEntities), count(array_unique($assetEntities)), 'mỗi entity một asset, không trùng');
        $this->assertSame('vehicle', $plan['assets'][array_search('moonrise', $assetEntities, true)]['kind']);
    }

    public function test_invariants_come_from_multiscene_entities_single_valued_only(): void
    {
        $plan = $this->assemble();
        $inv  = $plan['continuity']['invariants'];

        $attrs = array_column(array_filter($inv, fn ($i) => $i['entity_id'] === 'moonrise'), 'attribute');

        // moonrise xuất hiện nhiều scene → hull_color/length thành invariant.
        $this->assertContains('hull_color', $attrs);
        // Thuộc tính nhiều-giá-trị (amenities) KHÔNG thành invariant.
        $this->assertNotContains('onboard_amenity', $attrs);
    }

    public function test_anchor_only_entity_appears_in_world_with_empty_attributes(): void
    {
        $plan = $this->assemble();

        $feadship = array_values(array_filter($plan['world']['entities'], fn ($e) => $e['id'] === 'feadship'))[0];

        $this->assertSame([], (array) $feadship['attributes'], 'Feadship anchor-only: có tên, không thuộc tính');
        $this->assertSame('{}', json_encode($feadship['attributes']), 'attributes rỗng encode thành object {} không phải []');
        $this->assertSame('Feadship', $feadship['identity']['name']);
    }

    public function test_identity_semantic_is_omitted_when_empty(): void
    {
        $plan = $this->assemble();

        $jan = array_values(array_filter($plan['world']['entities'], fn ($e) => $e['id'] === 'jan_koum'))[0];

        // Không có semantic data → không emit khóa semantic rỗng.
        $this->assertArrayNotHasKey('semantic', $jan['identity']);
        $this->assertFalse($jan['identity']['visual_referent']);
    }

    public function test_is_deterministic(): void
    {
        // So sánh dạng JSON: assemble() chứa stdClass (attributes rỗng) nên
        // assertSame trên mảng sẽ so hai instance object khác nhau.
        $this->assertSame(json_encode($this->assemble()), json_encode($this->assemble()));
    }
}
