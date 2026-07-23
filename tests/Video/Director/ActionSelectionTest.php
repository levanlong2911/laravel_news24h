<?php

namespace Tests\Video\Director;

use App\Video\Director\ActionSelection;
use App\Video\Director\FakeDirector;
use App\Video\Editorial\ActionCandidate;
use App\Video\Editorial\ActionType;
use App\Video\Editorial\EditorialInterpreter;
use App\Video\Evidence\Evidence;
use App\Video\Evidence\EvidenceSource;
use App\Video\Evidence\ProvenanceLevel;
use App\Video\World\Entity;
use App\Video\World\EntityType;
use App\Video\World\Relation;
use App\Video\World\VerifiedAttribute;
use App\Video\World\VerifiedWorldGraph;
use PHPUnit\Framework\TestCase;

/**
 * Chuỗi thật Phase 3: candidatesFor() (Rule Engine) -> Director chọn -> resolve()
 * -> microPhysicsFor() (Rule Engine, sau khi biết primary) -> shape director_notes.
 * Xem ARCHITECTURE.md §18.4.
 */
class ActionSelectionTest extends TestCase
{
    private function ev(): Evidence
    {
        return new Evidence('x', EvidenceSource::Body, 0, ProvenanceLevel::Direct);
    }

    private function world(): VerifiedWorldGraph
    {
        $crane = new Entity('goliathcrane', EntityType::PhysicalObject, []);
        $block = new Entity('sternblock', EntityType::PhysicalObject, [
            'weight_tons' => [new VerifiedAttribute('weight_tons', 1250, $this->ev(), ProvenanceLevel::Direct)],
        ]);
        $worker = new Entity('signalman', EntityType::Human, []);

        return new VerifiedWorldGraph(
            [$crane, $block, $worker],
            [
                new Relation('r1', 'goliathcrane', 'sternblock', 'lifts', $this->ev()),
                new Relation('r2', 'signalman', 'goliathcrane', 'signals', $this->ev()),
            ],
            [],
        );
    }

    public function test_resolve_maps_indices_back_to_full_candidates(): void
    {
        $candidates = [
            new ActionCandidate(ActionType::Lift, 'goliathcrane', 'sternblock', ['heavy_object']),
            new ActionCandidate(ActionType::Signal, 'signalman', 'goliathcrane', []),
        ];

        $selection = new ActionSelection('sternblock', 0, [1], 'awe', 'delayed');

        $resolved = $selection->resolve($candidates);

        $this->assertSame('sternblock', $resolved['hero']);
        $this->assertSame([
            'type' => 'lift', 'actor' => 'goliathcrane', 'target' => 'sternblock', 'modifiers' => ['heavy_object'],
        ], $resolved['primary']);
        $this->assertSame([[
            'type' => 'signal', 'actor' => 'signalman', 'target' => 'goliathcrane', 'modifiers' => [],
        ]], $resolved['secondary']);
    }

    public function test_full_chain_from_world_to_director_notes_shape(): void
    {
        $world = $this->world();
        $scene = new \App\Video\Scene\SemanticScene('s1', 'a1', 1, \App\Video\Scene\ScenePurpose::Action,
            ['goliathcrane', 'sternblock', 'signalman']);
        $editorial = new EditorialInterpreter();

        // 1. Rule Engine sinh candidates — deterministic, $0
        $candidates = $editorial->candidatesFor($scene, $world);
        $this->assertCount(2, $candidates['action_candidates']);

        // 2. Director (Fake — thay Claude thật) chọn
        $director = new FakeDirector(new ActionSelection('sternblock', 0, [1], 'awe', 'delayed'));
        $selection = $director->select($candidates, $world, null);

        // 3. resolve() — index -> object
        $resolved = $selection->resolve($candidates['action_candidates']);

        // 4. microPhysicsFor() — SAU khi biết primary đã chọn, không phải trước
        $chosenPrimary = $candidates['action_candidates'][$selection->primaryCandidateIndex];
        $microPhysics = $editorial->microPhysicsFor($chosenPrimary);

        $directorNotes = array_merge($resolved, [
            'audience_emotion' => $selection->emotion,
            'reveal_strategy' => $selection->reveal,
            'micro_physics' => $microPhysics,
        ]);

        // Khớp đúng shape schema director_notes (contracts/renderplan/v1.0/schema.json)
        $this->assertSame('sternblock', $directorNotes['hero']);
        $this->assertSame('lift', $directorNotes['primary']['type']);
        $this->assertSame(['heavy_object'], $directorNotes['primary']['modifiers']);
        $this->assertSame(['the lifting cable holds under visible tension'], $directorNotes['micro_physics']);
        $this->assertSame('awe', $directorNotes['audience_emotion']);
        $this->assertSame('delayed', $directorNotes['reveal_strategy']);
    }

    // ---- hero rỗng: bug thật bắt qua nút 🎬 lần 3, 2026-07-22 ----

    public function test_resolve_omits_hero_key_when_hero_entity_is_empty(): void
    {
        // Đúng ca thật: actor là entity anchor-only (vd "Don Julio Tequila" chỉ
        // có identity.name, không attribute) — không nằm trong hero_candidates,
        // Director không có gì hợp lệ để chọn.
        $candidates = [new ActionCandidate(ActionType::Release, 'don_julio_tequila', 'limited_edition_bottle', [])];
        $selection = new ActionSelection('', 0, [], 'euphoric anticipation', 'immediate');

        $resolved = $selection->resolve($candidates);

        $this->assertArrayNotHasKey('hero', $resolved);
        $this->assertSame('release', $resolved['primary']['type']);
    }

    public function test_resolve_keeps_hero_key_when_present(): void
    {
        $candidates = [new ActionCandidate(ActionType::Lift, 'crane', 'sternblock', [])];
        $selection = new ActionSelection('sternblock', 0, [], 'awe', 'immediate');

        $resolved = $selection->resolve($candidates);

        $this->assertSame('sternblock', $resolved['hero']);
    }
}
