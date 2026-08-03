<?php

namespace App\Video\Pipeline;

/**
 * Pipeline DỪNG SỚM CÓ CHỦ ĐÍCH vì không đủ dữ liệu để đi tiếp — KHÔNG phải
 * crash, KHÔNG phải lỗi hệ thống.
 *
 * Lý do tồn tại là TIỀN. `VideoPlanningPipeline::plan()` gọi Claude 11+ lần
 * (1 Extractor + 1 Producer + N Director). Nếu đầu vào rỗng hoặc không sự thật
 * nào qua nổi Gatekeeper thì mọi cú gọi phía sau đều **trả tiền để nhận rác**:
 * bằng chứng thật 2026-07-30 — một cú Extractor bị `dd()` chặn ngay sau đó vẫn
 * bị tính $0.0179; tiền đã tiêu, dữ liệu vứt đi.
 *
 * Nên mỗi chốt chặn phải đứng NGAY TRƯỚC cú gọi tốn tiền kế tiếp, không phải ở
 * cuối luồng. Xem các GUARD trong `VideoPlanningPipeline::plan()`.
 *
 * `$stage` là thứ trả lời "hỏng ở đâu" mà không phải đọc stack trace —
 * `$context` là số liệu để người vận hành quyết định làm gì tiếp.
 */
final class PipelineAborted extends \RuntimeException
{
    public const STAGE_NORMALIZE = 'normalize';

    public const STAGE_GATEKEEPER = 'gatekeeper';

    public const STAGE_SCENE_PLANNING = 'scene_planning';

    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        public readonly string $stage,
        string $message,
        public readonly array $context = [],
        public readonly bool $spentMoney = true,
    ) {
        parent::__construct($message);
    }

    /**
     * TRƯỚC cú gọi AI đầu tiên — đây là chốt DUY NHẤT chặn được mà chưa tốn đồng nào.
     *
     * `EvidenceIndex` rỗng nghĩa là `ArticleNormalizer` không rút được đoạn chữ
     * nào: `content` rỗng/null, hoặc HTML chỉ có thẻ không có text. Bài viết ở
     * trạng thái `pending` chưa chạy pipeline AI thường rơi vào đây.
     */
    public static function articleHasNoText(string $articleId, int $contentLength): self
    {
        return new self(
            self::STAGE_NORMALIZE,
            'Bài viết không có đoạn văn bản nào dùng được, nên đã DỪNG TRƯỚC khi gọi AI — chưa tốn phí. '
                .'Kiểm tra nội dung bài viết (content) rồi bấm lại.',
            ['article_id' => $articleId, 'content_length' => $contentLength],
            spentMoney: false,
        );
    }

    /**
     * SAU Extractor (đã tốn 1 cú gọi) nhưng TRƯỚC Producer + N Director.
     *
     * Không entity nào qua Gatekeeper ⇒ không có gì để kể. Đi tiếp là trả tiền
     * cho Producer và Director để dựng kế hoạch quanh một thế giới rỗng.
     */
    public static function nothingSurvivedVerification(int $candidateCount, int $rejectedCount): self
    {
        return new self(
            self::STAGE_GATEKEEPER,
            sprintf(
                'Không sự thật nào qua được kiểm chứng (%d/%d ứng viên bị loại vì không tìm thấy trích dẫn trong bài). '
                    .'Đã DỪNG trước khi gọi tiếp AI để không tốn thêm phí. '
                    .'Thường do bài viết quá ngắn hoặc chỉ có thông tin không mô tả được bằng hình.',
                $rejectedCount,
                $candidateCount,
            ),
            ['candidate_count' => $candidateCount, 'rejected_count' => $rejectedCount],
        );
    }

    /**
     * SAU Gatekeeper, vẫn TRƯỚC Producer + N Director.
     *
     * Có entity nhưng không dựng nổi scene nào (vd mọi entity đều anchor-only,
     * không thuộc tính nào để quay). Cùng lý do tiền như trên.
     */
    public static function noSceneCouldBePlanned(int $entityCount, int $actCount): self
    {
        return new self(
            self::STAGE_SCENE_PLANNING,
            sprintf(
                'Có %d thực thể nhưng không dựng được cảnh quay nào (%d act). '
                    .'Đã DỪNG trước khi gọi tiếp AI. Thường do bài chỉ nêu tên mà không mô tả gì để quay.',
                $entityCount,
                $actCount,
            ),
            ['entity_count' => $entityCount, 'act_count' => $actCount],
        );
    }

    /** Dòng gọn cho log/UI: biết ngay hỏng ở chặng nào và đã tốn tiền chưa. */
    public function describe(): string
    {
        return sprintf(
            '[%s%s] %s',
            $this->stage,
            $this->spentMoney ? '' : ', chưa tốn phí',
            $this->getMessage(),
        );
    }
}
