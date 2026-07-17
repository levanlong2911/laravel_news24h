<?php

namespace App\Video\Extraction;

/**
 * LLM trả về thứ không parse nổi.
 *
 * Fail fast, KHÔNG tự chữa. Đoán xem LLM "định nói gì" chính là bịa — đúng thứ
 * cả Truth Layer sinh ra để chặn.
 */
final class MalformedExtraction extends \RuntimeException
{
}
