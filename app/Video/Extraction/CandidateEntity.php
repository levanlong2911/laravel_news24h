<?php

namespace App\Video\Extraction;

/** Giả thuyết về một entity. Kiểu này KHÁC App\Video\World\Entity có chủ ý. */
final class CandidateEntity
{
    /**
     * @param list<CandidateClaim> $claims Fact RENDER được (length/color/weather...)
     *        — verify xong chảy vào Entity::attributes, CÓ xuống ProviderIR/Python.
     * @param list<CandidateClaim> $semanticClaims Fact DANH TÍNH/NGUỒN GỐC
     *        (owner/builder/brand/breeder...) — CÙNG cơ chế evidence-gated như
     *        $claims (Gatekeeper vẫn verify, không đặc cách), nhưng verify xong
     *        sẽ chảy vào Identity::semantic — KHÔNG BAO GIỜ xuống ProviderIR
     *        (§4, "Moonrise Identity Trap": builder=Feadship không được rò vào
     *        render prompt). B0 (2026-07-22, xem project_benchmark_pilot10_findings
     *        memory) — chỉ CONTRACT, CHƯA nơi nào đọc field này (Gatekeeper chưa
     *        verify, ClaudeExtractor chưa sinh) — zero regression có chủ ý.
     *        B1: Extractor sinh + benchmark precision (chưa nối RenderPlan).
     *        B2: nếu precision đủ tốt mới nối Gatekeeper -> Identity::semantic.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly array $claims = [],
        public readonly ?string $name = null,
        public readonly string $nameQuote = '',
        public readonly float $confidence = 0.0,
        public readonly array $semanticClaims = [],
    ) {
    }
}
