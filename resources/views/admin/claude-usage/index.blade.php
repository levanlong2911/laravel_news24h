@extends('layouts.base', ['title' => 'Claude Usage'])
@section('title', 'Claude Usage')
@section('css')
<style>
.claude-usage-wrap { min-height: calc(100vh - 160px); }
</style>
@endsection
@section('content')
@php
    $adminMap    = $admins->keyBy('id');
    $totalCost   = $dailyCost->sum('total_cost');  // AI pipeline + manual combined
    $avgCost     = $tokenStats->avg_cost      ?? 0;
    $totalArts   = $tokenStats->total_articles ?? 0;
    $avgSec      = $tokenStats->avg_sec       ?? 0;
    $haikuIn     = $tokenStats->haiku_in      ?? 0;
    $haikuOut    = $tokenStats->haiku_out     ?? 0;
    $sonnetIn    = $tokenStats->sonnet_in     ?? 0;
    $sonnetOut   = $tokenStats->sonnet_out    ?? 0;
    $totalTokens = $haikuIn + $haikuOut + $sonnetIn + $sonnetOut;
@endphp

<div class="container-fluid claude-usage-wrap">
    <div class="row mb-3">
        <div class="col-12">
            <h4 class="mb-3">Claude Usage</h4>

            {{-- Filter --}}
            <form method="GET" class="mb-4">
                <input type="hidden" name="month" value="{{ $month }}">
                <div class="form-row align-items-end">
                    <div class="col-auto">
                        <label class="small text-muted d-block mb-1">Ngày cụ thể</label>
                        <input type="date" name="date" value="{{ $date }}" class="form-control"
                               placeholder="Chọn ngày">
                    </div>
                    <div class="col-auto">
                        <label class="small text-muted d-block mb-1">Member</label>
                        <select name="admin_id" class="form-control">
                            <option value="">-- Tất cả --</option>
                            @foreach($admins as $a)
                                <option value="{{ $a->id }}" {{ $adminId == $a->id ? 'selected' : '' }}>{{ $a->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary">Lọc</button>
                        @if($date)
                        <a href="{{ route('admin.claude-usage', ['month' => $month, 'admin_id' => $adminId]) }}"
                           class="btn btn-outline-secondary ml-1">Xoá ngày</a>
                        @endif
                    </div>
                </div>
            </form>

            @if($date)
            <div class="alert alert-info py-2 mb-3">
                Đang xem chi tiết ngày <strong>{{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}</strong>
                — {{ $totalArts }} bài AI · ${{ number_format($totalCost, 4) }}
            </div>
            @endif

            {{-- Cost Summary Cards --}}
            <div class="row mb-4">
                <div class="col-sm-6 col-lg-3 mb-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small mb-1">Tổng chi phí tháng {{ $month }}</div>
                            <div class="h3 font-weight-bold text-danger mb-0">${{ number_format($totalCost, 4) }}</div>
                            <div class="text-muted small mt-1">≈ {{ number_format($totalCost * 25000, 0, ',', '.') }} đ</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3 mb-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small mb-1">Trung bình / bài</div>
                            <div class="h3 font-weight-bold text-warning mb-0">${{ number_format($avgCost, 5) }}</div>
                            <div class="text-muted small mt-1">≈ {{ number_format($avgCost * 25000, 0, ',', '.') }} đ / bài</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3 mb-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small mb-1">Bài AI đã xử lý</div>
                            <div class="h3 font-weight-bold text-primary mb-0">{{ number_format($totalArts) }}</div>
                            <div class="text-muted small mt-1">Tốc độ TB: {{ $avgSec }}s / bài</div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3 mb-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small mb-1">Tổng tokens dùng</div>
                            <div class="h3 font-weight-bold text-success mb-0">{{ number_format($totalTokens) }}</div>
                            <div class="text-muted small mt-1">
                                Haiku {{ number_format($haikuIn + $haikuOut) }} &nbsp;·&nbsp; Sonnet {{ number_format($sonnetIn + $sonnetOut) }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Token breakdown bar --}}
            @if($totalTokens > 0)
            @php
                $pctHaikuIn  = round($haikuIn  / $totalTokens * 100, 1);
                $pctHaikuOut = round($haikuOut / $totalTokens * 100, 1);
                $pctSonIn    = round($sonnetIn  / $totalTokens * 100, 1);
                $pctSonOut   = round($sonnetOut / $totalTokens * 100, 1);
            @endphp
            <div class="card mb-4 border-0 shadow-sm">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <strong class="small">Phân bổ token</strong>
                        <span class="small text-muted">{{ number_format($totalTokens) }} tokens tổng</span>
                    </div>
                    <div class="progress" style="height:20px; border-radius:6px;">
                        <div class="progress-bar bg-info"       style="width:{{ $pctHaikuIn  }}%" title="Haiku Input: {{ number_format($haikuIn) }}"></div>
                        <div class="progress-bar bg-primary"    style="width:{{ $pctHaikuOut }}%" title="Haiku Output: {{ number_format($haikuOut) }}"></div>
                        <div class="progress-bar bg-warning"    style="width:{{ $pctSonIn   }}%" title="Sonnet Input: {{ number_format($sonnetIn) }}"></div>
                        <div class="progress-bar bg-danger"     style="width:{{ $pctSonOut  }}%" title="Sonnet Output: {{ number_format($sonnetOut) }}"></div>
                    </div>
                    <div class="d-flex flex-wrap mt-2" style="gap:12px; font-size:12px;">
                        <span><span class="badge badge-info mr-1">&nbsp;</span> Haiku In {{ number_format($haikuIn) }} ({{ $pctHaikuIn }}%)</span>
                        <span><span class="badge badge-primary mr-1">&nbsp;</span> Haiku Out {{ number_format($haikuOut) }} ({{ $pctHaikuOut }}%)</span>
                        <span><span class="badge badge-warning mr-1">&nbsp;</span> Sonnet In {{ number_format($sonnetIn) }} ({{ $pctSonIn }}%)</span>
                        <span><span class="badge badge-danger mr-1">&nbsp;</span> Sonnet Out {{ number_format($sonnetOut) }} ({{ $pctSonOut }}%)</span>
                    </div>
                </div>
            </div>
            @endif

            {{-- Article detail — chỉ hiện khi lọc theo ngày cụ thể --}}
            @if($date && $articleDetails->isNotEmpty())
            <div class="card mb-4 border-0 shadow-sm">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
                    <strong>Chi tiết từng bài — {{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}</strong>
                    <span class="badge badge-secondary">{{ $articleDetails->count() }} bài</span>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th style="width:80px">Giờ</th>
                                <th>Bài viết</th>
                                <th class="text-right" style="width:100px">Tokens</th>
                                <th class="text-right" style="width:110px">Chi phí</th>
                                <th class="text-right" style="width:60px">Tốc độ</th>
                                <th class="text-center" style="width:60px">Retry</th>
                                <th class="text-center" style="width:60px">Review</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($articleDetails as $m)
                            @php
                                $tokens = $m->haiku_input_tokens + $m->haiku_output_tokens
                                        + $m->sonnet_input_tokens + $m->sonnet_output_tokens;
                            @endphp
                            <tr>
                                <td class="text-muted small">{{ $m->created_at->setTimezone('Asia/Ho_Chi_Minh')->format('H:i:s') }}</td>
                                <td>
                                    @if($m->article)
                                        <a href="{{ route('post.update', $m->article->id) }}" target="_blank"
                                           class="text-truncate d-inline-block" style="max-width:380px"
                                           title="{{ $m->article->title }}">
                                            {{ $m->article->title }}
                                        </a>
                                    @else
                                        <span class="text-muted">— bài đã xoá —</span>
                                    @endif
                                </td>
                                <td class="text-right text-muted small">{{ number_format($tokens) }}</td>
                                <td class="text-right font-weight-bold small">
                                    {{ number_format($m->total_cost_usd * 25000, 0, ',', '.') }} đ
                                    <div class="text-muted" style="font-size:11px; font-weight:normal;">${{ number_format($m->total_cost_usd, 5) }}</div>
                                </td>
                                <td class="text-right text-muted small">
                                    {{ $m->processing_time_ms ? round($m->processing_time_ms / 1000, 1) . 's' : '—' }}
                                </td>
                                <td class="text-center">
                                    @if($m->retry_count > 0)
                                        <span class="badge badge-warning">{{ $m->retry_count }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if($m->needs_review)
                                        <span class="badge badge-danger">Review</span>
                                    @else
                                        <span class="text-success small">✓</span>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="table-active">
                            <tr>
                                <td colspan="2"><strong>Tổng ngày {{ \Carbon\Carbon::parse($date)->format('d/m') }}</strong></td>
                                <td class="text-right font-weight-bold">{{ number_format($totalTokens) }}</td>
                                <td class="text-right font-weight-bold">
                                    {{ number_format($totalCost * 25000, 0, ',', '.') }} đ
                                    <div class="text-muted" style="font-size:11px; font-weight:normal;">${{ number_format($totalCost, 4) }}</div>
                                </td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            @elseif($date && $articleDetails->isEmpty() && $logs->isEmpty())
            <div class="alert alert-warning">Không có dữ liệu nào cho ngày {{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}.</div>
            @endif




            {{-- Detail Log --}}
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom"><strong>Log chi tiết (thủ công)</strong></div>
                <div class="card-body p-0">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th style="width:130px">Thời gian</th>
                                <th style="width:110px">Member</th>
                                <th style="width:85px">Action</th>
                                <th>Bài viết</th>
                                <th style="width:110px" class="text-right">Chi phí</th>
                                <th>URL nguồn</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($logs as $log)
                            <tr>
                                <td class="text-muted small">{{ $log->created_at->setTimezone('Asia/Ho_Chi_Minh')->format('d/m H:i:s') }}</td>
                                <td>{{ $adminMap[$log->admin_id]->name ?? $log->admin_id }}</td>
                                <td>
                                    @if($log->action === 'synthesize')
                                        <span class="badge badge-info">Tổng hợp</span>
                                    @else
                                        <span class="badge badge-success">Send</span>
                                    @endif
                                </td>
                                <td>{{ $log->title }}</td>
                                <td class="text-right small">
                                    @if($log->total_cost_usd > 0)
                                        <span class="font-weight-bold">{{ number_format($log->total_cost_usd * 25000, 0, ',', '.') }} đ</span>
                                        <div class="text-muted" style="font-size:11px;">${{ number_format($log->total_cost_usd, 5) }}</div>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if($log->source_url)
                                        <a href="{{ $log->source_url }}" target="_blank" class="text-truncate d-inline-block" style="max-width:240px" title="{{ $log->source_url }}">
                                            {{ $log->source_url }}
                                        </a>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="6" class="text-center text-muted py-3">Không có dữ liệu</td></tr>
                            @endforelse
                        </tbody>
                        @php $logTotalCost = $logs->sum('total_cost_usd'); @endphp
                        @if($logTotalCost > 0)
                        <tfoot class="table-active font-weight-bold">
                            <tr>
                                <td colspan="4">Tổng {{ $logs->count() }} lần</td>
                                <td class="text-right">
                                    {{ number_format($logTotalCost * 25000, 0, ',', '.') }} đ
                                    <div class="text-muted" style="font-size:11px; font-weight:normal;">${{ number_format($logTotalCost, 5) }}</div>
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                        @endif
                    </table>
                </div>
                @if($logs->hasPages())
                <div class="card-footer bg-white">{{ $logs->links() }}</div>
                @endif
            </div>

        </div>
    </div>
</div>
@endsection
