<?php

namespace App\Video\Editorial;

use App\Video\Scene\ScenePurpose;
use App\Video\World\VerifiedWorldGraph;

/**
 * Editorial 5a: điền `aesthetic{}` cho mỗi scene.
 *
 * Đây là LẦN ĐẦU taste được phép xuất hiện trong pipeline. Nhưng nó vẫn phải
 * mù chủ đề: `aestheticFor()` chỉ nhận ScenePurpose — không thấy EntityType,
 * subjects, hay Evidence. Ranh giới đóng bằng type, như IntentPlanner. Taste
 * KHÔNG THỂ dính chủ đề vì chữ ký không cho.
 *
 * Bảng dưới đây là EDITORIAL POLICY thuần: cinematic grammar, không AI, không
 * heuristic, không `if yacht`, không `if sunset`. "Establishing shot thì
 * calm/balanced" đúng cho mọi chủ đề.
 *
 * CHƯA làm ở 5a: prohibitions/continuity. Đó là trách nhiệm KHÁC của Editorial,
 * được phép thấy World Graph (áp policy world-knowledge dưới dạng data, §12) —
 * để bước sau, không trộn với aesthetic mù-chủ-đề này.
 *
 * Khi nào cần thêm tín hiệu (actOrdinal, vị trí trong story) thì mới đưa vào —
 * và chỉ khi có bằng chứng ScenePurpose không còn đủ. Hiện chưa (Rule 0).
 */
final class EditorialInterpreter
{
    /**
     * @param list<EditorialPolicy> $policies §12 Rule #1: du lieu, tiem qua
     *        constructor — Interpreter khong hardcode Feadship/Ferrari/Moonrise.
     */
    public function __construct(
        private readonly array $policies = [],
    ) {
    }

    public function aestheticFor(ScenePurpose $purpose): SceneAesthetic
    {
        return match ($purpose) {
            ScenePurpose::Establish => new SceneAesthetic(
                Emotion::Calm, Composition::Centered, LightIntensity::Soft, LightGrade::Neutral,
            ),
            ScenePurpose::Reveal => new SceneAesthetic(
                Emotion::Calm, Composition::RuleOfThirds, LightIntensity::Soft, LightGrade::Warm,
            ),
            ScenePurpose::Detail => new SceneAesthetic(
                Emotion::Calm, Composition::Centered, LightIntensity::Soft, LightGrade::Neutral,
            ),
            ScenePurpose::Action => new SceneAesthetic(
                Emotion::Tense, Composition::RuleOfThirds, LightIntensity::Harsh, LightGrade::Cool,
            ),
            ScenePurpose::Process => new SceneAesthetic(
                Emotion::Dramatic, Composition::LeadingLines, LightIntensity::Neutral, LightGrade::Neutral,
            ),
            ScenePurpose::Comparison => new SceneAesthetic(
                Emotion::Calm, Composition::Symmetrical, LightIntensity::Neutral, LightGrade::Neutral,
            ),
            ScenePurpose::Resolution => new SceneAesthetic(
                Emotion::Majestic, Composition::Centered, LightIntensity::Soft, LightGrade::Golden,
            ),
        };
    }

    /**
     * §12 Rule #3: read-only over VerifiedWorldGraph — chi sinh prohibitions,
     * KHONG BAO GIO sua entity.type/attributes/builder. §12 Rule #2: generic —
     * ham nay khong biet Feadship/Ferrari/Moonrise ton tai, chi khop $policies.
     *
     * @return list<array{entity_id: string, attribute: string, value: mixed, reason: string}>
     */
    public function prohibitionsFor(VerifiedWorldGraph $world): array
    {
        $prohibitions = [];

        foreach ($world->entities() as $entity) {
            foreach ($this->policies as $policy) {
                if (! $this->matches($entity, $policy->match)) {
                    continue;
                }

                $prohibitions[] = [
                    'entity_id' => $entity->id,
                    'attribute' => $policy->prohibitAttribute,
                    'value'     => $policy->prohibitValue,
                    'reason'    => $policy->reason,
                ];
            }
        }

        return $prohibitions;
    }

    /**
     * @param array<string, mixed> $match
     */
    private function matches(\App\Video\World\Entity $entity, array $match): bool
    {
        foreach ($match as $attribute => $expected) {
            if ($entity->value($attribute) !== $expected) {
                return false;
            }
        }

        return true;
    }
}
