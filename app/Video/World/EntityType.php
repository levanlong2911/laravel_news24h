<?php

namespace App\Video\World;

/**
 * Ontology chung. Đóng có chủ ý.
 *
 * Đây là thứ cho phép cùng một đoạn code xử lý du thuyền, nhà máy Tesla, sư tử
 * và chiến tranh. Thêm một case ở đây gần như luôn là dấu hiệu ai đó đang muốn
 * lén đưa domain vào ontology — chi tiết riêng của chủ đề thuộc về `attributes`,
 * không thuộc về type.
 *
 * Phải khớp entity.type trong contracts/renderplan/v1.0/schema.json.
 */
enum EntityType: string
{
    case Human          = 'human';
    case LivingObject   = 'living_object';
    case Vehicle        = 'vehicle';
    case Building       = 'building';
    case Landscape      = 'landscape';
    case PhysicalObject = 'physical_object';
    case Event          = 'event';
    case Effect         = 'effect';
}
