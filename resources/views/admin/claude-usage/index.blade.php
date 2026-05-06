@extends('layouts.base', ['title' => 'Claude Usage'])
@section('title', 'Claude Usage')
@section('content')
@php $adminMap = $admins->keyBy('id'); @endphp
<div class="container-fluid">
    <div class="row mb-3">
        <div class="col-12">
            <h4 class="mb-3">Claude Usage</h4>

            {{-- Filter --}}
            <form method="GET" class="form-inline mb-3">
                <input type="month" name="month" value="{{ $month }}" class="form-control mr-2">
                <select name="admin_id" class="form-control mr-2">
                    <option value="">-- Tất cả member --</option>
                    @foreach($admins as $a)
                        <option value="{{ $a->id }}" {{ $adminId == $a->id ? 'selected' : '' }}>{{ $a->name }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-primary">Lọc</button>
            </form>

            {{-- Monthly Summary --}}
            @if($monthlySummary->isNotEmpty())
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>Tổng tháng {{ $month }}</strong>
                    <span class="badge badge-primary badge-lg" style="font-size:1rem">Tổng: {{ number_format($grandTotal) }} lần</span>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>Member</th>
                                <th class="text-right">Số lần gọi Claude</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($monthlySummary as $row)
                            <tr>
                                <td>{{ $adminMap[$row->admin_id]->name ?? $row->admin_id }}</td>
                                <td class="text-right"><strong>{{ number_format($row->total) }}</strong></td>
                            </tr>
                            @endforeach
                            <tr class="table-active font-weight-bold">
                                <td>Tổng cộng</td>
                                <td class="text-right">{{ number_format($grandTotal) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            @endif

            {{-- Daily Summary --}}
            @if($dailySummary->isNotEmpty())
            <div class="card mb-4">
                <div class="card-header"><strong>Tổng hợp theo ngày</strong></div>
                <div class="card-body p-0">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>Ngày</th>
                                <th>Member</th>
                                <th class="text-right">Số lần</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($dailySummary as $day => $rows)
                                @foreach($rows as $row)
                                <tr>
                                    @if($loop->first)
                                        <td rowspan="{{ $rows->count() }}">{{ \Carbon\Carbon::parse($day)->format('d/m/Y') }}</td>
                                    @endif
                                    <td>{{ $adminMap[$row->admin_id]->name ?? $row->admin_id }}</td>
                                    <td class="text-right"><strong>{{ $row->total }}</strong></td>
                                </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @endif

            {{-- Detail Log --}}
            <div class="card">
                <div class="card-header"><strong>Chi tiết từng lần</strong></div>
                <div class="card-body p-0">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th style="width:140px">Thời gian</th>
                                <th style="width:130px">Member</th>
                                <th style="width:100px">Action</th>
                                <th>Bài viết</th>
                                <th>URL nguồn</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($logs as $log)
                            <tr>
                                <td>{{ $log->created_at->format('d/m H:i:s') }}</td>
                                <td>{{ $adminMap[$log->admin_id]->name ?? $log->admin_id }}</td>
                                <td>
                                    @if($log->action === 'synthesize')
                                        <span class="badge badge-info">Tổng hợp</span>
                                    @else
                                        <span class="badge badge-success">Send</span>
                                    @endif
                                </td>
                                <td>{{ $log->title }}</td>
                                <td>
                                    @if($log->source_url)
                                        <a href="{{ $log->source_url }}" target="_blank" class="text-truncate d-inline-block" style="max-width:300px" title="{{ $log->source_url }}">
                                            {{ $log->source_url }}
                                        </a>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="5" class="text-center text-muted py-3">Không có dữ liệu</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($logs->hasPages())
                <div class="card-footer">{{ $logs->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
