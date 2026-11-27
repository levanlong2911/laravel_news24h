@php
    $requested = $entry['requested'];
    $measured = $entry['measured'];
    $seconds = static fn (mixed $ms): string => is_int($ms) ? rtrim(rtrim(number_format($ms / 1000, 3, '.', ''), '0'), '.').' s' : '—';
    $words = ['ai_video' => 'AI Video', 'dynamic_crop' => 'Dynamic Crop', 'keyframe' => 'từ keyframe', 'references' => 'từ ảnh tham chiếu', 'allow_silent' => 'cho phép không tiếng', 'require_audio' => 'bắt buộc có tiếng'];
@endphp
<div class="d">Yêu cầu: {{ $words[$requested['motion_method']] ?? $requested['motion_method'] }} · {{ $words[$requested['input_mode'] ?? ''] ?? ($requested['input_mode'] ?? '—') }} · {{ $requested['resolution'] ?? '—' }} · {{ $requested['aspect_ratio'] ?? '—' }} · file {{ $seconds($requested['duration_ms']) }}@if(is_array($requested['used'])) · phim giữ {{ $requested['used']['start_ms'] }}–{{ $requested['used']['start_ms'] + $requested['used']['duration_ms'] }} ms @endif @if($requested['audio_policy'] !== null) · {{ $words[$requested['audio_policy']] ?? $requested['audio_policy'] }} @endif</div>
@if($measured !== null)
    <div class="d">Đo được: {{ $seconds($measured['duration_ms']) }} · {{ $measured['width'] ?? '—' }} × {{ $measured['height'] ?? '—' }}@if($measured['fps'] !== null) · {{ $measured['fps'] }} fps @endif @if($measured['codec'] !== null) · {{ $measured['codec'] }}/{{ $measured['pixel_format'] }} @endif · {{ $measured['has_audio'] === true ? 'có tiếng' : ($measured['has_audio'] === false ? 'không tiếng' : 'chưa rõ tiếng') }}@if($measured['bytes'] !== null) · {{ number_format((int) $measured['bytes'], 0, ',', '.') }} byte @endif @if($measured['decoded'] === true) · giải mã toàn bộ không lỗi @endif</div>
@endif
@if($entry['cost'] !== null)
    <div class="d">Chi phí ghi nhận: ${{ number_format((float) $entry['cost'], 4) }}</div>
@endif
