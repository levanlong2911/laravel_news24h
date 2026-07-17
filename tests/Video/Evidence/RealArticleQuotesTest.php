<?php

namespace Tests\Video\Evidence;

use App\Video\Evidence\Value\ValueVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Normalizer đối mặt với chữ THẬT của bài Moonrise (autoevolution.com).
 *
 * Bài báo thật viết số kèm đơn vị theo đủ kiểu mà một regex viết trong phòng
 * kín sẽ không lường: gạch nối thay khoảng trắng, quy đổi trong ngoặc, gạch
 * chéo. Test này chạy trước khi tốn một đồng token nào cho Claude — nếu
 * normalizer không đọc nổi chữ của bài thì Extractor có hoàn hảo cũng vô ích,
 * mọi claim sẽ bị loại oan.
 */
class RealArticleQuotesTest extends TestCase
{
    private ValueVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new ValueVerifier();
    }

    /**
     * @dataProvider realQuotes
     */
    public function test_verifier_reads_real_article_measurements(string $quote, mixed $value, string $why): void
    {
        $this->assertNotNull(
            $this->verifier->verify($quote, $value),
            "Không đọc được {$value} từ \"{$quote}\" — {$why}",
        );
    }

    public static function realQuotes(): array
    {
        return [
            // Gạch nối thay khoảng trắng — kiểu viết phổ biến nhất của bài này
            'hyphenated metre'      => ['The majestic 99.95-meter (327-foot) Moonrise', 99.95, 'gạch nối giữa số và đơn vị'],
            'hyphenated 101'        => ['This 2025 Moonrise is 101-meter-long (330 feet)', 101, 'gạch nối hai đầu'],
            'support vessel'        => ['a 68-meter (223-foot) behemoth', 68, 'gạch nối'],

            // Gạch chéo
            'slash beam'            => ['thanks to a 15.5-meter/50.8-foot beam', 15.5, 'gạch chéo ngăn hai đơn vị'],

            // Khoảng trắng thường
            'knots with paren'      => ['top speed of up to 19.5 knots (36 kph)', 19.5, 'khoảng trắng chuẩn'],
            'cruising knots'        => ['a cruising speed of 16 knots', 16, 'số nguyên'],

            // Dấu phẩy ngăn nghìn, không khoảng trắng trước đơn vị
            'GT no space'           => ['Boasting nearly 4,000 GT of volume', 4000, 'dấu phẩy ngăn nghìn'],
            'range nautical'        => ['more than 8,000 nautical miles (14,816 km)', 8000, 'đơn vị hai chữ'],

            // Tiền tệ
            'euro millions'         => ['€325 million (more than $370 million)', 325000000, 'ký hiệu euro + chữ million'],
            'dollar in parens'      => ['€325 million (more than $370 million)', 370000000, 'đô la nằm trong ngoặc'],

            // Chuỗi
            'hull colour'           => ['a grey hull', 'grey', 'cụm nằm trong quote'],
            'bow'                   => ['a vertical bow that gives it its distinctive elegance', 'vertical', 'cụm giữa câu dài'],

            // Số trần — cú gọi Claude thật làm rụng 8 sự thật ở đây, vì
            // MeasurementNormalizer đòi phải có đơn vị mà "guests" không phải đơn vị.
            'guests'                => ['Moonrise can accommodate up to 16 guests', 16, 'số trần, đơn vị không phải đơn vị đo'],
            'crew'                  => ['a massive 32-person crew', 32, 'số dính vào danh từ bằng gạch nối'],
            'year delivered'        => ['Moonrise was delivered by Feadship in 2020', 2020, 'năm — không cần YearNormalizer riêng'],
            'year sold'             => ['which he sold to Facebook for $19 billion, in 2014', 2014, 'năm nằm cạnh một khoản tiền'],
            'staterooms'            => ['16 guests across eight superb staterooms', 8, 'số viết bằng chữ'],
            'engine count'          => ['fitted with two powerful MTU engines', 2, 'số viết bằng chữ'],
            'shipyards'             => ['operates seven shipyards and a specialist aluminum division', 7, 'số viết bằng chữ'],
            'forbes rank'           => ["Currently ranking 166th on Forbes' 2026 Billionaire List", 166, 'thứ tự — \\b166\\b không khớp trong "166th"'],
            'hangar width'          => ['This 14.5- by 12-meter (47.5 x 39 feet) heli hangar', 14.5, 'số bị bỏ lửng, đơn vị nằm ở số sau'],
        ];
    }

    /**
     * Bài báo có typo thật: "tech billionaire Jan Jan Koum".
     * Tên đúng vẫn phải khớp được, nếu không thì entity mất danh tính.
     */
    public function test_name_matches_despite_article_typo(): void
    {
        $this->assertNotNull(
            $this->verifier->verify('tech billionaire Jan Jan Koum, the world-famous founder of WhatsApp', 'Jan Koum'),
        );
    }

    /**
     * Quy đổi đơn vị vẫn phải bị loại, kể cả khi bài báo TỰ viết ra con số đó
     * ở chỗ khác. 327 feet có thật trong bài — nhưng nó không phải thứ mà quote
     * "99.95-meter" nói ra.
     */
    public function test_still_rejects_conversion_not_stated_by_this_quote(): void
    {
        $this->assertNull($this->verifier->verify('The majestic 99.95-meter Moonrise', 327));
    }
}
