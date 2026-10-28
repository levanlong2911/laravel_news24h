@if(is_array($stream ?? null))
    @php
        $phaseLabel = ['waiting' => 'chờ dữ liệu đầu', 'thinking' => 'đang suy nghĩ', 'writing' => 'đang viết JSON'][$stream['phase'] ?? ''] ?? ($stream['phase'] ?? '—');
    @endphp
    <div class="line"><span class="k">Stream</span><span class="v">
        @if(($stream['state'] ?? null) === 'streaming')
            {{ $phaseLabel }}
        @else
            kết thúc: {{ $stream['ended'] ?? '—' }}@if(($stream['stop_reason'] ?? null) !== null) ({{ $stream['stop_reason'] }})@endif
        @endif
        · {{ (int) ($stream['elapsed_seconds'] ?? 0) }} s · {{ number_format((int) ($stream['events'] ?? 0), 0, ',', '.') }} sự kiện · {{ number_format((int) ($stream['thinking_chars'] ?? 0), 0, ',', '.') }} ký tự suy nghĩ · {{ number_format((int) ($stream['text_chars'] ?? 0), 0, ',', '.') }} ký tự JSON
        @if(($stream['last_event_at'] ?? null) !== null) · sự kiện cuối {{ \Illuminate\Support\Carbon::parse($stream['last_event_at'])->format('H:i:s') }}@endif
        @if(($stream['request_id'] ?? null) !== null) · {{ $stream['request_id'] }}@endif
        @if(($stream['state'] ?? null) === 'failed' && ($stream['usage_complete'] ?? false) !== true) · <span style="color:var(--vp-red)">chưa nhận usage cuối — chi phí ghi nhận chỉ là mức tối thiểu</span>@endif
    </span></div>
@endif
