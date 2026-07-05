@extends('layouts.base', ['title' => 'Benchmark Sessions'])
@section('title', 'Benchmark Sessions')

@section('css')
<style>
.progress-bar-sm { height: 6px; border-radius: 3px; }
.badge-beat { font-size: 11px; font-weight: 600; letter-spacing: .3px; }
.session-card { transition: box-shadow .15s; }
.session-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,.12); }
</style>
@endsection

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex align-items-center mb-4">
        <div>
            <h3 class="mb-0 font-weight-bold">Benchmark Sessions</h3>
            <small class="text-muted">Sprint 3 baseline — 13 fixtures × 3 renders</small>
        </div>
        <div class="ml-auto">
            <a href="{{ route('benchmark.renders', 'sprint3-baseline') }}" class="btn btn-primary btn-sm">
                <i class="fas fa-play-circle mr-1"></i> Annotate sprint3-baseline
            </a>
        </div>
    </div>

    @if($sessions->isEmpty())
        <div class="alert alert-warning">
            No sessions found. Run <code>php artisan db:seed --class=BenchmarkSeeder</code> to seed the database.
        </div>
    @endif

    <div class="row">
        @foreach($sessions as $row)
        @php $s = $row['session']; @endphp
        <div class="col-md-6 col-xl-4 mb-4">
            <div class="card card-default session-card h-100">
                <div class="card-header d-flex align-items-center">
                    <div>
                        <b>{{ $s->name }}</b>
                        <span class="badge badge-secondary ml-2">{{ $s->sprint }}</span>
                    </div>
                    @if($row['pct'] >= 100)
                        <span class="badge badge-success ml-auto">Complete</span>
                    @elseif($row['annotated'] > 0)
                        <span class="badge badge-warning ml-auto">In progress</span>
                    @else
                        <span class="badge badge-light ml-auto text-muted">Not started</span>
                    @endif
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">{{ $s->description }}</p>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <small>Renders annotated</small>
                            <small><b>{{ $row['annotated'] }}/{{ $row['total'] }}</b></small>
                        </div>
                        <div class="progress progress-bar-sm">
                            <div class="progress-bar bg-success" style="width: {{ $row['pct'] }}%"></div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <small>Instructions annotated</small>
                            <small><b>{{ $row['inst_done'] }}/{{ $row['inst_total'] }}</b></small>
                        </div>
                        <div class="progress progress-bar-sm">
                            <div class="progress-bar bg-info" style="width: {{ $row['inst_pct'] }}%"></div>
                        </div>
                    </div>

                    @if($s->git_commit)
                    <small class="text-muted">
                        <i class="fas fa-code-branch mr-1"></i>
                        <code>{{ substr($s->git_commit, 0, 8) }}</code>
                    </small>
                    @endif
                </div>
                <div class="card-footer bg-transparent">
                    <a href="{{ route('benchmark.renders', $s->code) }}" class="btn btn-outline-primary btn-sm btn-block">
                        View Renders →
                    </a>
                </div>
            </div>
        </div>
        @endforeach
    </div>
</div>
@endsection
