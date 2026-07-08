<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\ExecutionGraph;

/**
 * Life-cycle states of a single ExecutionNode.
 *
 * PENDING   → node chưa được chạy
 * RUNNING   → node đang chạy (trạng thái trong memory, không persist)
 * COMPLETED → node chạy xong, kết quả đã lưu
 * FAILED    → node thất bại, error đã ghi lại
 * SKIPPED   → node bị bỏ qua vì dependency FAILED hoặc đã COMPLETED từ checkpoint
 */
enum ExecutionNodeStatus: string
{
    case PENDING   = 'pending';
    case RUNNING   = 'running';
    case COMPLETED = 'completed';
    case FAILED    = 'failed';
    case SKIPPED   = 'skipped';
}
