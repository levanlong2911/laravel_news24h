<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Framework fallback
    |--------------------------------------------------------------------------
    |
    | Dùng khi category chưa cấu hình CategoryContext, hoặc framework của nó đã
    | bị tắt. PromptBuilderService::buildFallback() đọc key này.
    |
    | Trước đây chỗ đó chọn "framework active cũ nhất" bằng orderBy('created_at').
    | Nhưng 8 framework do PromptSystemSeeder tạo có created_at giống nhau tới
    | từng giây, nên thứ tự hoàn toàn không xác định — cùng một truy vấn đã trả
    | về entertainment_viral rồi nfl_sports ở hai lần chạy cách nhau vài phút.
    | Bài thiên văn có thể nhận system_prompt "elite NFL journalist" tuỳ hôm.
    |
    | Chọn knowledge_discovery làm mặc định: fallback nghĩa là không biết domain,
    | mà chỉ dẫn của nó (ưu tiên độ chính xác, chống thổi phồng, cấm suy diễn
    | nhân quả) là an toàn nhất cho nội dung chưa phân loại được. Bảy cái còn lại
    | đều mang giọng chuyên ngành hẹp hơn.
    |
    */

    'fallback_framework' => env('PROMPT_FALLBACK_FRAMEWORK', 'knowledge_discovery'),

    /*
    |--------------------------------------------------------------------------
    | Structure template mặc định
    |--------------------------------------------------------------------------
    |
    | Dùng khi HookEngine nhận diện được content type nhưng type đó không có
    | structure_template. ArticlePipelineService đọc key này.
    |
    | Trước khi file config này tồn tại, config('prompt.default_structure', '')
    | luôn trả về chuỗi rỗng — nhánh fallback trên thực tế không tồn tại.
    |
    */

    'default_structure' => "① HOOK — Open on the single most consequential fact\n"
        . "② CONTEXT — What led here; what was at stake\n"
        . "③ DETAIL — The specifics: numbers, names, sequence of events\n"
        . "④ REACTION — Direct quotes and responses from named people\n"
        . "⑤ WHAT'S NEXT — The concrete next fact: a date, a decision, or a consequence already in motion",

];
