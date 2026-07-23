<?php

namespace Tests\Video\RenderPlan;

use App\Video\Evidence\Evidence;
use App\Video\Evidence\EvidenceSource;
use App\Video\Evidence\ProvenanceLevel;
use App\Video\Intent\IntentPlanner;
use App\Video\Producer\ProducerOutput;
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

    private function assemble(?ProducerOutput $producer = null, array $directorNotesByScene = [], ?VerifiedWorldGraph $world = null): array
    {
        $world  = $world ?? $this->graph();
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
            $producer,
            $directorNotesByScene,
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

    // ---- Producer: metadata song song, KHÔNG đụng world/acts/scenes ----

    public function test_producer_key_absent_when_not_given(): void
    {
        $plan = $this->assemble();

        $this->assertArrayNotHasKey('producer', $plan);
    }

    public function test_producer_key_present_when_given(): void
    {
        $producer = new ProducerOutput(
            'people who follow shipbuilding',
            'can the yard deliver on time',
            'watch the hull take shape',
            ['anticipation', 'tension', 'relief'],
        );

        $plan = $this->assemble($producer);

        $this->assertSame([
            'target_audience' => 'people who follow shipbuilding',
            'core_conflict'   => 'can the yard deliver on time',
            'visual_promise'  => 'watch the hull take shape',
            'emotional_curve' => ['anticipation', 'tension', 'relief'],
        ], $plan['producer']);
    }

    public function test_producer_never_changes_acts_or_scenes(): void
    {
        // Bằng chứng cho quyết định kiến trúc: Producer là nhánh song song,
        // KHÔNG phải input của StoryPlanner/ScenePlanner (bất biến có test canh
        // ở StoryPlannerTest/ScenePlannerTest). Cùng world phải ra cùng acts/scenes
        // dù có Producer hay không — TRỪ scene.objective, cố tình phụ thuộc
        // Producer (2026-07-22, xem test_producer_populates_scene_objective bên
        // dưới). Đây là field DUY NHẤT được loại khỏi so sánh; mọi field khác
        // (ordinal/act_id/purpose/subjects/camera/aesthetic/asset_refs) vẫn phải
        // giống hệt — nếu Producer vô tình làm lệch field khác, test này vẫn đỏ.
        $producer = new ProducerOutput('a', 'b', 'c', ['d']);

        $without = $this->assemble();
        $with    = $this->assemble($producer);

        unset($without['producer'], $with['producer']);
        foreach ($with['scenes'] as &$scene) {
            unset($scene['objective']);
        }
        unset($scene);

        $this->assertSame(json_encode($without), json_encode($with));
    }

    public function test_producer_populates_scene_objective_from_visual_promise(): void
    {
        $producer = new ProducerOutput('a', 'b', 'watch the hull take shape', ['d']);

        $plan = $this->assemble($producer);

        foreach ($plan['scenes'] as $scene) {
            $this->assertSame('watch the hull take shape', $scene['objective']);
        }
    }

    public function test_scene_objective_absent_when_no_producer(): void
    {
        $plan = $this->assemble();

        foreach ($plan['scenes'] as $scene) {
            $this->assertArrayNotHasKey('objective', $scene);
        }
    }

    // ---- world_environment: Sprint 2, 2026-07-22 — cấp video, chỉ khi đúng 1 Landscape ----

    public function test_world_environment_absent_when_no_landscape_entity(): void
    {
        $plan = $this->assemble();

        $this->assertArrayNotHasKey('world_environment', $plan);
    }

    public function test_world_environment_present_and_validates_against_real_schema(): void
    {
        $world = new VerifiedWorldGraph(
            array_merge($this->graph()->entities(), [
                new Entity('shipyard', EntityType::Landscape, [
                    'weather'     => $this->attr('weather', 'a light drizzle over the yard'),
                    'time_of_day' => $this->attr('time_of_day', 'just after sunset'),
                ]),
            ]),
            $this->graph()->relations,
            $this->graph()->events,
        );

        $plan = $this->assemble(world: $world);

        $this->assertSame(['weather' => 'RAIN', 'time_of_day' => 'GOLDEN_HOUR'], $plan['world_environment']);

        $decoded = json_decode(json_encode($plan), false);
        $schema  = json_decode(file_get_contents(self::SCHEMA), false);
        $result  = (new Validator())->validate($decoded, $schema);

        if ($result->hasError()) {
            $this->fail('RenderPlan có world_environment KHÔNG pass schema:' . "\n"
                . json_encode((new ErrorFormatter())->format($result->error()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $this->addToAssertionCount(1);
    }

    public function test_world_environment_absent_when_two_landscape_entities(): void
    {
        $world = new VerifiedWorldGraph(
            array_merge($this->graph()->entities(), [
                new Entity('shipyard', EntityType::Landscape, ['weather' => $this->attr('weather', 'clear skies')]),
                new Entity('open_sea', EntityType::Landscape, ['weather' => $this->attr('weather', 'light rain')]),
            ]),
            $this->graph()->relations,
            $this->graph()->events,
        );

        $plan = $this->assemble(world: $world);

        $this->assertArrayNotHasKey('world_environment', $plan);
    }

    // ---- director_notes: Phase 3, ARCHITECTURE.md §18.4 — nối thật, không chỉ code cô lập ----

    public function test_director_notes_key_absent_when_not_given(): void
    {
        $plan = $this->assemble();

        foreach ($plan['scenes'] as $scene) {
            $this->assertArrayNotHasKey('director_notes', $scene);
        }
    }

    public function test_director_notes_present_and_validates_against_real_schema(): void
    {
        $baseline = $this->assemble();
        $sceneId  = $baseline['scenes'][0]['id'];

        $directorNotes = [
            $sceneId => [
                'hero' => 'moonrise',
                'primary' => ['type' => 'lift', 'actor' => 'moonrise', 'target' => 'moonrise', 'modifiers' => ['heavy_object']],
                'secondary' => [
                    ['type' => 'signal', 'actor' => 'moonrise', 'target' => 'moonrise', 'modifiers' => []],
                ],
                'micro_physics' => ['the lifting cable holds under visible tension'],
                'audience_emotion' => 'awe',
                'reveal_strategy' => 'delayed',
            ],
        ];

        $plan = $this->assemble(null, $directorNotes);

        $this->assertSame($directorNotes[$sceneId], $plan['scenes'][0]['director_notes']);

        // Bằng chứng thật: schema chấp nhận field mới, không chỉ code chạy được.
        $decoded = json_decode(json_encode($plan), false);
        $schema  = json_decode(file_get_contents(self::SCHEMA), false);
        $result  = (new Validator())->validate($decoded, $schema);

        if ($result->hasError()) {
            $this->fail('RenderPlan có director_notes KHÔNG pass schema:' . "\n"
                . json_encode((new ErrorFormatter())->format($result->error()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        $this->addToAssertionCount(1);
    }

    public function test_director_notes_only_attaches_to_the_matching_scene_id(): void
    {
        $baseline = $this->assemble();
        if (count($baseline['scenes']) < 2) {
            $this->markTestSkipped('fixture cần ≥2 scene để test cách ly theo scene id');
        }
        $sceneId = $baseline['scenes'][0]['id'];

        $plan = $this->assemble(null, [
            $sceneId => ['hero' => 'moonrise'],
        ]);

        $this->assertArrayHasKey('director_notes', $plan['scenes'][0]);
        for ($i = 1; $i < count($plan['scenes']); $i++) {
            $this->assertArrayNotHasKey('director_notes', $plan['scenes'][$i]);
        }
    }

    // ---- repairAfterDatabaseRoundTrip(): bug thật bắt qua nút 🎬 thật, 2026-07-22 ----

    public function test_repair_converts_empty_attributes_array_back_to_object(): void
    {
        // Mô phỏng đúng cái Eloquent array cast trả về sau khi decode JSON:
        // {} object rỗng -> [] array rỗng, không còn phân biệt được.
        $plan = ['world' => ['entities' => [
            ['id' => 'feadship', 'type' => 'building', 'attributes' => []],
        ]]];

        $repaired = RenderPlanAssembler::repairAfterDatabaseRoundTrip($plan);

        $this->assertInstanceOf(\stdClass::class, $repaired['world']['entities'][0]['attributes']);
    }

    public function test_repair_leaves_non_empty_attributes_untouched(): void
    {
        $plan = ['world' => ['entities' => [
            ['id' => 'moonrise', 'type' => 'vehicle', 'attributes' => ['hull_color' => 'grey']],
        ]]];

        $repaired = RenderPlanAssembler::repairAfterDatabaseRoundTrip($plan);

        $this->assertSame(['hull_color' => 'grey'], $repaired['world']['entities'][0]['attributes']);
    }

    public function test_repair_after_database_round_trip_makes_plan_pass_schema_again(): void
    {
        // Bằng chứng đầy đủ: assemble() tươi (đã đúng) -> giả lập round-trip DB
        // (json_decode assoc=true, chính là cái Eloquent làm) -> FAIL schema ->
        // repair() -> PASS schema lại.
        $baseline = $this->assemble();
        // Ép có ít nhất 1 entity anchor-only để tái hiện đúng bug (Feadship trong
        // graph() vốn đã anchor-only — xem test_anchor_only_entity_appears_in_world_with_empty_attributes).

        $roundTripped = json_decode(json_encode($baseline), true); // Eloquent array cast decode y hệt kiểu này

        $decoded = json_decode(json_encode($roundTripped), false);
        $schema  = json_decode(file_get_contents(self::SCHEMA), false);
        $before  = (new Validator())->validate($decoded, $schema);
        $this->assertTrue($before->hasError(), 'test tự kiểm: round-trip phải TÁI HIỆN được bug trước khi verify repair() sửa nó');

        $repaired = RenderPlanAssembler::repairAfterDatabaseRoundTrip($roundTripped);

        $decodedRepaired = json_decode(json_encode($repaired), false);
        $after = (new Validator())->validate($decodedRepaired, $schema);
        if ($after->hasError()) {
            $this->fail('repair() KHÔNG sửa được:' . "\n"
                . json_encode((new ErrorFormatter())->format($after->error()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        $this->addToAssertionCount(1);
    }
}
