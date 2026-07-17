<?php

namespace App\Video\Article;

/**
 * Bài báo thô, trước khi qua bất kỳ xử lý nào.
 *
 * Đây là điểm nhập DUY NHẤT của toàn hệ thống. Mọi sự thật trong video cuối
 * cùng đều phải truy được về một trong các trường ở đây — không có đường nào
 * khác đưa dữ liệu vào Truth Layer.
 */
final class RawArticle
{
    /**
     * @param array<string, scalar> $metadata Ngày đăng, tác giả, nguồn…
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $html,
        public readonly array $metadata = [],
    ) {
    }
}
