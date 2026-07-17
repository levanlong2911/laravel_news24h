<?php

namespace App\Video\Article;

use App\Video\Evidence\EvidenceIndex;
use App\Video\Evidence\EvidenceSource;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * HTML bài báo → EvidenceIndex.
 *
 * Deterministic. Không gọi AI. Nhiệm vụ duy nhất: bóc chữ ra khỏi markup mà
 * KHÔNG đánh mất nguồn gốc của nó — thông số con tàu nằm ở bảng, tên nằm ở
 * tiêu đề, ngày tháng nằm ở metadata. Đổ tất cả thành một khối text sẽ khiến
 * Gatekeeper vẫn chạy đúng nhưng không còn nói được bằng chứng đến từ đâu.
 *
 * Cố tình KHÔNG lọc bỏ gì theo ngữ nghĩa: một câu trông như quảng cáo vẫn có
 * thể chứa sự thật duy nhất về chiều dài con tàu. Lọc là việc của Gatekeeper,
 * và Gatekeeper lọc bằng bằng chứng chứ không bằng cảm tính.
 */
final class ArticleNormalizer
{
    /** Thẻ không bao giờ mang nội dung bài. */
    private const STRIP_TAGS = ['script', 'style', 'noscript', 'iframe', 'svg', 'form', 'nav'];

    public function normalize(RawArticle $article): EvidenceIndex
    {
        $index = new EvidenceIndex();

        if (trim($article->title) !== '') {
            $index->add(EvidenceSource::Headline, $article->title);
        }

        foreach ($article->metadata as $key => $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                // Giữ cả tên trường: "published: 2025-03-14" cho phép LLM trích
                // được cụm có nghĩa thay vì một con số trần trụi.
                $index->add(EvidenceSource::Metadata, sprintf('%s: %s', $key, $value));
            }
        }

        if (trim($article->html) === '') {
            return $index;
        }

        $dom = $this->parse($article->html);
        $xpath = new DOMXPath($dom);

        $this->addCaptions($index, $xpath);
        $this->addTables($index, $xpath);
        $this->addBody($index, $xpath);

        return $index;
    }

    private function parse(string $html): DOMDocument
    {
        $dom = new DOMDocument();

        $previous = libxml_use_internal_errors(true);
        // HTML bài báo thật gần như luôn sai chuẩn. Nuốt warning là có chủ ý —
        // ta cần chữ, không cần markup hợp lệ.
        $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);

        foreach (self::STRIP_TAGS as $tag) {
            foreach (iterator_to_array($xpath->query("//{$tag}") ?: []) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        return $dom;
    }

    private function addCaptions(EvidenceIndex $index, DOMXPath $xpath): void
    {
        foreach ($xpath->query('//figcaption | //caption') ?: [] as $node) {
            $index->add(EvidenceSource::Caption, $this->textOf($node));
            $this->detach($node);
        }

        foreach ($xpath->query('//img[@alt]') ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $index->add(EvidenceSource::Caption, $node->getAttribute('alt'));
            }
        }
    }

    /**
     * Mỗi hàng bảng thành một segment riêng, giữ nguyên cặp nhãn–giá trị.
     *
     * Bảng thông số là nơi sống của những sự thật đáng render nhất ("Top speed:
     * 19.5 knots"). Nếu bóc thành chữ chạy dài thì "19.5" mất đi cái nhãn của
     * nó và không ai còn biết 19.5 là của cái gì.
     */
    private function addTables(EvidenceIndex $index, DOMXPath $xpath): void
    {
        foreach ($xpath->query('//table//tr') ?: [] as $row) {
            $cells = [];

            foreach ($xpath->query('.//th | .//td', $row) ?: [] as $cell) {
                $text = $this->textOf($cell);

                if ($text !== '') {
                    $cells[] = $text;
                }
            }

            if ($cells !== []) {
                $index->add(EvidenceSource::Table, implode(': ', $cells));
            }
        }

        foreach (iterator_to_array($xpath->query('//table') ?: []) as $table) {
            $this->detach($table);
        }
    }

    private function addBody(EvidenceIndex $index, DOMXPath $xpath): void
    {
        $blocks = $xpath->query('//p | //h1 | //h2 | //h3 | //h4 | //li | //blockquote | //dd | //dt');

        $found = false;

        foreach ($blocks ?: [] as $node) {
            $text = $this->textOf($node);

            if ($text !== '') {
                $index->add(EvidenceSource::Body, $text);
                $found = true;
            }
        }

        // Không có thẻ khối nào — HTML rời rạc hoặc chỉ là text thuần.
        if (! $found) {
            $body = $xpath->query('//body')->item(0);

            if ($body !== null) {
                $index->add(EvidenceSource::Body, $this->textOf($body));
            }
        }
    }

    private function textOf(DOMNode $node): string
    {
        return trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
    }

    private function detach(DOMNode $node): void
    {
        $node->parentNode?->removeChild($node);
    }
}
