<?php

namespace App\Services\Video;

use App\Models\Article;
use App\Models\VideoExtractionArtifact;
use App\Video\Extraction\CandidateEntity;
use App\Video\Extraction\CandidateEvent;
use App\Video\Extraction\CandidateRelation;
use App\Video\Extraction\CandidateWorldGraph;
use App\Video\Extraction\ExtractionResult;
use App\Video\Gatekeeper\GatekeeperReport;

/**
 * Ghi lại bằng chứng của MỘT lần Truth Layer chạy — và không bao giờ làm hỏng
 * lần chạy đó.
 *
 * VỊ TRÍ TRONG KIẾN TRÚC: đây là tầng Laravel, được phép biết Eloquent. Nó nhận
 * DTO thuần từ `app/Video/` và tự ánh xạ sang mảng. Chiều phụ thuộc chỉ đi một
 * hướng — Truth Layer KHÔNG biết class này tồn tại, không biết có DB, và sẽ
 * chạy y hệt nếu ta xoá cả bảng đi.
 *
 * VÌ SAO ÁNH XẠ Ở ĐÂY chứ không thêm `toArray()` vào các DTO: định dạng lưu trữ
 * là nhu cầu của tầng lưu trữ. Thêm method phục vụ persistence vào
 * `CandidateEntity` là kéo mối bận tâm của DB ngược lên Truth Layer, đúng cái
 * ranh giới mà cả kiến trúc dựng lên để giữ.
 *
 * KHÔNG CÓ STATE. Hai móc của pipeline bắn ở hai thời điểm khác nhau
 * (`onExtracted` trước, `onWorldVerified` sau), nên VIỆC GHÉP thuộc về chỗ gọi —
 * ở đó nó là biến cục bộ của một lượt chạy. Recorder giữ state thì hai request
 * song song sẽ trộn bằng chứng của nhau, và đó là loại bug tệ nhất trong một hệ
 * thống pháp y: bằng chứng sai còn nguy hiểm hơn không có bằng chứng.
 */
class ExtractionArtifactRecorder
{
    // KHÔNG `final` — khác lệ chung của repo, và đây là lý do: bộ test Video
    // chạy KHÔNG DB (`phpunit.xml` để sqlite in-memory ở dạng comment). Bốn ca
    // phải kiểm — ghi khi Gatekeeper loại sạch, ghi khi Planning nổ, ghi hỏng
    // mà pipeline vẫn chạy — đều hỏi "recorder CÓ ĐƯỢC GỌI ĐÚNG LÚC KHÔNG",
    // không hỏi "MySQL có một hàng không". Thay thế được là điều kiện để hỏi
    // câu đó mà không kéo cả CSDL vào một bộ test đang chạy trong 1 giây.
    //
    // Đây là lựa chọn RẺ HƠN một interface: một từ khoá, không thêm type nào.
    // Có sink thứ hai thật (S3, hệ đo lường) thì lúc đó mới nâng lên interface.

    /**
     * Ghi một hàng. NÉM khi hỏng — và đó là chủ đích.
     *
     * Bảo đảm "dụng cụ đo không bao giờ làm sập thứ nó đo" nằm ở CHỖ GỌI
     * (`VideoRenderPlanService`), không nằm ở đây. Đặt `try/catch` bên trong
     * hàm này thì bảo đảm ấy chỉ đúng với BẢN CÀI ĐẶT NÀY — ai viết recorder
     * khác và quên bọc là pipeline chết, mà lỗi đó sẽ không lộ ra cho tới lúc
     * production đang chạy.
     *
     * Ở đây thì ngược lại: hàm trung thực về việc nó hỏng, còn người gọi quyết
     * định hỏng đó có được phép lan hay không. Và người gọi đã quyết: không.
     */
    public function record(
        Article $article,
        ExtractionResult $extraction,
        GatekeeperReport $report,
        ?string $sessionId = null,
    ): void {
        VideoExtractionArtifact::create($this->payloadFor($article, $extraction, $report, $sessionId));
    }

    /**
     * Tách khỏi `record()` để KIỂM ĐƯỢC PHÉP ÁNH XẠ mà không cần CSDL — bộ test
     * Video chạy không DB, và phép ánh xạ mới là phần dễ sai: tên trường, thứ
     * tự tầng bằng chứng, `null` đúng chỗ.
     *
     * @return array<string, mixed>
     */
    public function payloadFor(
        Article $article,
        ExtractionResult $extraction,
        GatekeeperReport $report,
        ?string $sessionId = null,
    ): array {
        return [
            'article_id' => $article->id,
            'session_id' => $sessionId,
            'category' => $article->category_id ? (string) $article->category_id : null,

            // Giá trị ĐÃ DÙNG THẬT: `$extraction->model` là model API trả về,
            // không phải khoá ta gửi đi. Bài học từ bug ClaudeProducer khai
            // 'haiku' mà chạy Sonnet suốt sáu ngày.
            'model' => $extraction->model,
            'instruction_version' => $extraction->instructionVersion,
            'profile_version' => null,   // Sprint 1 mới có profile để mà ghi

            'tokens_in' => $extraction->tokensIn,
            'tokens_out' => $extraction->tokensOut,
            'latency_ms' => $extraction->latencyMs,
            'cost_usd' => $extraction->costUsd,

            'raw' => $extraction->raw,
            'candidate_graph' => $this->graphToArray($extraction->candidates),
            'gatekeeper_report' => $this->reportToArray($report),
            'diagnostics' => $extraction->diagnostics?->toArray(),
        ];
    }

    /**
     * Graph SAU parser, TRƯỚC Gatekeeper.
     *
     * Lưu bản đã verify thì mất đúng phần cần để phân biệt "LLM không tìm ra"
     * với "cổng đã loại" — mà đó chính là câu hỏi cả bảng này sinh ra để trả lời.
     *
     * @return array<string, mixed>
     */
    private function graphToArray(CandidateWorldGraph $graph): array
    {
        return [
            'entities' => array_map(fn (CandidateEntity $e) => [
                'id' => $e->id,
                'type' => $e->type,
                'name' => $e->name,
                'name_quote' => $e->nameQuote,
                'confidence' => $e->confidence,
                'claims' => $this->claimsToArray($e->claims),
                'semantic_claims' => $this->claimsToArray($e->semanticClaims),
            ], $graph->entities),

            'relations' => array_map(fn (CandidateRelation $r) => [
                'id' => $r->id,
                'from' => $r->from,
                'to' => $r->to,
                'type' => $r->type,
                'evidence_quote' => $r->evidenceQuote,
                'confidence' => $r->confidence,
            ], $graph->relations),

            'events' => array_map(fn (CandidateEvent $ev) => [
                'id' => $ev->id,
                'type' => $ev->type,
                'entity_id' => $ev->entityId,
                'evidence_quote' => $ev->evidenceQuote,
                'confidence' => $ev->confidence,
            ], $graph->events),
        ];
    }

    /**
     * @param  list<\App\Video\Extraction\CandidateClaim>  $claims
     * @return list<array<string, mixed>>
     */
    private function claimsToArray(array $claims): array
    {
        return array_map(fn ($c) => [
            'attribute' => $c->attribute,
            'value' => $c->value,
            'evidence_quote' => $c->evidenceQuote,
            'confidence' => $c->confidence,
            'confidence_reason' => $c->confidenceReason,
        ], $claims);
    }

    /**
     * Giữ TỪNG lý do loại, không chỉ con số tổng.
     *
     * "Loại 30 claim" không sửa được gì; "loại vì quote không chứng minh được
     * giá trị, ở `moonrise.hull_color`" thì sửa được.
     *
     * @return array<string, mixed>
     */
    private function reportToArray(GatekeeperReport $report): array
    {
        return [
            'candidate_count' => $report->candidateCount,
            'rejected_count' => $report->rejectedCount(),
            'survival_rate' => $report->survivalRate(),
            'verified_entity_count' => count($report->graph->entities()),
            'rejections' => array_map(fn ($r) => [
                'subject' => $r->subject,
                'reason' => $r->reason->value,
                'detail' => $r->detail,
            ], $report->rejections),
        ];
    }
}
