<?php

namespace App\Video\Extraction;

use App\Video\Evidence\EvidenceIndex;
use App\Video\Evidence\Value\ValueVerifier;

/**
 * B1 (2026-07-22, xem project_benchmark_pilot10_findings memory): đo precision
 * của `CandidateEntity::$semanticClaims` — CHỈ ĐỂ QUAN SÁT, không ghi bất cứ
 * gì vào VerifiedWorldGraph/Identity. KHÔNG đụng EvidenceGatekeeper — dùng lại
 * ĐÚNG 2 bước verify Gatekeeper đang dùng (EvidenceIndex::find() + ValueVerifier
 * ::verify(), cả 2 đã public), nhưng đây là bản sao READ-ONLY cho mục đích đo,
 * không phải đường dẫn production. Nếu B2 được duyệt, verify thật sẽ nằm ở
 * Gatekeeper (một nguồn sự thật duy nhất) — class này khi đó có thể bỏ hoặc
 * giữ làm công cụ benchmark độc lập.
 */
final class SemanticClaimPrecisionAnalyzer
{
    public function __construct(
        private readonly ValueVerifier $values = new ValueVerifier(),
    ) {
    }

    public function analyze(CandidateWorldGraph $candidates, EvidenceIndex $index): SemanticClaimReport
    {
        $total = 0;
        $verified = 0;
        $failures = [];
        $reasonDistribution = [];

        foreach ($candidates->entities as $entity) {
            foreach ($entity->semanticClaims as $claim) {
                $total++;

                $selfReported = $claim->confidenceReason !== '' ? $claim->confidenceReason : '(not given)';
                $reasonDistribution[$selfReported] = ($reasonDistribution[$selfReported] ?? 0) + 1;

                $reason = $this->checkClaim($claim, $index);
                if ($reason === null) {
                    $verified++;
                    continue;
                }

                $failures[] = [
                    'entity_id' => $entity->id,
                    'attribute' => $claim->attribute,
                    'value' => $claim->value,
                    'evidence_quote' => $claim->evidenceQuote,
                    'reason' => $reason,
                    'confidence_reason' => $claim->confidenceReason,
                ];
            }
        }

        return new SemanticClaimReport($total, $verified, $failures, $reasonDistribution);
    }

    private function checkClaim(CandidateClaim $claim, EvidenceIndex $index): ?string
    {
        if (trim($claim->evidenceQuote) === '') {
            return 'no_evidence';
        }

        if ($index->find($claim->evidenceQuote) === null) {
            return 'quote_not_found';
        }

        if ($this->values->verify($claim->evidenceQuote, $claim->value) === null) {
            return 'value_not_supported';
        }

        return null;
    }
}
