<?php

namespace App\Video\Editorial;

/**
 * Tông màu / xử lý ánh sáng nghệ thuật. Editorial taste.
 *
 * KHÁC `world.time_of_day`: time_of_day là FACT (bài báo ghi "at dusk"),
 * light_grade là CHỌN LỰA thẩm mỹ (tông vàng ấm). Đúng luật "đừng trộn hai nguồn
 * tri thức vào một field" — §13.
 */
enum LightGrade: string
{
    case Warm    = 'WARM';
    case Cool    = 'COOL';
    case Neutral = 'NEUTRAL';
    case Golden  = 'GOLDEN';
    case Noir    = 'NOIR';
}
