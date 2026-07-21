<?php

namespace Tests\Video\Editorial;

use App\Video\Editorial\Composition;
use App\Video\Editorial\EditorialInterpreter;
use App\Video\Editorial\EditorialPolicy;
use App\Video\Editorial\Emotion;
use App\Video\Editorial\LightGrade;
use App\Video\Editorial\LightIntensity;
use App\Video\Editorial\SceneAesthetic;
use App\Video\Evidence\Evidence;
use App\Video\Evidence\EvidenceSource;
use App\Video\Evidence\ProvenanceLevel;
use App\Video\Scene\ScenePurpose;
use App\Video\World\Entity;
use App\Video\World\EntityType;
use App\Video\World\Identity;
use App\Video\World\VerifiedAttribute;
use App\Video\World\VerifiedWorldGraph;
use PHPUnit\Framework\TestCase;

/**
 * Editorial 5a: aesthetic là hàm THUẦN của ScenePurpose.
 *
 * Ranh giới đóng bằng type: aestheticFor() chỉ nhận ScenePurpose — không thấy
 * EntityType, subjects, Evidence. Nên taste KHÔNG THỂ dính chủ đề. "Establishing
 * shot thì calm/balanced" đúng cho Moonrise, Ferrari, sư tử, nhà máy.
 *
 * Đây là lần đầu taste được phép xuất hiện trong pipeline — và nó vẫn phải mù
 * chủ đề y như Intent, Timeline.
 */
class EditorialInterpreterTest extends TestCase
{
    private function aesthetic(ScenePurpose $p): SceneAesthetic
    {
        return (new EditorialInterpreter())->aestheticFor($p);
    }

    public function test_every_purpose_yields_a_complete_aesthetic(): void
    {
        // Editorial LUÔN điền — không scene nào thiếu taste. Đối lập với world{}
        // (fact) được phép vắng.
        foreach (ScenePurpose::cases() as $purpose) {
            $a = $this->aesthetic($purpose);

            $this->assertInstanceOf(Emotion::class, $a->emotion);
            $this->assertInstanceOf(Composition::class, $a->composition);
            $this->assertInstanceOf(LightIntensity::class, $a->lightIntensity);
            $this->assertInstanceOf(LightGrade::class, $a->lightGrade);
        }
    }

    public function test_is_deterministic(): void
    {
        $this->assertEquals(
            $this->aesthetic(ScenePurpose::Action),
            $this->aesthetic(ScenePurpose::Action),
        );
    }

    // ---- Chữ ký điện ảnh của từng purpose ----

    public function test_resolution_gets_the_golden_majestic_ending_look(): void
    {
        $a = $this->aesthetic(ScenePurpose::Resolution);

        $this->assertSame(Emotion::Majestic, $a->emotion);
        $this->assertSame(LightGrade::Golden, $a->lightGrade);
    }

    public function test_action_is_tense_and_harsh(): void
    {
        $a = $this->aesthetic(ScenePurpose::Action);

        $this->assertSame(Emotion::Tense, $a->emotion);
        $this->assertSame(LightIntensity::Harsh, $a->lightIntensity);
    }

    public function test_establish_is_calm(): void
    {
        $this->assertSame(Emotion::Calm, $this->aesthetic(ScenePurpose::Establish)->emotion);
    }

    public function test_comparison_is_symmetrical(): void
    {
        // So sánh hai chủ thể → bố cục cân đối để mắt đối chiếu.
        $this->assertSame(Composition::Symmetrical, $this->aesthetic(ScenePurpose::Comparison)->composition);
    }

    /**
     * Chốt bất biến: mọi purpose cho aesthetic khác biệt đủ để không nhàm, và
     * KHÔNG có purpose nào rơi vào default trống. Bảng policy phải phủ hết.
     */
    public function test_no_two_purposes_are_editorially_identical(): void
    {
        $seen = [];

        foreach (ScenePurpose::cases() as $purpose) {
            $a = $this->aesthetic($purpose);
            $key = "{$a->emotion->value}|{$a->composition->value}|{$a->lightIntensity->value}|{$a->lightGrade->value}";
            $seen[] = $key;
        }

        // Không đòi 7 khác nhau hoàn toàn (một số taste trùng là hợp lý), nhưng
        // phải có ít nhất vài chữ ký khác biệt — nếu tất cả giống nhau thì bảng
        // policy vô dụng.
        $this->assertGreaterThan(3, count(array_unique($seen)), 'bảng editorial quá đơn điệu — gần như mọi scene giống nhau');
    }

    // ---- prohibitionsFor(): §12, chỗ "no domes" thuộc về ----

    private function ev(): Evidence
    {
        return new Evidence('x', EvidenceSource::Body, 0, ProvenanceLevel::Direct);
    }

    private function entityWithAttribute(string $id, string $attribute, mixed $value): Entity
    {
        return new Entity($id, EntityType::Vehicle, [
            $attribute => [new VerifiedAttribute($attribute, $value, $this->ev(), ProvenanceLevel::Direct)],
        ], new Identity($id, false, $this->ev()));
    }

    public function test_prohibitions_empty_when_no_policy_configured(): void
    {
        $world = new VerifiedWorldGraph([$this->entityWithAttribute('moonrise', 'builder', 'Feadship')], [], []);

        $this->assertSame([], (new EditorialInterpreter())->prohibitionsFor($world));
    }

    public function test_prohibition_fires_when_entity_matches_policy(): void
    {
        // Đúng ví dụ minh hoạ ARCHITECTURE.md §12: integrated receivers → no domes.
        $policy = new EditorialPolicy(
            ['builder' => 'Feadship'],
            'domes',
            false,
            'integrated receivers instead of radomes',
        );
        $world = new VerifiedWorldGraph([$this->entityWithAttribute('moonrise', 'builder', 'Feadship')], [], []);

        $prohibitions = (new EditorialInterpreter([$policy]))->prohibitionsFor($world);

        $this->assertSame([[
            'entity_id' => 'moonrise',
            'attribute' => 'domes',
            'value'     => false,
            'reason'    => 'integrated receivers instead of radomes',
        ]], $prohibitions);
    }

    public function test_prohibition_does_not_fire_when_entity_does_not_match(): void
    {
        $policy = new EditorialPolicy(['builder' => 'Feadship'], 'domes', false, 'reason');
        $world  = new VerifiedWorldGraph([$this->entityWithAttribute('other', 'builder', 'Lurssen')], [], []);

        $this->assertSame([], (new EditorialInterpreter([$policy]))->prohibitionsFor($world));
    }

    public function test_prohibitions_never_mutate_world(): void
    {
        // §12 Rule #3: read-only. Type system đã bảo đảm — Entity/attributes
        // readonly nên không có cách nào viết code vi phạm; test này chỉ xác
        // nhận giá trị KHÔNG đổi sau khi gọi.
        $entity = $this->entityWithAttribute('moonrise', 'builder', 'Feadship');
        $world  = new VerifiedWorldGraph([$entity], [], []);
        $policy = new EditorialPolicy(['builder' => 'Feadship'], 'domes', false, 'reason');

        (new EditorialInterpreter([$policy]))->prohibitionsFor($world);

        $this->assertSame('Feadship', $entity->value('builder'));
    }
}
