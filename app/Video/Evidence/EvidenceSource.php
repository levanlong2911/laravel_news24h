<?php

namespace App\Video\Evidence;

/**
 * Evidence không chỉ nằm ở thân bài.
 *
 * Chiều dài con tàu thường nằm trong bảng thông số; tên nằm ở tiêu đề; ngày
 * tháng nằm ở metadata. Giới hạn Evidence vào mỗi body sẽ loại oan hàng loạt
 * sự thật có thật.
 */
enum EvidenceSource: string
{
    case Body     = 'body';
    case Headline = 'headline';
    case Caption  = 'caption';
    case Table    = 'table';
    case Metadata = 'metadata';
}
