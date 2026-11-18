{{--
    The check stored when the run was made beside the check the page runs now, when they differ.
    $check: the check now; $stored: verdict, version and technical_schema of the check stored with the run.
--}}
@php
    $stored = is_array($stored ?? null) ? $stored : [];
    $differs = $stored !== [] && (
        ($stored['verdict'] ?? null) !== ($check['verdict'] ?? null)
        || ($stored['version'] ?? null) !== ($check['version'] ?? null)
        || (isset($stored['technical_schema']) && $stored['technical_schema'] !== ($check['technical_schema'] ?? null))
    );
@endphp
@if($differs)
    <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">
        Lúc chạy: {{ $stored['verdict'] ?? '—' }} ({{ $stored['version'] ?? '—' }} · kiểm cấu trúc {{ $stored['technical_schema'] ?? 'chưa ghi' }}) — giữ làm lịch sử, câu trả lời không bị sửa.
        Kiểm hiện tại: {{ $check['verdict'] ?? '—' }} ({{ $check['version'] ?? '—' }} · kiểm cấu trúc {{ $check['technical_schema'] ?? '—' }}).
    </div>
@endif
