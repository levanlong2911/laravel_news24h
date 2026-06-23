@extends('layouts.base', ['title' => 'Video Jobs'])
@section('title', 'Video Jobs')
@section('content')
<div class="container-fluid">

    <div class="row align-items-center mb-3">
        <div class="col">
            <h4 class="mb-0">
                Video Jobs
                <span class="badge badge-secondary ml-2">{{ $plans->total() }}</span>
            </h4>
        </div>
        <div class="col-auto">
            <form method="POST" action="{{ route('video-job.generate', '__ID__') }}"
                  onsubmit="
                      var sel = document.getElementById('genArticleSelect');
                      if (sel.value === '') return false;
                      trackVideoGeneration(sel.value, sel.options[sel.selectedIndex].text);
                      this.action = this.action.replace('__ID__', sel.value);
                      return true;
                  "
                  class="d-flex" style="gap:6px">
                @csrf
                <select id="genArticleSelect" class="form-control form-control-sm" style="width:340px" required>
                    <option value="">-- Chọn bài viết để tạo Video AI --</option>
                    @foreach($candidates as $candidate)
                        <option value="{{ $candidate->id }}">{{ Str::limit($candidate->title, 70) }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="fas fa-video"></i> Tạo Video AI
                </button>
            </form>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show py-2">
            {{ session('success') }}
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show py-2">
            {{ session('error') }}
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    @endif

    <div class="card card-default">
        <div class="card-body p-0">
            <table class="table table-sm table-bordered table-striped mb-0">
                <thead class="bg-dark text-white">
                    <tr>
                        <th>Article</th>
                        <th width="70" class="text-center">Score</th>
                        <th width="90" class="text-center">Mood</th>
                        <th width="110" class="text-center">Parts</th>
                        <th width="220">Progress</th>
                        <th width="100" class="text-center">Cost (USD)</th>
                        <th width="80" class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($plans as $plan)
                    @php
                        $jobs = $plan->videoJobs;
                        $uploaded = $jobs->where('status', 'uploaded')->count();
                        $failed = $jobs->whereIn('status', ['quality_check_failed', 'upload_failed'])->count();
                        $totalCost = $jobs->sum('cost_total');
                    @endphp
                    <tr>
                        <td class="align-middle">
                            <a href="{{ route('video-job.show', $plan->article_id) }}" class="font-weight-bold text-dark d-block text-truncate" style="max-width:380px">
                                {{ Str::limit($plan->article->title ?? '(article deleted)', 90) }}
                            </a>
                        </td>
                        <td class="text-center align-middle">
                            <span class="badge badge-secondary">{{ $plan->article->viral_score ?? '—' }}</span>
                        </td>
                        <td class="text-center align-middle small">{{ $plan->mood }}</td>
                        <td class="text-center align-middle small">{{ $jobs->count() }} / {{ $plan->total_parts }}</td>
                        <td class="align-middle">
                            <span class="badge badge-success">{{ $uploaded }} uploaded</span>
                            @if($failed > 0)
                                <span class="badge badge-danger">{{ $failed }} failed</span>
                            @endif
                        </td>
                        <td class="text-center align-middle small">${{ number_format($totalCost, 4) }}</td>
                        <td class="text-center align-middle">
                            <a href="{{ route('video-job.show', $plan->article_id) }}" class="btn btn-xs btn-outline-primary" title="View">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">Chưa có story plan nào.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            {{ $plans->links('pagination::bootstrap-4') }}
        </div>
    </div>
</div>
@endsection
