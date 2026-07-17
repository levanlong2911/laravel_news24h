<?php

namespace App\Video\Extraction;

use App\Video\Article\RawArticle;
use App\Video\Evidence\EvidenceIndex;

/**
 * Phát lại phản hồi Claude đã ghi. Không token, không mạng, không chờ.
 *
 * Đây là thứ đáng đầu tư nhất trong cả tầng extraction: nó biến một cú gọi AI
 * bất định thành một regression test cố định. Ghi một lần bằng ClaudeExtractor
 * (có duyệt), từ đó CI chạy mãi trên **đúng JSON thô mà Claude đã trả về** —
 * không phải trên một bản Fake do tôi tự nghĩ ra và vô tình viết cho dễ pass.
 *
 * Khác biệt với FakeExtractor rất quan trọng:
 *   Fake     — tôi bịa ra, dùng cho unit test tình huống cụ thể
 *   Recorded — Claude thật đã nói, dùng cho regression
 * Fake sẽ không bao giờ bắt được một hallucination mà tôi không nghĩ tới.
 * Recorded thì có.
 */
final class RecordedExtractor implements Extractor
{
    public function __construct(
        private readonly string $recordingDir,
        private readonly CandidateGraphParser $parser = new CandidateGraphParser(),
    ) {
    }

    public function extract(RawArticle $article, EvidenceIndex $index): ExtractionResult
    {
        $path = $this->pathFor($article->id);

        if (! is_file($path)) {
            throw new RecordingMissing(
                "Chưa có bản ghi cho article '{$article->id}' tại {$path}. "
                . 'Chạy ClaudeExtractor một lần (cần duyệt) để ghi lại.',
            );
        }

        $recording = json_decode(file_get_contents($path), true);

        if (! is_array($recording) || ! isset($recording['raw'])) {
            throw new MalformedExtraction("Bản ghi hỏng: {$path}");
        }

        return new ExtractionResult(
            $this->parser->parse($recording['raw']),
            $recording['model'] ?? 'recorded',
            $recording['instruction_version'] ?? 'unknown',
            (int) ($recording['tokens_in'] ?? 0),
            (int) ($recording['tokens_out'] ?? 0),
            0,     // latency của lần ghi không nói lên gì về lần phát lại
            0.0,   // phát lại không tốn tiền — báo cáo $0 mới là sự thật
            $recording['raw'],
        );
    }

    public function record(RawArticle $article, ExtractionResult $result): void
    {
        if (! is_dir($this->recordingDir)) {
            mkdir($this->recordingDir, 0o755, true);
        }

        file_put_contents($this->pathFor($article->id), json_encode([
            'article_id'          => $article->id,
            'model'               => $result->model,
            'instruction_version' => $result->instructionVersion,
            'tokens_in'           => $result->tokensIn,
            'tokens_out'          => $result->tokensOut,
            'cost_usd'            => $result->costUsd,
            'raw'                 => $result->raw,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function has(string $articleId): bool
    {
        return is_file($this->pathFor($articleId));
    }

    private function pathFor(string $articleId): string
    {
        return $this->recordingDir . '/' . preg_replace('/[^a-z0-9_-]/i', '_', $articleId) . '.json';
    }
}
