<?php

namespace App\Video\Intent;

/**
 * Ý đồ máy quay cho một scene — độc lập provider.
 *
 * WIDE/ORBIT/SLOW vẫn là ngôn ngữ điện ảnh, KHÔNG phải prompt. Prompt chỉ xuất
 * hiện khi Python compile: WIDE+ORBIT → "24mm cinematic drone orbit" cho Kling,
 * hoặc "wide establishing crane shot" cho provider khác. Đây là điểm tách
 * provider — cùng một CameraIntent, nhiều prompt khác nhau.
 */
final class CameraIntent
{
    public function __construct(
        public readonly CameraFraming $framing,
        public readonly CameraMovement $movement,
        public readonly CameraSpeed $speed,
        /** entity id máy quay hướng vào — chủ thể chính của scene. */
        public readonly string $target,
    ) {
    }
}
