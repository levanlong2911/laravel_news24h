@extends('layouts.base', ['title' => 'Review Shots'])
@section('title', 'Session ' . $session->code)
@section('content')
<div class="container-fluid">
<div class="card card-default"><div class="card-body">
  <b>{{ $session->project->name }}</b> — {{ $session->shots->count() }} shot ·
  Ước tính: <b>${{ number_format($session->cost_estimate_total, 2) }}</b> ·
  Thực chi: <b>${{ number_format($session->cost_actual, 2) }}</b> ·
  Đã duyệt: <b>{{ $session->shots->where('status', 'approved')->count() }}</b> ·
  Queued: <b>{{ $session->shots->where('status', 'queued')->count() }}</b>
  <form method="post" action="{{ route('video-session.queue', $session->id) }}" style="display:inline" 
        onsubmit="return confirm('Render {{ $session->shots->where('status','approved')->count() }} shot đã duyệt (${{ number_format($session->shots->where('status','approved')->sum('cost_estimate'), 2) }})?')">
    @csrf <button class="btn btn-danger btn-sm" {{ $session->shots->where('status','approved')->count() ? '' : 'disabled' }}>🎬 Render các shot đã duyệt</button>
  </form>
</div></div>

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
  <td style="max-width:420px"><details><summary>{{ \Illuminate\Support\Str::limit($shot->compiled_prompt, 90) }}</summary>
      <small>{{ $shot->compiled_prompt }}</small>
      @if($shot->negative_prompt)<hr><small><b>Negative:</b> {{ $shot->negative_prompt }}</small>@endif
      @if($shot->review_note)<hr><small class="text-danger"><b>Note:</b> {{ $shot->review_note }}</small>@endif</details></td>
  <td>{{ $shot->render_plan['provider'] ?? '?' }} / ${{ number_format($shot->cost_estimate, 2) }}</td>
  <td><span class="badge badge-secondary">{{ $shot->status }}</span></td>
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
