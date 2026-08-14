<?php

namespace Tests\Video\Article;

use App\Video\Article\ArticleNormalizer;
use App\Video\Article\RawArticle;
use App\Video\Evidence\EvidenceSource;
use PHPUnit\Framework\TestCase;

class ArticleNormalizerTest extends TestCase
{
    private ArticleNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new ArticleNormalizer();
    }

    private function article(string $html, string $title = 'Moonrise sold for €325M', array $metadata = []): RawArticle
    {
        return new RawArticle('a1', $title, $html, $metadata);
    }

    public function test_headline_becomes_findable_evidence(): void
    {
        $index = $this->normalizer->normalize($this->article('<p>Body text.</p>'));

        $evidence = $index->find('Moonrise sold for €325M');

        $this->assertNotNull($evidence);
        $this->assertSame(EvidenceSource::Headline, $evidence->source);
    }

    /**
     * Thông số con tàu sống trong bảng. Giữ cặp nhãn–giá trị: nếu bóc thành chữ
     * chạy dài thì "19.5" mất nhãn và không ai còn biết nó là của cái gì.
     */
    public function test_table_rows_keep_their_label(): void
    {
        $index = $this->normalizer->normalize($this->article(
            '<table><tr><td>Top speed</td><td>19.5 knots</td></tr></table>',
        ));

        $evidence = $index->find('Top speed: 19.5 knots');

        $this->assertNotNull($evidence);
        $this->assertSame(EvidenceSource::Table, $evidence->source);
    }

    public function test_captions_are_evidence_too(): void
    {
        $index = $this->normalizer->normalize($this->article(
            '<figure><img src="x.jpg"><figcaption>Moonrise under way at dusk</figcaption></figure>',
        ));

        $evidence = $index->find('Moonrise under way at dusk');

        $this->assertNotNull($evidence);
        $this->assertSame(EvidenceSource::Caption, $evidence->source);
    }

    public function test_image_alt_text_is_evidence(): void
    {
        $index = $this->normalizer->normalize($this->article('<img src="x.jpg" alt="The grey hull at sea">'));

        $this->assertSame(EvidenceSource::Caption, $index->find('The grey hull at sea')?->source);
    }

    public function test_metadata_keeps_its_field_name(): void
    {
        $index = $this->normalizer->normalize(
            $this->article('<p>Body.</p>', metadata: ['published' => '2025-03-14']),
        );

        // Giữ tên trường để LLM trích được cụm có nghĩa, không phải số trần trụi.
        $this->assertSame(EvidenceSource::Metadata, $index->find('published: 2025-03-14')?->source);
    }

    public function test_scripts_and_styles_never_become_evidence(): void
    {
        $index = $this->normalizer->normalize($this->article(
            '<script>var hullColor = "purple";</script><style>.x{color:red}</style><p>The grey hull.</p>',
        ));

        // Nếu lọt, LLM sẽ có "bằng chứng" hợp lệ cho hull_color = purple.
        $this->assertFalse($index->has('purple'));
        $this->assertTrue($index->has('The grey hull.'));
    }

    /**
     * Đoạn thật từ bài Launchpad. Model trích đúng nghĩa nhưng bỏ `**`, và
     * trước bản này quote đó bị loại oan.
     */
    public function test_balanced_emphasis_markers_are_stripped(): void
    {
        $index = $this->normalizer->normalize($this->article(
            '<p>* Bể bơi có **đáy nâng hạ**, cho phép biến đổi công năng mặt sàn.</p>'
            .'<p>Sàn __teak__ và mũi *thẳng đứng*.</p>',
        ));

        $this->assertTrue($index->has('Bể bơi có đáy nâng hạ, cho phép biến đổi công năng mặt sàn.'));
        $this->assertTrue($index->has('Sàn teak và mũi thẳng đứng.'));
    }

    public function test_stripping_emphasis_never_touches_bullets_maths_or_identifiers(): void
    {
        $index = $this->normalizer->normalize($this->article(
            '<p>* Một gạch đầu dòng thật.</p><p>Phép nhân 2*3*4 và biến snake_case_name.</p>'
            .'<p>Dấu sao rời a * b.</p>',
        ));

        $this->assertTrue($index->has('* Một gạch đầu dòng thật.'));
        $this->assertTrue($index->has('Phép nhân 2*3*4 và biến snake_case_name.'));
        $this->assertTrue($index->has('Dấu sao rời a * b.'));
    }

    /**
     * Marker kép NẰM GIỮA chữ/số không phải nhấn mạnh. Bản đầu chỉ chặn marker
     * đứng cạnh marker nên nuốt mất cả hai chuỗi này.
     */
    public function test_double_markers_inside_a_word_or_number_are_not_emphasis(): void
    {
        $index = $this->normalizer->normalize($this->article(
            '<p>Định danh foo__bar__baz và biểu thức 2**3**4 giữ nguyên.</p>',
        ));

        $this->assertTrue($index->has('foo__bar__baz'));
        $this->assertTrue($index->has('2**3**4'));
    }

    public function test_emphasis_boundary_recognises_vietnamese_letters(): void
    {
        // \w của PHP không nhận `ể` (không bật PCRE_UCP) — nên phải dùng \pL\pN,
        // nếu không marker kẹp giữa chữ tiếng Việt sẽ bị bóc nhầm.
        $index = $this->normalizer->normalize($this->article('<p>Chuỗi Bể*nghiêng*tàu giữ nguyên.</p>'));

        $this->assertTrue($index->has('Bể*nghiêng*tàu'));
    }

    public function test_table_text_is_not_duplicated_into_body(): void
    {
        $index = $this->normalizer->normalize($this->article(
            '<p>Intro.</p><table><tr><td>Top speed</td><td>19.5 knots</td></tr></table>',
        ));

        $this->assertSame(EvidenceSource::Table, $index->find('19.5 knots')?->source);
    }

    public function test_survives_malformed_html(): void
    {
        // HTML bài báo thật gần như luôn sai chuẩn — ta cần chữ, không cần markup hợp lệ.
        $index = $this->normalizer->normalize($this->article(
            '<p>The grey hull measures 101 metres<p><div>unclosed<span>tags',
        ));

        $this->assertTrue($index->has('The grey hull measures 101 metres'));
    }

    public function test_plain_text_without_block_tags_still_indexes(): void
    {
        $index = $this->normalizer->normalize($this->article('The grey hull measures 101 metres.'));

        $this->assertTrue($index->has('The grey hull measures 101 metres.'));
    }

    public function test_whitespace_and_entities_are_normalized(): void
    {
        $index = $this->normalizer->normalize($this->article(
            "<p>The   grey\n\thull &amp; the\u{00A0}vertical bow</p>",
        ));

        $this->assertTrue($index->has('The grey hull & the vertical bow'));
    }

    public function test_empty_article_yields_empty_index(): void
    {
        $index = $this->normalizer->normalize(new RawArticle('a1', '', '', []));

        $this->assertTrue($index->isEmpty());
    }

    public function test_is_deterministic(): void
    {
        $article = $this->article('<p>The grey hull measures 101 metres.</p>');

        $this->assertSame(
            $this->normalizer->normalize($article)->fullText(),
            $this->normalizer->normalize($article)->fullText(),
        );
    }
}
