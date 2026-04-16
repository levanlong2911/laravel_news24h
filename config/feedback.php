<?php

return [
    /*
     * EMA alpha — weight của data point mới khi cập nhật performance_score.
     *
     * 0.1 = smooth, "nhớ" ~10 bài gần nhất (1 / alpha)
     * 0.2 = reactive hơn, ~5 bài gần nhất
     * 0.05 = rất smooth, ~20 bài gần nhất
     *
     * Chỉnh ở đây để test sensitivity mà không cần sửa code.
     */
    'ema_alpha' => env('FEEDBACK_EMA_ALPHA', 0.1),

    /*
     * TTL (giây) của cache cho summary().
     * 300 = 5 phút — dashboard load nhanh, vẫn fresh sau vài bài mới.
     */
    'summary_cache_ttl' => env('FEEDBACK_SUMMARY_CACHE_TTL', 300),

    /*
     * Số record tối đa dùng cho summary aggregation window.
     */
    'summary_window' => env('FEEDBACK_SUMMARY_WINDOW', 50),
];
