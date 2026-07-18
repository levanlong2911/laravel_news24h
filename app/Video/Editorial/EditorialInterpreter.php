<?php

namespace App\Video\Editorial;

use App\Video\Scene\ScenePurpose;

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
}
