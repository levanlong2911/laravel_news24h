<?php

namespace App\Video\Scene;

/**
 * Chức năng tự sự của một scene. Suy CƠ HỌC từ (narrative_role, act_source) —
 * không gu, không domain.
 *
 * Trùng value set với `scene.purpose` trong contract RenderPlan (§6) để lắp
 * thẳng vào lúc emit ở Phase 5. ScenePlanner chỉ sinh MỘT TẬP CON các value này;
 * `PROCESS` chẳng hạn chưa được sinh (chưa có event nào mang nghĩa "quá trình"
 * trong dữ liệu thật). Đó KHÔNG phải enum chết: enum này thuộc CONTRACT, không
 * phải phát minh của planner — schema định nghĩa cái gì được phép, planner dùng
 * một phần. Khác với NarrativeRole, nơi planner tự làm chủ enum.
 */
enum ScenePurpose: string
{
    case Reveal     = 'REVEAL';
    case Establish  = 'ESTABLISH';
    case Process    = 'PROCESS';
    case Detail     = 'DETAIL';
    case Action     = 'ACTION';
    case Comparison = 'COMPARISON';
    case Resolution = 'RESOLUTION';
}
