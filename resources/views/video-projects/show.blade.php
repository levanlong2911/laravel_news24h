@extends('layouts.base', ['title' => __('tag.tag_detail')])
@section('title', __('tag.tag_detail'))
@section('content')
<div class="container-fluid">

{{--
  §18.30: planning/failed la hai trang thai session CHUA CO shot nao (renderplan_json
  con null luc failed som) — hien rieng, khong de lot xuong bang shot rong ben duoi.
--}}
@if($session->status === 'planning')
<div class="card card-default"><div class="card-body">
  <span class="text-warning">⏳ {{ \App\Enums\VideoSessionStatus::PLANNING->label() }}</span> — pipeline Claude đang chạy nền
  (thường 25-90 giây). F5 trang này để xem tiến độ.
</div></div>
@elseif($session->status === 'failed' && $session->renderplan_json === null)
<div class="card card-default"><div class="card-body">
  <span class="text-danger">✘ Lên kế hoạch thất bại</span>
  @if($session->error_message)
    <div class="text-muted mt-1"><small>{{ $session->error_message }}</small></div>
  @endif
</div></div>
@endif

<div class="card card-default"><div class="card-body">
  <b>{{ $session->project->title }}</b> — {{ $session->shots->count() }} shot ·
  Ước tính: <b>${{ number_format($session->cost_estimate_total, 2) }}</b> ·
  Thực chi: <b>${{ number_format($session->cost_actual, 2) }}</b> ·
  Đã duyệt: <b>{{ $session->shots->where('status', 'approved')->count() }}</b> ·
  Queued: <b>{{ $session->shots->where('status', 'queued')->count() }}</b>
  <form method="post" action="{{ route('video-session.queue', $session->id) }}" style="display:inline"
        onsubmit="return confirm('Render {{ $session->shots->where('status','approved')->count() }} shot đã duyệt (${{ number_format($session->shots->where('status','approved')->sum('cost_estimate'), 2) }})?')">
    @csrf <button class="btn btn-danger btn-sm" {{ $session->shots->where('status','approved')->count() ? '' : 'disabled' }}>🎬 Render các shot đã duyệt</button>
  </form>

  {{--
    Nut THU nam ngay canh nut TIEU TIEN, va KHONG bi disable theo so shot da
    duyet: no ton tai de tra loi cau hoi "bam Render bay gio thi chuyen gi xay
    ra", ma cau hoi do dang nhat khi CHUA duyet gi. Khong confirm, khong mau do
    — no khong doi gi ca.
  --}}
  <form method="post" action="{{ route('video-session.preflight', $session->id) }}" style="display:inline">
    @csrf <button class="btn btn-outline-secondary btn-sm">🔍 Thử render (không tốn tiền)</button>
  </form>

  {{--
    Chỉ bật khi MỌI cảnh trong renderplan_json['timeline'] đã có shot motion
    rendered — $finalReadiness tính một lần trong Controller (finalCompositionReadiness()),
    cùng nguồn dữ liệu buildFinalCompositionPlan() dùng lúc Python kéo kế hoạch,
    nên nút bật/tắt đúng với cái Python thực sự sẽ thấy.
  --}}
  <form method="post" action="{{ route('video-session.compose-final', $session->id) }}" style="display:inline">
    @csrf <button class="btn btn-primary btn-sm" {{ $finalReadiness['ready'] ? '' : 'disabled' }}
      title="{{ $finalReadiness['ready'] ? '' : 'Còn thiếu render cho: '.implode(', ', $finalReadiness['missing']) }}">
      🎬 Ghép video hoàn chỉnh
    </button>
  </form>
</div></div>

@if($latestFinal)
<div class="card card-default"><div class="card-body">
  <b>Bản ghép gần nhất</b> —
  @if($latestFinal->status === 'ready')
    <span class="text-success">✔ ready</span> ·
    <a href="{{ asset($latestFinal->video_path) }}" target="_blank">{{ $latestFinal->video_path }}</a> ·
    {{ $latestFinal->duration_seconds }}s · ${{ number_format($latestFinal->cost_total, 3) }}
  @elseif($latestFinal->status === 'composing')
    <span class="text-warning">⏳ composing</span> — F5 để xem tiến độ.
  @elseif($latestFinal->status === 'failed')
    <span class="text-danger">✘ failed</span> — {{ $latestFinal->error_message }}
  @else
    {{ $latestFinal->status }}
  @endif
</div></div>
@endif

@if(session('preflight'))
<div class="card card-default"><div class="card-body">
  <b>🔍 Thử render</b>
  <div class="text-muted mb-2"><small>Chạy đúng đường render thật, nhưng không gọi vendor và không đổi dữ liệu.</small></div>
  <pre class="bg-dark text-light p-2 mb-0" style="max-height:32rem;overflow:auto;font-size:.8rem">{{ session('preflight') }}</pre>
</div></div>
@endif

@if(session('error'))
<div class="card card-default"><div class="card-body">
  <div class="alert alert-danger mb-0"><b>Thử render không chạy được</b><br><small>{{ session('error') }}</small></div>
</div></div>
@endif

{{--
  Chat luong RenderPlan — CHI CANH BAO, khong chan. Dat NGAY DUOI card tom tat
  vi nut 🎬 Render (cho tieu tien) nam trong card do: de o duoi bang shots thi
  nguoi duyet cuon qua nut tieu tien truoc khi thay canh bao.

  Tinh dong trong Controller moi request, khong luu DB. $quality = null khi
  session khong co renderplan_json (session do Python day ve). Xem
  App\Video\Analysis\RenderPlanQualityReport va ARCHITECTURE.md §18.19.
--}}
@if($quality !== null)
<div class="card card-default">
  <div class="card-body">
    @if($quality['warnings'] === [])
      <span class="text-success"><b>✔ Chất lượng RenderPlan</b> — không có cảnh báo nào.</span>
    @else
      <b>⚠ Chất lượng RenderPlan — {{ count($quality['warnings']) }} cảnh báo</b>
      <div class="text-muted mb-2"><small>Cảnh báo, KHÔNG chặn render — cân nhắc trước khi bấm 🎬 ở trên.</small></div>
      @foreach($quality['warnings'] as $warning)
        <div class="alert alert-warning py-2 mb-2">
          <b>{{ $warning['code'] }}</b> — {{ $warning['message'] }}
          @if($warning['detail'] !== [])
            <details class="mt-1"><summary><small>chi tiết</small></summary>
              <small><code>{{ json_encode($warning['detail'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code></small>
            </details>
          @endif
        </div>
      @endforeach
    @endif

    <details class="mt-2"><summary><small>Số đo (không phải cảnh báo)</small></summary>
      <table class="table table-sm table-borderless mb-0"><tbody>
      @foreach($quality['metrics'] as $name => $value)
        <tr>
          <td style="width:260px"><small><code>{{ $name }}</code></small></td>
          <td><small>{{ is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $value }}</small></td>
        </tr>
      @endforeach
      </tbody></table>
    </details>
  </div>
</div>
@endif

<form method="post" action="{{ route('video-session.approve-selected', $session->id) }}">@csrf
<button class="btn btn-success btn-sm mb-2">✔ Duyệt các shot đã chọn</button>
<table class="table table-bordered">
<thead><tr><th></th><th>Beat</th><th>Shot</th><th>Type</th><th>Kind</th><th>Preview</th><th>Prompt</th><th>Provider/$</th><th>Status</th><th>Hành động</th></tr></thead>
<tbody>
@foreach($session->shots as $shot)
<tr class="{{ ['approved' => 'table-success', 'rejected' => 'table-danger', 'needs_revision' => 'table-warning', 'rendered' => 'table-info'][$shot->status] ?? '' }}">
  <td><input type="checkbox" name="shot_ids[]" value="{{ $shot->id }}" {{ in_array($shot->status, ['draft', 'needs_revision']) ? '' : 'disabled' }}></td>
  <td>{{ $shot->beat }}</td>
  <td>{{ $shot->shot_code }}</td>
  <td>{{ $shot->shot_type }}</td>
  <td>{{ $shot->kind }}</td>
  <td>@if($shot->preview_path)<img src="{{ asset($shot->preview_path) }}" style="max-width:120px">@endif
      @if($shot->artifact_path)<div><a href="{{ asset($shot->artifact_path) }}" target="_blank">artifact</a></div>@endif</td>
  @php($latestRender = $shot->latestRender)
  @php($shownPrompt = $latestRender ? $latestRender->sent_prompt : $shot->compiled_prompt)
  @php($shownNegative = $latestRender ? $latestRender->negative_prompt : $shot->negative_prompt)
  <td style="max-width:420px"><details><summary>{{ \Illuminate\Support\Str::limit($shownPrompt, 90) }}</summary>
      <small>{{ $shownPrompt }}</small>
      @if($latestRender)<hr><small class="text-muted">Prompt của lần render gần nhất — kế hoạch hiện hành có thể đã đổi.</small>@endif
      @if($shownNegative)<hr><small><b>Negative:</b> {{ $shownNegative }}</small>@endif
      @if($shot->review_note)<hr><small class="text-danger"><b>Note:</b> {{ $shot->review_note }}</small>@endif</details></td>
  <td>{{ $shot->render_plan['provider'] ?? '?' }} / ${{ number_format($shot->cost_estimate, 2) }}</td>
  <td><span class="badge badge-secondary">{{ \App\Enums\VideoShotStatus::tryFrom($shot->status)?->label() ?? $shot->status }}</span></td>
  <td>
    @if(in_array($shot->status, ['draft', 'needs_revision', 'rejected']))
      <button class="btn btn-xs btn-success" formaction="{{ route('video-shot.action', $shot->id) }}" name="action" value="approve">✔</button>
      <button class="btn btn-xs btn-warning" formaction="{{ route('video-shot.action', $shot->id) }}" name="action" value="revise"
              onclick="this.form.note.value = prompt('Lý do cần sửa?') || ''">✎</button>
      <button class="btn btn-xs btn-danger" formaction="{{ route('video-shot.action', $shot->id) }}" name="action" value="reject">✘</button>
    @endif
  </td>
</tr>
@endforeach
</tbody></table>
<input type="hidden" name="note" value="">
</form>
</div>
@endsection
