<?php

namespace App\Video\Extraction;

use App\Video\Article\RawArticle;
use App\Video\Evidence\EvidenceIndex;

/**
 * Hypothesis Generator. KHÔNG phải Fact Extractor — cái tên đó là một lời nói dối.
 *
 * Nhiệm vụ duy nhất: **đưa ra giả thuyết**. Nó không quyết định cái gì là sự
 * thật; EvidenceGatekeeper mới có quyền đó.
 *
 * BẤT BIẾN — Extractor không được biết schema cuối:
 *   sinh   → CandidateWorldGraph
 *   KHÔNG  → VerifiedWorldGraph, RenderPlan, Scene, Act
 * Nó không được giúp Planner. Không được giúp Renderer. Không được tối ưu riêng
 * cho Moonrise. Chỉ một việc.
 *
 * Nhận EvidenceIndex chứ không phải HTML thô là CÓ CHỦ Ý: LLM phải trích dẫn từ
 * ĐÚNG văn bản mà Gatekeeper sẽ đi tìm. Cho nó xem HTML thô thì quote trả về sẽ
 * không khớp index, và mọi claim đều bị loại oan.
 */
interface Extractor
{
    public function extract(RawArticle $article, EvidenceIndex $index): ExtractionResult;
}
