@extends('layouts.base', ['title' => 'Proxy 3D'])
@section('title', 'Proxy 3D')

@section('css')
<link rel="stylesheet" href="{{ asset('assets/css/video-producer.css') }}?v={{ filemtime(public_path('assets/css/video-producer.css')) }}">
@endsection

@section('content')
<div class="container-fluid vp" id="proxyPage">

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0 pl-3">
                @foreach($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @php
        $payload = $proxy['payload'];
        $current = collect($proxy['revisions'])->firstWhere('current', true);
    @endphp

    <div class="va-page">
        <div>
            <h1>Proxy 3D</h1>
            <p>Phase 3P — mô hình hình học dựng thẳng từ dữ liệu thiết kế, làm chuẩn cho model sheet</p>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            @if($proxy['approved_id'] !== null)
                <span class="va-tag ok">ĐÃ DUYỆT PROXY</span>
            @elseif($current !== null)
                <span class="va-tag amber">ĐÃ LƯU — CHƯA DUYỆT</span>
            @else
                <span class="va-tag mute">CHƯA LƯU REVISION</span>
            @endif
            <a class="vp-btn" href="{{ route('video-projects.anchor', $project->id) }}">← Thiết kế</a>
            <a class="vp-btn {{ $proxy['approved_id'] !== null ? 'pri' : '' }}" href="{{ route('video-projects.anchor', $project->id) }}#modelSheetPanel">Model sheet →</a>
        </div>
    </div>

    <div class="vp-panel">
        <div class="va-head">
            <b>NGUỒN</b>
            <em>{{ $proxy['design_name'] ?? '—' }}@if($proxy['design_stage_id']) · thiết kế {{ substr($proxy['design_stage_id'], 0, 8) }}@endif</em>
            <span class="grow"></span>
            @if($payload !== null)
                <span class="va-lbl" style="font-weight:400">builder {{ $payload['builder'] }} · dữ liệu {{ substr($payload['input_sha256'], 0, 12) }}</span>
            @endif
        </div>
        @if($proxy['error'] !== null)
            <div class="va-body"><div class="va-brief"><div class="line"><span class="v" style="color:var(--vp-red)">{{ $proxy['error'] }}</span></div></div></div>
        @endif
    </div>

    @if($payload !== null)
        <script type="application/json" id="yachtProxyData">@json($payload)</script>

        <div class="vp-panel">
            <div class="va-head">
                <b>KHUNG XEM</b>
                <em id="proxyStatus">Đang tải…</em>
            </div>
            <div class="va-body">
                <div class="vp-proxy-bar">
                    <div class="vp-proxy-group">
                        <button type="button" class="vp-btn sm pri" data-proxy-view="free">Xoay tự do</button>
                        @foreach(array_keys($payload['views']) as $view)
                            <button type="button" class="vp-btn sm" data-proxy-view="{{ $view }}">{{ $view }}</button>
                        @endforeach
                        <button type="button" class="vp-btn sm" id="proxyAutoRotate" data-proxy-needs-webgl>Tự xoay 360°</button>
                    </div>
                    <div class="vp-proxy-group">
                        <button type="button" class="vp-btn sm pri" data-proxy-mode="clay">Clay</button>
                        <button type="button" class="vp-btn sm" data-proxy-mode="parts">Parts</button>
                        <button type="button" class="vp-btn sm" data-proxy-mode="design">Thiết kế</button>
                        <label class="vp-proxy-check"><input type="checkbox" id="proxyTint" checked> Tô phần giả định</label>
                        <label class="vp-proxy-check"><input type="checkbox" id="proxyHelpers" checked> Đường nước, trục, mốc</label>
                    </div>
                </div>
                <div id="proxyViewer" class="vp-proxy-stage"></div>
                <div id="proxyLegend" class="vp-proxy-legend"></div>
                <div class="vp-proxy-bar">
                    <div class="vp-proxy-group">
                        <button type="button" class="vp-btn sm" id="proxyDownloadView" data-proxy-needs-webgl>Tải ảnh góc đang xem (Clay + Parts + Thiết kế)</button>
                        <button type="button" class="vp-btn sm" id="proxyDownloadAll" data-proxy-needs-webgl>Tải 24 ảnh 8 góc</button>
                        <button type="button" class="vp-btn sm" id="proxyDownloadGlb" data-proxy-needs-webgl>Tải proxy.glb</button>                    </div>
                    <div class="vp-proxy-group">
                        <span class="va-lbl" id="proxySaveStatus" style="font-weight:400"></span>
                        <button type="button" class="vp-btn pri" id="proxySave" data-proxy-needs-webgl data-url="{{ route('video-projects.proxy-save', $project->id) }}">Lưu revision</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="vp-panel">
            <div class="va-head"><b>BẢNG KIỂM</b><em>đo trên chính mô hình vừa dựng</em></div>
            <div class="va-body">
                <table class="vp-proxy-table">
                    <thead><tr><th>Hạng mục</th><th>Thiết kế</th><th>Proxy</th><th>Ngưỡng</th><th></th></tr></thead>
                    <tbody id="proxyReport"></tbody>
                </table>
                <div id="proxyMissing" class="va-lbl" style="color:var(--vp-red);font-weight:400"></div>
            </div>
        </div>

        @php
            $mapped = collect($payload['features'])->filter(fn (array $f) => isset($f['id'], $f['label']));
        @endphp
        @if($mapped->isNotEmpty())
            <div class="vp-panel">
                <div class="va-head"><b>BẢNG ÁNH XẠ CHI TIẾT</b><em>cửa kính và chi tiết ngoài quy từ chữ thiết kế — kích thước, khoảng cách là giả định đã duyệt</em></div>
                <div class="va-body">
                    <table class="vp-proxy-table">
                        <thead><tr><th>Mã</th><th>Chi tiết</th><th>Nguồn trong thiết kế</th><th>Thông số dựng</th></tr></thead>
                        <tbody>
                            @foreach($mapped as $feature)
                                <tr>
                                    <td><b>{{ $feature['id'] }}</b></td>
                                    <td>{{ $feature['label'] }}</td>
                                    <td>{{ $feature['source'] ?? '' }}</td>
                                    <td>{{ collect($feature)->except(['id', 'label', 'source', 'part', 'type'])->map(fn ($v, $k) => $k.': '.(is_array($v) ? implode(', ', $v) : $v))->implode(' · ') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <div class="vp-panel">
            <div class="va-head"><b>GIẢ ĐỊNH</b><em>chưa có trong dữ liệu thiết kế — duyệt proxy là duyệt luôn các giá trị này</em></div>
            <div class="va-body">
                <table class="vp-proxy-table">
                    <thead><tr><th>Mã</th><th>Giả định</th><th>Giá trị đang dùng</th></tr></thead>
                    <tbody>
                        @foreach($payload['assumptions'] as $code => $assumption)
                            <tr>
                                <td><b>{{ $code }}</b></td>
                                <td>{{ $assumption['label'] }}</td>
                                <td>{{ is_array($assumption['value']) ? implode(' ; ', $assumption['value']) : $assumption['value'] }} {{ $assumption['unit'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="vp-panel">
        <div class="va-head"><b>REVISION ĐÃ LƯU</b><em>mỗi lần lưu là một revision; chỉ revision dựng từ dữ liệu hiện hành mới duyệt được</em></div>
        <div class="va-body">
            @forelse($proxy['revisions'] as $revision)
                <div class="vp-proxy-rev">
                    <div class="vp-proxy-bar">
                        <div class="vp-proxy-group">
                            <b>Revision {{ $revision['revision'] }}</b>
                            <span class="va-lbl" style="font-weight:400">{{ $revision['created_at']?->format('Y-m-d H:i:s') }} · {{ $revision['created_by'] ?: '—' }}</span>
                            @if($revision['approved'])
                                <span class="va-tag ok">Đã duyệt</span>
                            @elseif(! $revision['current'])
                                <span class="va-tag mute">Dữ liệu cũ</span>
                            @endif
                            <span class="va-tag {{ $revision['pass'] ? 'ok' : 'dg' }}" title="{{ implode(', ', $revision['failed']) }}">{{ $revision['pass'] ? 'Bảng kiểm đạt (server tính lại)' : 'Bảng kiểm chưa đạt: '.implode(', ', $revision['failed']) }}</span>
                        </div>
                        @if($revision['current'] && ! $revision['approved'])
                            <form method="POST" action="{{ route('video-projects.proxy-approve', $project->id) }}">
                                @csrf
                                <input type="hidden" name="stage_id" value="{{ $revision['id'] }}">
                                <button class="vp-btn ok" @disabled(! $revision['pass']) title="{{ $revision['pass'] ? '' : 'Cần bảng kiểm đạt hết' }}">✓ Duyệt proxy</button>
                            </form>
                        @endif
                    </div>
                    <div class="vp-proxy-thumbs">
                        @foreach(collect($revision['artifacts'])->where('kind', '!==', 'glb') as $artifact)
                            <a href="{{ route('video-artifacts.show', $artifact['artifact_id']) }}" target="_blank" rel="noopener" title="{{ $artifact['view_id'] }} · {{ $artifact['kind'] }}">
                                <img src="{{ route('video-artifacts.show', $artifact['artifact_id']) }}" alt="{{ $artifact['view_id'] }} {{ $artifact['kind'] }}" loading="lazy">
                                <span>{{ $artifact['view_id'] }} · {{ $artifact['kind'] }}</span>
                            </a>
                        @endforeach
                        @foreach(collect($revision['artifacts'])->where('kind', 'glb') as $artifact)
                            <a class="vp-btn sm" href="{{ route('video-artifacts.show', $artifact['artifact_id']) }}" download="proxy-r{{ $revision['revision'] }}.glb">Tải .glb</a>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">Chưa có revision nào.</div>
            @endforelse
        </div>
    </div>
</div>
@endsection

@section('script')
<script type="importmap">
{ "imports": { "three": "{{ asset('assets/vendor/three/three.module.js') }}", "three/addons/": "{{ asset('assets/vendor/three/addons') }}/", "yacht-proxy": "{{ asset('assets/js/yacht-proxy.js') }}?v={{ filemtime(public_path('assets/js/yacht-proxy.js')) }}" } }
</script>
<script type="module" src="{{ asset('assets/js/proxy-viewer.js') }}?v={{ filemtime(public_path('assets/js/proxy-viewer.js')) }}"></script>
@endsection
