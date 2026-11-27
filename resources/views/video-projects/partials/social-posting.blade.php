{{-- One posting of a package revision: its status, each step with its own result, and what may be done with it now. --}}
@php
    $at = static fn ($time): string => $time === null ? '—' : \Illuminate\Support\Carbon::parse($time)->timezone($zone ?: 'UTC')->format('Y-m-d H:i').' ('.($zone ?: 'UTC').')';
    $stepNames = ['check' => 'Kiểm lại trước khi gửi', 'open' => 'Mở phiên upload', 'upload' => 'Tải file', 'finish' => 'Gửi lệnh đăng', 'status' => 'Trạng thái xử lý', 'publish' => 'Đăng', 'schedule' => 'Lên lịch', 'cover' => 'Đặt cover', 'synthetic_media' => 'Khai báo nội dung AI', 'reconcile' => 'Đối chiếu', 'person' => 'Người vận hành ghi nhận', 'worker' => 'Tiến trình gửi', 'send' => 'Gửi', 'cancel' => 'Yêu cầu hủy lịch qua API', 'follow' => 'Theo dõi tự động'];
    $stepStates = ['ok' => ['xong', 'ok'], 'failed' => ['lỗi', 'bad'], 'unknown' => ['chưa rõ', 'bad'], 'manual' => ['làm tay trên nền tảng', 'warn'], 'none' => ['không có', ''], 'published' => ['đã đăng', 'info'], 'cancelled' => ['đã hủy', ''], 'rescheduled' => ['đổi lịch', 'info'], 'overdue' => ['quá hạn theo dõi — cần người kiểm tra', 'bad']];
    $labels = [
        'manual_reported' => ['Đã đăng — xác nhận thủ công', 'info'], 'queued' => ['Chờ gửi', ''], 'uploading' => ['Đang tải lên', 'warn'],
        'processing' => ['Nền tảng đang xử lý', 'warn'], 'scheduled' => ['Nền tảng giữ lịch', 'info'], 'published' => ['Đã đăng — API xác nhận', 'ok'],
        'failed' => ['Lỗi', 'bad'], 'unknown' => ['Chưa rõ kết quả', 'bad'], 'cancelled' => ['Đã hủy', ''],
    ];
    [$label, $class] = $labels[$posting['status']] ?? [$posting['status'], ''];

    if ($posting['status'] === 'published' && $posting['confirmed_by_person']) {
        [$label, $class] = ['Đã đăng — xác nhận thủ công', 'info'];
    }
@endphp
<div class="fcomp-note" data-social-posting="{{ $posting['id'] }}" data-social-posting-status="{{ $posting['status'] }}">
    <div class="social-row">
        <span class="social-tag {{ $class }}">{{ $label }}</span>
        <small>{{ $posting['method'] === 'manual' ? 'Đăng thủ công' : 'Đăng qua API' }}@if($posting['intent'] === 'repost') · đăng thêm có chủ đích @endif · tạo {{ $at($posting['created_at']) }}</small>
        @if($posting['scheduled_at'])<small>· hẹn {{ $at($posting['scheduled_at']) }}</small>@endif
        @if($posting['published_at'])<small>· đăng {{ $at($posting['published_at']) }}</small>@endif
        @if($posting['url'])<a href="{{ $posting['url'] }}" target="_blank" rel="noopener">mở bài</a>@endif
        @if($posting['external_id'])<small>· ID nền tảng {{ $posting['external_id'] }}</small>@endif
    </div>
    @if($posting['method'] === 'manual')
        <small>Đây là xác nhận của người dùng; hệ thống không đối chiếu bài trên nền tảng với gói.</small>
    @endif
    @if($posting['error_message'])
        <div><small>{{ $posting['error_code'] ? '['.$posting['error_code'].'] ' : '' }}{{ $posting['error_message'] }}</small></div>
    @endif
    @if($posting['status'] === 'unknown')
        <div><small><b>Chưa rõ nền tảng đã nhận gì.</b> Hệ thống không tự gửi lại. Bấm Đối chiếu để hỏi nền tảng bằng ID/phiên đã lưu; nếu nền tảng không trả lời được, kiểm tra trên nền tảng rồi ghi nhận kết quả.</small></div>
    @endif
    @if(($detail ?? false) && $posting['steps'] !== [])
        <ul class="social-list" data-social-steps>
            @foreach($posting['steps'] as $step)
                @php [$stepLabel, $stepClass] = $stepStates[$step['status']] ?? [$step['status'], '']; @endphp
                <li data-social-step="{{ $step['step'] }}" data-social-step-status="{{ $step['status'] }}">{{ $stepNames[$step['step']] ?? $step['step'] }}: <span class="social-tag {{ $stepClass }}">{{ $stepLabel }}</span> <small>{{ \Illuminate\Support\Carbon::parse($step['at'])->timezone($zone ?: 'UTC')->format('H:i:s') }}@if($step['detail']) — {{ $step['detail'] }}@endif</small></li>
            @endforeach
        </ul>
    @endif
    @if($detail ?? false)
        <div class="social-row">
            @if($posting['method'] === 'api' && in_array($posting['status'], ['uploading', 'processing', 'scheduled', 'unknown'], true) && $can['publish'])
                <form method="POST" action="{{ route('video-projects.social-publication-follow', [$id, $posting['id']]) }}">@csrf<button class="fcomp-download" type="submit" data-social-follow><i class="fas fa-sync"></i> Đối chiếu với nền tảng</button></form>
            @endif
            @if(($posting['status'] === 'queued' && $can['publish']) || ($posting['status'] === 'manual_reported' && $can['export']))
                <form method="POST" action="{{ route('video-projects.social-publication-cancel', [$id, $posting['id']]) }}">@csrf<button class="fcomp-download" type="submit" data-social-cancel><i class="fas fa-times"></i> {{ $posting['status'] === 'queued' ? 'Hủy lượt chờ gửi' : 'Rút ghi nhận đăng tay' }}</button></form>
            @elseif($posting['status'] === 'scheduled' && $can['publish'])
                <form method="POST" action="{{ route('video-projects.social-publication-cancel', [$id, $posting['id']]) }}">@csrf<button class="fcomp-download" type="submit" data-social-cancel><i class="fas fa-times"></i> Hủy lịch</button></form>
            @endif
            @if($posting['status'] === 'queued' && $posting['scheduled_at'] && $can['publish'])
                <form method="POST" action="{{ route('video-projects.social-publication-reschedule', [$id, $posting['id']]) }}" class="social-row">@csrf<input type="datetime-local" name="scheduled_at" required><button class="fcomp-download" type="submit">Đổi giờ</button></form>
            @endif
        </div>
        @if(in_array($posting['status'], ['unknown', 'scheduled'], true) && $can['publish'])
            <form method="POST" action="{{ route('video-projects.social-publication-resolve', [$id, $posting['id']]) }}" class="social-form" data-social-resolve>
                @csrf
                <b>Ghi nhận theo những gì bạn thấy trên nền tảng</b>
                <div class="social-inline">
                    <label>Kết quả<select name="outcome">
                        <option value="published">Đã đăng</option>
                        @if($posting['status'] === 'unknown')<option value="failed">Không có bài nào được đăng</option>@endif
                        @if($posting['status'] === 'scheduled')<option value="cancelled">Đã hủy lịch trên nền tảng</option><option value="rescheduled">Đã đổi giờ trên nền tảng</option>@endif
                    </select></label>
                    <label>URL bài <small>(khi đã đăng)</small><input type="url" name="url" maxlength="500"></label>
                    <label>Thời điểm <small>giờ {{ $zone }}</small><input type="datetime-local" name="time"></label>
                </div>
                <button class="fcomp-download" type="submit">Ghi nhận (xác nhận thủ công)</button>
            </form>
        @endif
    @endif
</div>
