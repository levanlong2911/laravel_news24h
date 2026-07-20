@extends('layouts.base', ['title' => 'Video Sessions'])
@section('title', 'Video Sessions')
@section('content')
<div class="container-fluid"><div class="card card-default"><div class="card-body">
<table class="table table-bordered table-hover">
<thead><tr><th>Project</th><th>Session</th><th>Shots</th><th>Status</th><th>Ước tính ($)</th><th>Thực chi ($)</th><th></th></tr></thead>
<tbody>
@forelse($sessions as $s)
<tr>
  <td>{{ $s->project->name }}</td>
  <td>{{ $s->code }}</td>
  <td>{{ $s->shots_count }}</td>
  <td><span class="badge badge-{{ $s->status === 'done' ? 'success' : 'info' }}">{{ $s->status }}</span></td>
  <td>{{ number_format($s->cost_estimate_total, 2) }}</td>
  <td>{{ number_format($s->cost_actual, 2) }}</td>
  <td><a class="btn btn-sm btn-primary" href="{{ route('video-session.show', $s->id) }}">Review</a></td>
</tr>
@empty
<tr><td colspan="7">Chưa có session — Python push qua POST /api/render-plans</td></tr>
@endforelse
</tbody></table>
</div></div></div>
@endsection
