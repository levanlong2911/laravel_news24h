<?php

return [
    /**
     * Bump thủ công khi RenderPlan schema/pipeline thay đổi đáng kể (không tự
     * động lấy git sha — CI/Docker/release zip có thể không có .git). Dùng để
     * đối chiếu 2 lần chạy `video:benchmark` có cùng phiên bản pipeline không.
     */
    'pipeline_version' => '2026.07.22',

    /**
     * EditorialPolicy (§12 Rule #1: data, không phải code) — knowledge world
     * thật, tiêm qua constructor EditorialInterpreter. Mặc định TRỐNG cho tới
     * khi có bằng chứng thật (§12 lịch sử: "policy thật thêm sau" — không
     * hardcode Feadship/domes vào code, chỉ vào data ở đây).
     *
     * Feadship/domes: bài Moonrise 2025 refit nói "integrated satellite
     * receivers instead of exposed radomes" — domes=true là suy luận (article
     * không nói thẳng), KHÔNG qua nổi Gatekeeper. Đây là editorial taste đã
     * biết trước (world-knowledge), không phải fact — đúng chỗ của Editorial,
     * không phải Truth. Xem docs/video/ARCHITECTURE.md §12.
     */
    'editorial_policies' => [
        [
            'match'              => ['builder' => 'Feadship'],
            'prohibit_attribute' => 'domes',
            'prohibit_value'     => true,
            'reason'             => 'integrated satellite receivers instead of exposed radomes (2025 refit)',
        ],
    ],
];
