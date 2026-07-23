<?php

namespace App\Video\Pipeline;

use App\Video\Director\ClaudeDirector;
use App\Video\Editorial\EditorialInterpreter;
use App\Video\Editorial\EditorialPolicy;
use App\Video\Extraction\ClaudeExtractor;
use App\Video\Llm\LlmClient;
use App\Video\Producer\ClaudeProducer;
use App\Video\RenderPlan\RenderPlanAssembler;

/**
 * Dựng VideoPlanningPipeline THẬT (Claude*) với EditorialPolicy production —
 * đúng MỘT chỗ, dùng chung bởi VideoSessionService (nút 🎬) VÀ video:benchmark
 * (--extractor=claude). Lý do tách class riêng (không phải tiện tay):
 *
 * VideoPlanningPipeline có 2 EditorialInterpreter ĐỘC LẬP nếu không cẩn thận
 * — một cái riêng của Pipeline (candidatesFor()/microPhysicsFor()), một cái
 * MẶC ĐỊNH bên trong RenderPlanAssembler (prohibitionsFor()/environmentFor()/
 * aestheticFor()). Nếu 2 nơi gọi tự dựng Pipeline lại quên truyền CÙNG một
 * EditorialInterpreter($policies) vào cả hai, policy thật sẽ chỉ có hiệu lực
 * ở candidatesFor() còn continuity.prohibitions vẫn rỗng — bug âm thầm, không
 * lỗi rõ ràng. Factory này đảm bảo đúng 1 lần, test được.
 */
final class VideoPipelineFactory
{
    public static function claude(LlmClient $llm, array $policies = []): VideoPlanningPipeline
    {
        $editorial = new EditorialInterpreter($policies);

        return new VideoPlanningPipeline(
            new ClaudeExtractor($llm),
            new ClaudeProducer($llm),
            new ClaudeDirector($llm),
            editorial: $editorial,
            assembler: new RenderPlanAssembler($editorial),
        );
    }

    /**
     * Đọc `config('video.editorial_policies')` (data, §12 Rule #1) -> object.
     * Config rỗng -> mảng rỗng, hành vi y hệt trước khi có policy thật.
     *
     * @return list<EditorialPolicy>
     */
    public static function productionPolicies(): array
    {
        return array_map(
            fn (array $p) => new EditorialPolicy(
                $p['match'],
                $p['prohibit_attribute'],
                $p['prohibit_value'],
                $p['reason'],
            ),
            config('video.editorial_policies', []),
        );
    }
}
