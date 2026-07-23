<?php

namespace Tests\Video\Extraction;

use App\Video\Evidence\EvidenceIndex;
use App\Video\Evidence\EvidenceSource;
use App\Video\Extraction\CandidateClaim;
use App\Video\Extraction\CandidateEntity;
use App\Video\Extraction\CandidateWorldGraph;
use App\Video\Extraction\SemanticClaimPrecisionAnalyzer;
use PHPUnit\Framework\TestCase;

class SemanticClaimPrecisionAnalyzerTest extends TestCase
{
    private function index(): EvidenceIndex
    {
        return (new EvidenceIndex())
            ->add(EvidenceSource::Body, 'The 100m yacht was built by Feadship at their Kaag yard.');
    }

    private function graphWith(CandidateClaim ...$semanticClaims): CandidateWorldGraph
    {
        return new CandidateWorldGraph([
            new CandidateEntity('moonrise', 'vehicle', [], semanticClaims: $semanticClaims),
        ]);
    }

    public function test_verified_claim_counts_toward_precision(): void
    {
        $claim = new CandidateClaim('moonrise', 'builder', 'Feadship', 'built by Feadship');

        $report = (new SemanticClaimPrecisionAnalyzer())->analyze($this->graphWith($claim), $this->index());

        $this->assertSame(1, $report->total);
        $this->assertSame(1, $report->verified);
        $this->assertSame(1.0, $report->precision());
        $this->assertSame([], $report->failures);
    }

    public function test_quote_not_found_is_a_failure(): void
    {
        $claim = new CandidateClaim('moonrise', 'builder', 'Feadship', 'constructed by the Feadship shipyard');

        $report = (new SemanticClaimPrecisionAnalyzer())->analyze($this->graphWith($claim), $this->index());

        $this->assertSame(0, $report->verified);
        $this->assertSame('quote_not_found', $report->failures[0]['reason']);
        $this->assertSame('Feadship', $report->failures[0]['value'], 'value phải có mặt trong failure để chẩn đoán được');
    }

    public function test_missing_evidence_quote_is_a_failure(): void
    {
        $claim = new CandidateClaim('moonrise', 'builder', 'Feadship', '');

        $report = (new SemanticClaimPrecisionAnalyzer())->analyze($this->graphWith($claim), $this->index());

        $this->assertSame('no_evidence', $report->failures[0]['reason']);
    }

    public function test_value_not_supported_by_quote_is_a_failure(): void
    {
        // Quote thật, nhưng value KHÔNG khớp nội dung quote (LLM gắn nhầm).
        $claim = new CandidateClaim('moonrise', 'builder', 'Lürssen', 'built by Feadship');

        $report = (new SemanticClaimPrecisionAnalyzer())->analyze($this->graphWith($claim), $this->index());

        $this->assertSame('value_not_supported', $report->failures[0]['reason']);
    }

    public function test_empty_semantic_claims_yields_zero_precision_not_error(): void
    {
        $report = (new SemanticClaimPrecisionAnalyzer())->analyze($this->graphWith(), $this->index());

        $this->assertSame(0, $report->total);
        $this->assertSame(0.0, $report->precision());
    }

    public function test_does_not_touch_regular_claims(): void
    {
        $regular = new CandidateClaim('moonrise', 'hull_color', 'grey', 'grey hull');
        $graph = new CandidateWorldGraph([
            new CandidateEntity('moonrise', 'vehicle', [$regular]), // semanticClaims mặc định rỗng
        ]);

        $report = (new SemanticClaimPrecisionAnalyzer())->analyze($graph, $this->index());

        $this->assertSame(0, $report->total, 'claims thường KHÔNG được tính vào precision semantic');
    }

    // ---- B1.1 (2026-07-22): confidence_reason tự khai, chỉ observability ----

    public function test_reason_distribution_tallies_self_reported_labels(): void
    {
        $verbatim = new CandidateClaim('moonrise', 'builder', 'Feadship', 'built by Feadship', confidenceReason: 'verbatim');
        $inferred = new CandidateClaim('moonrise', 'owner', 'Someone', 'built by Feadship', confidenceReason: 'inferred');

        $report = (new SemanticClaimPrecisionAnalyzer())->analyze($this->graphWith($verbatim, $inferred), $this->index());

        $this->assertSame(['verbatim' => 1, 'inferred' => 1], $report->reasonDistribution);
    }

    public function test_missing_confidence_reason_is_grouped_as_not_given(): void
    {
        $claim = new CandidateClaim('moonrise', 'builder', 'Feadship', 'built by Feadship');

        $report = (new SemanticClaimPrecisionAnalyzer())->analyze($this->graphWith($claim), $this->index());

        $this->assertSame(['(not given)' => 1], $report->reasonDistribution);
    }

    public function test_failure_carries_confidence_reason_for_cross_reference(): void
    {
        $claim = new CandidateClaim('moonrise', 'builder', 'Feadship', 'constructed by the Feadship shipyard', confidenceReason: 'verbatim');

        $report = (new SemanticClaimPrecisionAnalyzer())->analyze($this->graphWith($claim), $this->index());

        $this->assertSame('verbatim', $report->failures[0]['confidence_reason']);
    }
}
