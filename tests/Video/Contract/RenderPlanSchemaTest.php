<?php

namespace Tests\Video\Contract;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Gate cứng nhất của Phase 0.
 *
 * RenderPlan là ranh giới DUY NHẤT giữa Laravel (Semantic OS) và Python
 * (Compiler + Runtime). Sai contract thì cả hai bên sai theo — nên schema
 * phải gác cổng ở cả hai đầu, và cùng một file schema/fixture được cả PHP
 * lẫn Python đọc. Xem docs/video/ARCHITECTURE.md §6.
 */
class RenderPlanSchemaTest extends TestCase
{
    private const CONTRACT_DIR = __DIR__ . '/../../../contracts/renderplan/v1.0';

    private Validator $validator;
    private object $schema;

    protected function setUp(): void
    {
        $this->validator = new Validator();
        $this->schema = json_decode(file_get_contents(self::CONTRACT_DIR . '/schema.json'), false, 512, JSON_THROW_ON_ERROR);
    }

    private function fixture(): object
    {
        return json_decode(file_get_contents(self::CONTRACT_DIR . '/fixtures/moonrise.json'), false, 512, JSON_THROW_ON_ERROR);
    }

    private function assertPlanValid(object $plan, string $message = ''): void
    {
        $result = $this->validator->validate($plan, $this->schema);

        if ($result->hasError()) {
            $errors = (new ErrorFormatter())->format($result->error());
            $this->fail($message . "\n" . json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $this->addToAssertionCount(1);
    }

    private function assertPlanRejected(object $plan, string $why): void
    {
        $this->assertTrue(
            $this->validator->validate($plan, $this->schema)->hasError(),
            "Schema PHẢI reject: {$why}",
        );
    }

    public function test_golden_fixture_is_valid(): void
    {
        $this->assertPlanValid($this->fixture(), 'Golden fixture Moonrise phải pass schema v1.0');
    }

    public function test_timeline_covers_target_seconds_without_gaps_or_overlaps(): void
    {
        $plan = $this->fixture();
        $slots = $plan->timeline;

        usort($slots, fn ($a, $b) => $a->start_sec <=> $b->start_sec);

        $this->assertSame(0.0, (float) $slots[0]->start_sec, 'Timeline phải bắt đầu từ 0');

        foreach ($slots as $i => $slot) {
            $this->assertLessThan($slot->end_sec, $slot->start_sec, "Slot {$slot->scene_id} có độ dài <= 0");

            if ($i > 0) {
                $this->assertSame(
                    (float) $slots[$i - 1]->end_sec,
                    (float) $slot->start_sec,
                    "Timeline hở hoặc chồng lấn giữa {$slots[$i - 1]->scene_id} và {$slot->scene_id}",
                );
            }
        }

        $this->assertSame(
            (float) $plan->story->target_seconds,
            (float) end($slots)->end_sec,
            'Timeline phải phủ kín target_seconds',
        );
    }

    /**
     * Mọi ref phải trỏ tới thứ có thật. Schema không tự làm được việc này —
     * JSON Schema kiểm được hình dạng, không kiểm được tính toàn vẹn tham chiếu.
     */
    public function test_every_reference_resolves(): void
    {
        $plan = $this->fixture();

        $entityIds   = array_column($plan->world->entities, 'id');
        $relationIds = array_column($plan->world->relations, 'id');
        $eventIds    = array_column($plan->world->events, 'id');
        $factIds     = array_column($plan->facts, 'id');
        $actIds      = array_column($plan->acts, 'id');
        $sceneIds    = array_column($plan->scenes, 'id');
        $assetIds    = array_column($plan->assets, 'id');

        foreach ($plan->world->relations as $r) {
            $this->assertContains($r->from, $entityIds, "relation {$r->id}.from trỏ tới entity không tồn tại");
            $this->assertContains($r->to, $entityIds, "relation {$r->id}.to trỏ tới entity không tồn tại");
        }

        foreach ($plan->world->events as $e) {
            $this->assertContains($e->entity_id, $entityIds, "event {$e->id} trỏ tới entity không tồn tại");
        }

        foreach ($plan->facts as $f) {
            $this->assertContains($f->entity_id, $entityIds, "fact {$f->id} trỏ tới entity không tồn tại");
        }

        // Act = node|edge của World Graph. Xem ARCHITECTURE.md §3.
        foreach ($plan->acts as $a) {
            match ($a->source) {
                'ENTITY'   => $this->assertContains($a->entity_ref, $entityIds, "act {$a->id} trỏ tới entity không tồn tại"),
                'EVENT'    => $this->assertContains($a->event_ref, $eventIds, "act {$a->id} trỏ tới event không tồn tại"),
                'RELATION' => $this->assertContains($a->relation_ref, $relationIds, "act {$a->id} trỏ tới relation không tồn tại"),
            };
        }

        foreach ($plan->scenes as $s) {
            $this->assertContains($s->act_id, $actIds, "scene {$s->id} trỏ tới act không tồn tại");
            $this->assertContains($s->camera->target, $entityIds, "scene {$s->id} camera.target trỏ tới entity không tồn tại");

            foreach ($s->subjects as $subject) {
                $this->assertContains($subject, $entityIds, "scene {$s->id} subject '{$subject}' không tồn tại");
            }
            foreach ($s->fact_refs ?? [] as $ref) {
                $this->assertContains($ref, $factIds, "scene {$s->id} fact_ref '{$ref}' không tồn tại");
            }
            foreach ($s->asset_refs ?? [] as $ref) {
                $this->assertContains($ref, $assetIds, "scene {$s->id} asset_ref '{$ref}' không tồn tại");
            }
        }

        foreach ($plan->timeline as $slot) {
            $this->assertContains($slot->scene_id, $sceneIds, "timeline trỏ tới scene không tồn tại: {$slot->scene_id}");
        }

        foreach ($plan->assets as $a) {
            $this->assertContains($a->entity_id, $entityIds, "asset {$a->id} trỏ tới entity không tồn tại");
        }

        foreach ([...$plan->continuity->invariants, ...$plan->continuity->prohibitions] as $rule) {
            $this->assertContains($rule->entity_id, $entityIds, 'continuity rule trỏ tới entity không tồn tại');
        }
    }

    /**
     * Continuity không được tự mâu thuẫn: một prohibition không được cấm đúng
     * giá trị mà invariant bắt buộc.
     */
    public function test_continuity_rules_do_not_contradict(): void
    {
        $plan = $this->fixture();

        foreach ($plan->continuity->prohibitions as $p) {
            foreach ($plan->continuity->invariants as $i) {
                if ($i->entity_id === $p->entity_id && $i->attribute === $p->attribute && $i->value === $p->value) {
                    $this->fail("Continuity tự mâu thuẫn: {$p->entity_id}.{$p->attribute} vừa bắt buộc vừa bị cấm");
                }
            }
        }

        $this->addToAssertionCount(1);
    }

    // ---- Schema phải REJECT các vi phạm kiến trúc ----

    public function test_rejects_domain_specific_entity_type(): void
    {
        $plan = $this->fixture();
        $plan->world->entities[0]->type = 'superyacht'; // ontology chung — §3

        $this->assertPlanRejected($plan, 'entity.type domain-specific ("superyacht")');
    }

    public function test_rejects_unknown_field(): void
    {
        $plan = $this->fixture();
        $plan->scenes[0]->style_prompt = 'ultra realistic cinematic 8k'; // §1

        $this->assertPlanRejected($plan, 'trường lạ mang prompt language lọt vào scene');
    }

    public function test_rejects_legacy_content_type(): void
    {
        $plan = $this->fixture();
        unset($plan->scenes[0]->motion_intent);
        $plan->scenes[0]->content_type = 'visual'; // §1 — content_type đã bị xoá

        $this->assertPlanRejected($plan, 'content_type của kiến trúc cũ');
    }

    public function test_rejects_act_with_ref_mismatching_its_source(): void
    {
        $plan = $this->fixture();
        $plan->acts[0]->source = 'EVENT'; // act[0] mang entity_ref

        $this->assertPlanRejected($plan, 'act.source=EVENT nhưng lại mang entity_ref');
    }

    public function test_rejects_act_with_multiple_refs(): void
    {
        $plan = $this->fixture();
        $plan->acts[0]->event_ref = 'e1'; // đã có entity_ref

        $this->assertPlanRejected($plan, 'act mang nhiều hơn một ref');
    }

    public function test_rejects_free_text_camera_movement(): void
    {
        $plan = $this->fixture();
        $plan->scenes[0]->camera->movement = '24mm drone orbit'; // §1 — camera là intent, không phải thấu kính

        $this->assertPlanRejected($plan, 'camera.movement dạng text tự do thay vì enum');
    }

    public function test_rejects_wrong_plan_version(): void
    {
        $plan = $this->fixture();
        $plan->plan_version = '2.0';

        $this->assertPlanRejected($plan, 'plan_version sai');
    }

    public function test_identity_requires_visual_referent_decision(): void
    {
        $plan = $this->fixture();
        unset($plan->world->entities[0]->identity->visual_referent);

        $this->assertPlanRejected($plan, 'identity thiếu visual_referent — Laravel phải phán đoán §4');
    }
}
