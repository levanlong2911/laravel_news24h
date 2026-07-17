<?php

namespace App\Video\Extraction;

/**
 * Không có bản ghi cho bài báo này.
 *
 * CỐ TÌNH không tự gọi Claude để lấp chỗ trống. Một RecordedExtractor lặng lẽ
 * fallback sang API thật sẽ biến CI thành thứ đốt tiền và cần mạng — đúng cái
 * nó sinh ra để tránh.
 */
final class RecordingMissing extends \RuntimeException
{
}
