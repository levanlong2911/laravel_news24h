<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\ExecutionGraph;

/**
 * Lưu trữ trạng thái ExecutionGraph sau mỗi node hoàn thành.
 * Dùng cho restart/resume khi node thất bại.
 *
 * Implementation:
 *   - InMemoryCheckpointStore  — tests (không cần Laravel)
 *   - CacheCheckpointStore     — production (dùng Laravel Cache)
 */
interface CheckpointStore
{
    /**
     * Lưu snapshot của ExecutionGraph.
     * Được gọi sau mỗi node thay đổi trạng thái (COMPLETED hoặc FAILED).
     */
    public function save(string $executionId, ExecutionGraph $graph): void;

    /**
     * Load snapshot gần nhất của ExecutionGraph.
     * Trả về null nếu chưa có checkpoint.
     */
    public function load(string $executionId): ?ExecutionGraph;

    /**
     * Xóa checkpoint sau khi execution hoàn thành thành công.
     */
    public function clear(string $executionId): void;
}
