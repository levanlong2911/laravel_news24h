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

    /*
    |--------------------------------------------------------------------------
    | Phạm vi của prompt:check
    |--------------------------------------------------------------------------
    |
    | Framework có tên bắt đầu bằng một trong các tiền tố này được BỎ QUA, và
    | việc bỏ qua đó được in ra rõ ràng thay vì im lặng.
    |
    | Danh sách này phải tường minh. Bản kiểm tra đầu tiên lọc phạm vi bằng cách
    | hỏi "phase3 có mục FORBIDDEN PHRASES không" — nghe hợp lý, nhưng nó biến
    | mọi lỗi parser thành báo cáo xanh: đổi tiêu đề mục là framework đó lặng lẽ
    | biến mất khỏi phạm vi kiểm tra. Loại trừ theo tên thì hỏng ở chỗ nhìn thấy
    | được; loại trừ theo nội dung thì hỏng ở chỗ không ai thấy.
    |
    | Nhóm video_* thuộc pipeline dựng video, không phải đường viết tin, và
    | phase3 của chúng theo khuôn hoàn toàn khác.
    |
    */

    'check' => [
        'excluded_framework_prefixes' => ['video_'],

        /*
        | Mục bắt buộc phải có mặt trong phase3. Thiếu một mục là hình dạng tài
        | liệu đã đổi — báo lỗi ngay, đừng chờ tới lúc một bài viết ra sai.
        |
        | Đây là tín hiệu ĐỘC LẬP với việc đọc danh sách từ cấm, không phải cách
        | để định vị nó. Cắt từ cấm theo cửa sổ FORBIDDEN→QUALITY GATE nghe có vẻ
        | "cấu trúc hơn", nhưng khối DOMAIN LAWS nằm trước FACT INTEGRITY tức đầu
        | tài liệu, nên cửa sổ đó nuốt cả STEP 0–3 — mọi câu ví dụ trong ngoặc kép
        | ("The window closes Thursday.") sẽ bị đọc thành cụm cấm.
        */
        /*
        | Dòng checklist trong QUALITY GATE: tiền tố => số dòng phải có.
        |
        | Chỉ đếm và soi trùng lặp, cố ý KHÔNG kiểm câu chữ. Lỗi thật từng xảy ra
        | là migration 074940 dùng regex khớp cả hai dòng "[ ] FB post:" rồi ghi
        | đè bằng cùng một chuỗi — còn đúng số dòng, nhưng hai dòng y hệt nhau và
        | dòng kiểm format biến mất. Đếm + so trùng bắt được đúng dạng đó mà không
        | nhét prose của phase3 vào command.
        */
        'gate_lines' => [
            '[ ] FB image text:' => 1,
            '[ ] FB post:'       => 2,
        ],

        'required_sections' => [
            'ABSOLUTE RULES',
            'FACT INTEGRITY',
            'STEP 1',
            'STEP 2',
            'STEP 3',
            'QUALITY GATE',
        ],
    ],

];
