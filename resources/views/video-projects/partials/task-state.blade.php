@if(($task ?? null) !== null)
    @php($since = $task['status'] === 'running' ? $task['started_at'] : $task['queued_at'])
    <div class="line" data-task-endpoint="{{ route('video-projects.tasks', $projectId) }}" data-task-kind="{{ $task['kind'] ?? 'stages' }}" data-task-id="{{ $task['id'] ?? $task['stage_id'] }}" data-task-status="{{ $task['status'] }}">
        <span class="k">Hàng đợi</span>
        <span class="v" data-task-label style="color:var(--vp-amber-fg)">{{ $task['status'] === 'running' ? 'Đang chạy' : 'Đang chờ' }}{{ $task['requested_by'] ? ' · '.$task['requested_by'] : '' }}{{ $since ? ' · từ '.\Illuminate\Support\Carbon::parse($since)->format('H:i') : '' }}{{ $task['retry_at'] ? ' · chờ hạn mức nhà cung cấp tới '.date('H:i', $task['retry_at']) : '' }} — trang tự cập nhật khi xong, đóng trang vẫn chạy tiếp</span>
    </div>
@endif
