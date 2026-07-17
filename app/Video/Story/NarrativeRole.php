<?php

namespace App\Video\Story;

/**
 * Vai trò tự sự của một act. Ontology-level, KHÔNG phải domain label.
 *
 * `Luxury`, `Performance`, `Ownership` KHÔNG BAO GIỜ xuất hiện ở đây — chúng là
 * cách con người nhóm thuộc tính lại, tức domain knowledge. Việc gọi một act là
 * "Luxury" khi trình bày là chuyện của Editorial (§12).
 *
 * Vì sao trường này tồn tại: thiếu nó, Story Planner chỉ còn là
 * `sort(graph, centrality)`. Centrality trả lời "cái gì quan trọng"; nó không
 * trả lời "vì sao người xem nên quan tâm".
 *
 * CHỈ CÓ BA CASE. `COMPARE`, `CLIMAX`, `TRANSITION` cố tình vắng mặt: hiện chưa
 * có tín hiệu CẤU TRÚC nào trong graph sinh ra được chúng.
 *   - COMPARE cần biết hai entity là phương án thay thế của nhau. Quan hệ duy
 *     nhất nói lên điều đó (`successor_of`) đang bị Extractor bỏ sót, và
 *     "cùng type" không đủ: `nebula --support_vessel_for--> moonrise_2020` cũng
 *     là vehicle→vehicle mà chẳng so sánh gì.
 *   - CLIMAX/TRANSITION chưa có tín hiệu nào cả.
 * Thêm case không ai sinh ra được = enum chết. Xem Rule 0.
 */
enum NarrativeRole: string
{
    /** Act đầu tiên — phải giới thiệu chủ thể trước khi nói bất cứ điều gì về nó. */
    case Introduce = 'INTRODUCE';

    /** Mặc định. */
    case Explain = 'EXPLAIN';

    /** Act cuối cùng. */
    case Resolve = 'RESOLVE';
}
