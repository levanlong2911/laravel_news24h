@extends('layouts.base', ['title' => 'Benchmark Renders'])
@section('title', $session->name . ' — Renders')

@section('css')
<style>
.fixture-header { background: #f8f9fa; border-left: 4px solid #007bff; padding: 8px 14px; font-weight: 600; }
.render-row td { vertical-align: middle; }
.badge-progress { font-size: 11px; }
.status-dot { width:8px; height:8px; border-radius:50%; display:inline-block; margin-right:5px; }
.dot-done   { background: #28a745; }
.dot-partial{ background: #ffc107; }
.dot-empty  { background: #dee2e6; }
</style>
@endsection

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex align-items-center mb-4">
        <div>
            <a href="{{ route('benchmark.sessions') }}" class="text-muted small">← Sessions</a>
            <h3 class="mb-0 font-weight-bold">{{ $session->name }}</h3>
            <small class="text-muted">{{ $session->sprint }} · {{ $byFixture->sum(fn($rs) => $rs->count()) }} renders</small>
        </div>
    </div>

    @if($byFixture->isEmpty())
        <div class="alert alert-info">
            No renders yet. Run
            <code>php artisan benchmark:generate-prompt --fixture=nfl_quarterback_throw --json > payload.json</code>
            then submit via Python client.
        </div>
    @endif

    @foreach($byFixture as $fixtureSlug => $renders)
    @php
        $fixture     = $renders->first()->fixture;
        $totalInst   = $renders->sum(fn($r) => $r->instructionInstances->count());
        $doneInst    = $renders->sum(fn($r) => $r->instructionInstances->whereNotNull('observed')->count());
        $instPct     = $totalInst > 0 ? round($doneInst / $totalInst * 100) : 0;
    @endphp
    <div class="card card-default mb-4">
        <div class="fixture-header d-flex align-items-center">
            <span>{{ $fixture->name }}</span>
            <span class="badge badge-light ml-2">{{ $fixture->scene_category }}</span>
            <span class="ml-auto text-muted small">{{ $doneInst }}/{{ $totalInst }} instructions · {{ $instPct }}%</span>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>UUID</th>
                        <th>Model</th>
                        <th>Seed</th>
                        <th>Chars</th>
                        <th>Instructions</th>
                        <th>Scores</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @foreach($renders as $render)
                @php
                    $instTotal = $render->instructionInstances->count();
                    $instDone  = $render->instructionInstances->filter(fn($i) => $i->observed !== null)->count();
                    $instPct   = $instTotal > 0 ? round($instDone / $instTotal * 100) : 0;
                    $hasScore  = $render->score?->overall !== null;
                    $annotated = $render->annotated_at !== null;

                    if ($annotated)  $dot = 'dot-done';
                    elseif ($instDone > 0) $dot = 'dot-partial';
                    else   $dot = 'dot-empty';
                @endphp
                <tr class="render-row">
                    <td><code class="small">{{ substr($render->uuid, 0, 8) }}</code></td>
                    <td><span class="badge badge-secondary">{{ $render->model }}</span></td>
                    <td><code class="small">{{ $render->seed ?? '—' }}</code></td>
                    <td class="text-right">{{ number_format($render->char_count) }}</td>
                    <td>
                        <div class="d-flex align-items-center">
                            <div class="progress flex-grow-1 mr-2" style="height:6px">
                                <div class="progress-bar {{ $instPct >= 100 ? 'bg-success' : 'bg-info' }}"
                                     style="width:{{ $instPct }}%"></div>
                            </div>
                            <small>{{ $instDone }}/{{ $instTotal }}</small>
                        </div>
                    </td>
                    <td class="text-center">
                        @if($hasScore)
                            <span class="badge badge-success">{{ $render->score->overall }}/10</span>
                        @else
                            <span class="text-muted small">—</span>
                        @endif
                    </td>
                    <td>
                        <span class="status-dot {{ $dot }}"></span>
                        @if($annotated) <small class="text-success">Done</small>
                        @elseif($instDone > 0) <small class="text-warning">Partial</small>
                        @else <small class="text-muted">Pending</small>
                        @endif
                    </td>
                    <td>
                        <a href="{{ route('benchmark.annotate', $render->uuid) }}"
                           class="btn btn-sm {{ $annotated ? 'btn-outline-secondary' : 'btn-primary' }}">
                            {{ $annotated ? 'Review' : 'Annotate' }}
                        </a>
                    </td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endforeach
</div>
@endsection
