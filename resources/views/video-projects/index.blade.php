@extends('layouts.base', ['title' => 'Video Projects'])
@section('title', 'Video Projects')

@section('css')
<link rel="stylesheet" href="{{ asset('assets/css/video-session.css') }}">
@endsection

@section('content')
@php
    $rows = collect($projects);
    // Đếm MỘT lượt rồi tra bảng, thay vì mỗi chip một lượt duyệt collection.
    $byStatus = $rows->countBy(fn ($p) => $p->latest_status ?? 'none');
    $categories = $rows->map(fn ($p) => $p->article?->category?->name)->filter()->unique()->sort()->values();
@endphp

<div class="container-fluid vs-page">

    <div class="vs-card">
    <div class="vs-toolbar">
        <div class="vs-filters">
            <button class="on" data-filter="all">All ({{ $rows->count() }})</button>
            {{-- Duyệt thẳng case của enum: thêm trạng thái mới là chip tự có, không phải nhớ sửa view. --}}
            @foreach(\App\Enums\VideoSessionStatus::cases() as $case)
                @if($byStatus->get($case->value, 0))<button data-filter="{{ $case->value }}">{{ $case->label() }} ({{ $byStatus[$case->value] }})</button>@endif
            @endforeach
            {{-- Dự án chưa có session nào: màn cũ xoay quanh session nên không bao giờ thấy chúng. --}}
            @if($byStatus->get('none', 0))<button data-filter="none">Not started ({{ $byStatus['none'] }})</button>@endif
        </div>
    </div>
    <div class="vs-controls">
        <input type="search" id="vsQuery" placeholder="Search by project title…" autocomplete="off">
        <select id="vsCategory">
            <option value="">All categories</option>
            @foreach($categories as $name)
                <option value="{{ \Illuminate\Support\Str::lower($name) }}">{{ $name }}</option>
            @endforeach
        </select>
        <button type="button" class="vs-reset" id="vsReset">Clear filters</button>
    </div>

    <div class="vs-scroll">
    <table class="vs-table">
        <thead>
            <tr>
                <th>Project</th>
                <th>Category</th>
                <th>Created by</th>
                <th>Status</th>
                <th class="vs-num">Runs</th>
                <th class="vs-num">Assets</th>
                <th class="vs-num">Cost</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        @forelse($rows as $p)
            @php($category = $p->article?->category?->name)
            @php($status = \App\Enums\VideoSessionStatus::tryFrom($p->latest_status ?? ''))
            <tr data-status="{{ $p->latest_status ?? 'none' }}"
                data-category="{{ \Illuminate\Support\Str::lower($category ?? '') }}"
                data-search="{{ \Illuminate\Support\Str::lower($p->title) }}">
                <td>
                    <a class="vs-name" href="{{ route('video-projects.anchor', $p->id) }}">{{ $p->title }}</a>
                </td>
                <td>@if($category)<span class="vs-tag">{{ $category }}</span>@else<span class="vs-code">—</span>@endif</td>
                <td>
                    {{-- Vắng admin là dữ liệu cũ tạo trước khi có cột `admin_id`, không phải Python đẩy về. --}}
                    @if($p->admin)
                        <span class="vs-by">{{ $p->admin->name }}</span>
                    @else
                        <span class="vs-code">—</span>
                    @endif
                    <span class="vs-code">{{ $p->created_at?->format('d/m H:i') }}</span>
                </td>
                {{-- tryFrom: giá trị lạ trong DB rơi về chuỗi thô, không ném lỗi làm chết trang. --}}
                <td>
                    @if($p->latest_status === null)
                        <span class="vs-pill vs-draft">Not started</span>
                    @else
                        <span class="vs-pill vs-{{ $status ? $p->latest_status : 'draft' }}">{{ $status?->label() ?? $p->latest_status }}</span>
                    @endif
                </td>
                <td class="vs-num">{{ $p->sessions_count }}</td>
                <td class="vs-num">{{ $p->approved_assets_count }}</td>
                <td class="vs-num">${{ number_format((float) $p->cost_actual_sum, 2) }}</td>
                <td><a class="btn btn-sm btn-primary" href="{{ route('video-projects.anchor', $p->id) }}">Open</a></td>
            </tr>
        @empty
            <tr><td colspan="8" class="vs-empty">No projects yet — click 🎬 on an article to open one.</td></tr>
        @endforelse
        <tr id="vsNoMatch" style="display:none"><td colspan="8" class="vs-empty">No projects match the current filters.</td></tr>
        </tbody>
    </table>
    </div>{{-- .vs-scroll --}}
    </div>{{-- .vs-card --}}
</div>
@endsection

@section('script')
<script>
(function () {
    var query = document.getElementById('vsQuery');
    var category = document.getElementById('vsCategory');
    var reset = document.getElementById('vsReset');
    var noMatch = document.getElementById('vsNoMatch');
    var chips = document.querySelectorAll('.vs-filters button');
    var rows = document.querySelectorAll('.vs-table tbody tr[data-status]');

    // Ba bộ lọc phải AND với nhau trong MỘT hàm. Mỗi handler tự set display sẽ
    // xoá kết quả của handler kia — lọc trạng thái rồi gõ tìm là mất chip.
    function apply() {
        var text = query.value.trim().toLowerCase();
        var cat = category.value;
        var status = (document.querySelector('.vs-filters button.on') || {}).dataset;
        status = status ? status.filter : 'all';
        var shown = 0;

        rows.forEach(function (tr) {
            var ok = (status === 'all' || tr.dataset.status === status)
                && (cat === '' || tr.dataset.category === cat)
                && (text === '' || tr.dataset.search.indexOf(text) !== -1);
            tr.style.display = ok ? '' : 'none';
            if (ok) { shown++; }
        });

        noMatch.style.display = shown ? 'none' : '';
    }

    chips.forEach(function (btn) {
        btn.addEventListener('click', function () {
            chips.forEach(function (b) { b.classList.toggle('on', b === btn); });
            apply();
        });
    });

    query.addEventListener('input', apply);
    category.addEventListener('change', apply);
    reset.addEventListener('click', function () {
        query.value = '';
        category.value = '';
        chips.forEach(function (b) { b.classList.toggle('on', b.dataset.filter === 'all'); });
        apply();
    });
})();
</script>
@endsection
