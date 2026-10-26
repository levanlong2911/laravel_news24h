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

    <div class="va-page">
        <div>
            <h1>Proxy 3D</h1>
            <p>Phase 3P — mô hình hình học dựng từ thiết kế, đối chiếu với model sheet đã duyệt</p>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            @if($proxy['approved_id'] !== null)
                <span class="va-tag ok">ĐÃ DUYỆT PROXY</span>
            @elseif($proxy['approved_problem'] !== null)
                <span class="va-tag dg" title="{{ $proxy['approved_problem'] }}">PROXY ĐÃ DUYỆT HẾT HIỆU LỰC</span>
            @endif
            <a class="vp-btn" href="{{ route('video-projects.anchor', $project->id) }}#modelSheetPanel">← Model sheet</a>
        </div>
    </div>

    <div class="vp-panel">
        <div class="va-head">
            <b>NGUỒN</b>
            <em>{{ $proxy['design_name'] ?? '—' }}@if($proxy['design_stage_id']) · thiết kế {{ substr($proxy['design_stage_id'], 0, 8) }}@endif</em>
        </div>
        @foreach(array_filter([$proxy['source_error'], $proxy['approved_problem']]) as $problem)
            <div class="va-body"><div class="va-brief"><div class="line"><span class="v" style="color:var(--vp-red)">{{ $problem }}</span></div></div></div>
        @endforeach
    </div>

    @php
        $ps = $proxy['proxy_spec'];
        $spec = $ps['spec'];
        $gaps = collect($spec['gaps'] ?? []);
        $pending = collect($spec['assumptions'] ?? [])->filter(fn (array $a) => $a['requires_confirmation'] && ! $a['confirmed']);
        $confirmed = collect($spec['assumptions'] ?? [])->filter(fn (array $a) => $a['requires_confirmation'] && $a['confirmed']);
    @endphp
    <div class="vp-panel">
        <div class="va-head">
            <b>PROXYSPEC</b>
            <em>biên dịch từ nguồn đóng băng của master đã duyệt + mapping của revision gần nhất — chỉ đọc</em>
            <span class="grow"></span>
            @if($spec !== null)
                <span class="va-tag {{ $spec['ready'] ? 'ok' : 'amber' }}">{{ $spec['ready'] ? 'Đủ điều kiện dựng' : 'Chưa đủ: '.$gaps->where('severity', 'blocking')->count().' chỗ chặn' }}</span>
                <span class="va-lbl" style="font-weight:400">{{ $spec['version'] }} · {{ substr($spec['spec_sha256'], 0, 12) }}</span>
                @if($spec['ready'])
                    <button type="button" class="vp-btn sm" data-proxy-preview data-tolerances='@json(config('video.superyacht.proxy.tolerances'))' data-fill="{{ config('video.superyacht.proxy.fill') }}" title="Chạy bộ dựng chung trong trình duyệt — chỉ xem, không lưu">Xem trước</button>
                @endif
            @endif
        </div>
        <div class="va-body">
            @if($spec !== null && $spec['ready'])
                <script type="application/json" id="proxySpecData">@json($spec)</script>
            @endif
            <div class="va-brief">
                @if($ps['error'] !== null)
                    <div class="line"><span class="v" style="color:var(--vp-red)">{{ $ps['error'] }}</span></div>
                @else
                    @php $src = $spec['source']; @endphp
                    <div class="line"><span class="k">Nguồn</span><span class="v">master {{ substr($src['master_image_id'], 0, 8) }} · QA {{ $src['qa_stage_id'] ? substr($src['qa_stage_id'], 0, 8) : '—' }} · xác nhận prompt {{ $src['approval_stage_id'] ? substr($src['approval_stage_id'], 0, 8) : '—' }} · thiết kế {{ substr($src['design_stage_id'], 0, 8) }} · canonical {{ substr(str_replace('sha256:', '', $src['canonical_hash']), 0, 12) }} · {{ count($src['crops']) }} ô</span></div>
                    <div class="line"><span class="k">Hình khối</span><span class="v">{{ count($spec['geometry']['hull']['profile']) }} điểm profile · {{ count($spec['geometry']['hull']['plan_port']) }} điểm mạn trái ({{ $spec['geometry']['hull']['symmetry'] === 'design' ? 'đối xứng theo dữ liệu' : $spec['geometry']['hull']['symmetry'] }}) · {{ $spec['geometry']['deck_count'] }} boong · {{ count($spec['geometry']['parts']) }} bộ phận</span></div>
                    @foreach($ps['errors'] as $message)
                        <div class="line"><span class="v" style="color:var(--vp-red)">{{ $message }}</span></div>
                    @endforeach
                @endif
            </div>
            @if($spec !== null)
                <table class="vp-proxy-table">
                    <thead><tr><th>Mã</th><th>Phép dựng</th><th>Đổi mới</th><th>Build state</th><th>Tham số (m)</th></tr></thead>
                    <tbody>
                        @foreach($spec['geometry']['parts'] as $part)
                            <tr>
                                <td><b>{{ $part['id'] }}</b></td>
                                <td>{{ $part['op'] }}</td>
                                <td>{{ $part['innovation'] ?? '' }}</td>
                                <td>{{ $part['registry'] }}</td>
                                <td>{{ collect($part['params'])->map(fn (array $p, string $k) => $k.' '.(is_array($p['value']) ? implode(' ; ', $p['value']) : ($p['value'] ?? '—')).($p['basis'] === 'design' ? '' : ' ('.$p['basis'].')'))->implode(' · ') }}@if($part['instances'] !== []) · tại {{ collect($part['instances'])->pluck('landmark')->implode(', ') }}@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @php $missing = $gaps->where('severity', 'blocking')->where('scope', '!=', 'assumption'); @endphp
                @if($missing->isNotEmpty())
                    <div class="va-lbl" style="margin-top:8px">Chỗ thiếu chặn ({{ $missing->count() }})</div>
                    @foreach($missing as $gap)
                        <div class="va-lbl" style="font-weight:400;color:var(--vp-red)">{{ $gap['subject'] }} — {{ $gap['message'] }}</div>
                    @endforeach
                @endif
                @if($gaps->where('severity', 'warning')->isNotEmpty())
                    <details style="margin-top:8px">
                        <summary class="va-lbl">Cảnh báo ({{ $gaps->where('severity', 'warning')->count() }})</summary>
                        @foreach($gaps->where('severity', 'warning') as $gap)
                            <div class="va-lbl" style="font-weight:400;color:var(--vp-amber-fg)">{{ $gap['subject'] }} — {{ $gap['message'] }}</div>
                        @endforeach
                    </details>
                @endif
            @endif
        </div>
    </div>

    @if($spec !== null)
        <div class="vp-panel" id="proxyMapping">
            <div class="va-head"><b>MAPPING &amp; XÁC NHẬN GIẢ ĐỊNH</b><em>phép dựng cho chi tiết nhận dạng; giá trị không có trong thiết kế phải ghi lý do và được xác nhận</em></div>
            <div class="va-body">
                <form method="POST" action="{{ route('video-projects.proxy-build', $project->id) }}">
                    @csrf
                    <div class="va-lbl" style="font-weight:400">JSON {"parts": [...], "unused": {"mốc": "lý do"}} — phép dựng: {{ implode(', ', array_keys(\App\Video\Proxy\ProxySpecCompiler::OPS)) }}. Tham số: {"landmark", "axis"} · {"fact"} (+ "pick" với số trích dẫn) · {"range", "bound"} · {"code"} · {"value", "unit", "note"}.</div>
                    <textarea name="mapping" class="vp-proxy-json" rows="14" spellcheck="false">{{ old('mapping', json_encode(['parts' => $proxy['mapping']['parts'] ?? [], 'unused' => (object) ($proxy['mapping']['unused'] ?? [])], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) }}</textarea>
                    @if($pending->isNotEmpty())
                        <div class="va-lbl" style="margin-top:8px">Giả định chờ xác nhận ({{ $pending->count() }}) — tick để xác nhận đúng giá trị đang hiển thị</div>
                        @foreach($pending as $assumption)
                            <label class="vp-proxy-check" style="display:flex;font-size:.74rem">
                                <input type="checkbox" name="confirm[{{ $assumption['id'] }}]" value="{{ json_encode($assumption['value']) }}">
                                <span><b>{{ $assumption['id'] }}</b> — {{ $assumption['label'] }}</span>
                            </label>
                        @endforeach
                    @endif
                    @if($confirmed->isNotEmpty())
                        <details style="margin-top:6px">
                            <summary class="va-lbl">Đã xác nhận ({{ $confirmed->count() }})</summary>
                            @foreach($confirmed as $assumption)
                                <div class="va-lbl" style="font-weight:400">✓ {{ $assumption['id'] }} — {{ $assumption['label'] }}</div>
                            @endforeach
                        </details>
                    @endif
                    <div class="vp-proxy-bar">
                        <span class="va-lbl" style="font-weight:400">Mỗi lần lưu là một revision; ProxySpec đủ điều kiện thì worker Node dựng, xuất GLB và ảnh kiểm (vài giây).</span>
                        <button class="vp-btn pri">Lưu mapping &amp; dựng</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="vp-panel" id="proxyReferences">
            <div class="va-head"><b>THAM CHIẾU MODEL SHEET</b><em>mask tàu và mốc trên từng ô của master đã duyệt — chỉ dùng khi đã xác nhận; MS8 chỉ đánh dấu mốc</em></div>
            <div class="va-body">
                @php
                    $landmarkNames = collect($spec['geometry']['landmarks'])->filter(fn ($l) => $l['X'] !== null && $l['z'] !== null)->pluck('name')->values();
                    $requiredMarks = \App\Video\Proxy\ProxyVerdict::requiredLandmarks($spec);
                    $specCameras = collect($spec['cameras'])->keyBy('view');
                    $latestRevision = $proxy['revisions'][0] ?? null;
                @endphp
                <div class="vp-ref-grid">
                    @foreach($proxy['references'] as $view => $ref)
                        <div class="vp-ref-card" id="proxyRef{{ $view }}" data-ref-card data-view="{{ $view }}" data-width="{{ $ref['crop']['width'] }}" data-height="{{ $ref['crop']['height'] }}" data-landmarks='@json($ref['landmarks']['points'] ?? [])'>
                            <div class="vp-proxy-bar">
                                <b>{{ $view }}</b>
                                @if($ref['mask'] !== null)
                                    <span class="va-tag ok" title="{{ $ref['mask']['meta']['confirmed_by'] ?? '' }}">Mask đã xác nhận</span>
                                @elseif(in_array($view, config('video.superyacht.proxy.iou_views'), true))
                                    <span class="va-tag amber">Chưa có mask</span>
                                @endif
                                <span class="va-tag {{ $ref['landmarks'] ? 'ok' : 'mute' }}">{{ $ref['landmarks'] ? count($ref['landmarks']['points']).' mốc' : 'Chưa có mốc' }}</span>
                                @php
                                    $cam = $specCameras[$view] ?? null;
                                    $camOk = $cam !== null && ($ref['camera']['camera_sha256'] ?? null) === \App\Video\Proxy\ProxyVerdict::cameraSha($cam);
                                @endphp
                                <span class="va-tag {{ $camOk ? 'ok' : 'amber' }}">{{ $camOk ? 'Camera đã xác nhận' : 'Camera danh nghĩa' }}</span>
                            </div>
                            @if($cam !== null)
                                <form method="POST" action="{{ route('video-projects.proxy-reference', [$project->id, 'camera']) }}" class="vp-proxy-bar">
                                    @csrf
                                    <input type="hidden" name="view" value="{{ $view }}">
                                    <span class="va-lbl" style="font-weight:400">{{ $cam['projection'] }} · phương vị {{ $cam['azimuth_deg'] }}°@if($cam['elevation_deg'] !== null) · nhìn xuống {{ $cam['elevation_deg'] }}°@endif @if($cam['eye_height_m'] !== null) · mắt {{ $cam['eye_height_m'] }} m @endif @if($cam['focal_mm'] !== null) · {{ $cam['focal_mm'] }} mm @endif
                                        @if(isset($latestRevision['artifacts']['overlay-'.$view.'.png'])) · <a href="{{ route('video-artifacts.show', $latestRevision['artifacts']['overlay-'.$view.'.png']['artifact_id']) }}" target="_blank" rel="noopener">ảnh chồng rev {{ $latestRevision['revision'] }}</a>@elseif(isset($latestRevision['artifacts']['clay-'.$view.'.png'])) · <a href="{{ route('video-artifacts.show', $latestRevision['artifacts']['clay-'.$view.'.png']['artifact_id']) }}" target="_blank" rel="noopener">clay rev {{ $latestRevision['revision'] }}</a>@endif
                                    </span>
                                    @unless($camOk)
                                        <button class="vp-btn sm" title="Xác nhận góc, tiêu cự, khung của camera này khớp ô model sheet (đối chiếu ảnh clay/ảnh chồng)">Xác nhận camera</button>
                                    @endunless
                                </form>
                            @endif
                            @php
                                $inFrame = collect($latestRevision['projected'][$view] ?? [])->where('in_frame', true)->pluck('name');
                                $needed = $inFrame->intersect($view === 'MS8' ? $requiredMarks['ms8'] : $requiredMarks['all'])->values();
                            @endphp
                            @if($needed->isNotEmpty())
                                <div class="va-lbl" style="font-weight:400">Mốc cần đo hoặc giải trình (trong khung): {{ $needed->implode(', ') }}</div>
                            @endif
                            <div class="vp-ref-img" data-ref-stage>
                                <img src="{{ $ref['crop']['url'] }}" alt="{{ $view }}" draggable="false">
                                @if($ref['proposal'] !== null && ($ref['mask'] === null || $ref['mask']['meta']['proposal_artifact_id'] !== $ref['proposal']['id']))
                                    <img class="mask proposal" src="{{ $ref['proposal']['url'] }}" alt="mask đề xuất">
                                @elseif($ref['mask'] !== null)
                                    <img class="mask" src="{{ $ref['mask']['url'] }}" alt="mask đã xác nhận">
                                @endif
                                <canvas data-ref-canvas></canvas>
                            </div>
                            @if(in_array($view, config('video.superyacht.proxy.iou_views'), true))
                                <div class="vp-proxy-bar">
                                    <form method="POST" action="{{ route('video-projects.proxy-reference', [$project->id, 'propose']) }}" class="vp-proxy-group">
                                        @csrf
                                        <input type="hidden" name="view" value="{{ $view }}">
                                        <input type="number" name="tolerance" min="1" max="60" value="{{ $ref['proposal']['meta']['tolerance'] ?? config('video.superyacht.proxy.reference_tolerance') }}" class="vp-proxy-num" title="Ngưỡng bước màu">
                                        <button class="vp-btn sm">Đề xuất mask</button>
                                    </form>
                                    <form method="POST" action="{{ route('video-projects.proxy-reference', [$project->id, 'polygon']) }}" class="vp-proxy-group" data-polygon-form>
                                        @csrf
                                        <input type="hidden" name="view" value="{{ $view }}">
                                        <input type="hidden" name="points" value="[]">
                                        <button type="button" class="vp-btn sm" data-mode="polygon">Khoanh đa giác</button>
                                        <button class="vp-btn sm" data-polygon-save hidden>Tạo mask từ đa giác</button>
                                    </form>
                                    @if($ref['proposal'] !== null && ($ref['mask'] === null || $ref['mask']['meta']['proposal_artifact_id'] !== $ref['proposal']['id']))
                                        <form method="POST" action="{{ route('video-projects.proxy-reference', [$project->id, 'confirm']) }}">
                                            @csrf
                                            <input type="hidden" name="view" value="{{ $view }}">
                                            <input type="hidden" name="proposal_id" value="{{ $ref['proposal']['id'] }}">
                                            <button class="vp-btn sm ok">✓ Xác nhận mask ({{ ($ref['proposal']['meta']['method'] ?? '') === 'polygon' ? 'đa giác' : 'ngưỡng '.($ref['proposal']['meta']['tolerance'] ?? '') }})</button>
                                        </form>
                                    @endif
                                </div>
                            @endif
                            <form method="POST" action="{{ route('video-projects.proxy-reference', [$project->id, 'landmarks']) }}" class="vp-proxy-bar" data-landmark-form>
                                @csrf
                                <input type="hidden" name="view" value="{{ $view }}">
                                <input type="hidden" name="points" value='@json($ref['landmarks']['points'] ?? [])'>
                                <div class="vp-proxy-group">
                                    <select data-landmark-name class="vp-proxy-select">
                                        @foreach($landmarkNames as $name)
                                            <option value="{{ $name }}">{{ $name }}</option>
                                        @endforeach
                                    </select>
                                    <button type="button" class="vp-btn sm" data-mode="landmark">Đặt mốc (bấm ảnh)</button>
                                    <button type="button" class="vp-btn sm" data-status="occluded">Bị che</button>
                                    <button type="button" class="vp-btn sm" data-status="out_of_frame">Ngoài khung</button>
                                    <button type="button" class="vp-btn sm" data-remove>Bỏ mốc</button>
                                </div>
                                <button class="vp-btn sm pri">Lưu mốc</button>
                                <div class="va-lbl" style="font-weight:400;width:100%" data-landmark-list></div>
                            </form>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <div class="vp-panel" id="proxyViewerPanel" hidden>
        <div class="va-head"><b>XEM 3D</b><em id="proxyViewerTitle"></em></div>
        <div class="va-body">
            <div class="vp-proxy-bar">
                <div class="vp-proxy-group" id="proxyViewButtons"></div>
                <div class="vp-proxy-group">
                    <select id="proxyStateSelect" class="vp-proxy-select"></select>
                </div>
            </div>
            <div id="proxyViewer" class="vp-proxy-stage"></div>
        </div>
    </div>

    <div class="vp-panel">
        <div class="va-head"><b>REVISION</b><em>mỗi lần lưu mapping là một revision; chỉ revision dựng từ nguồn, code và tham chiếu hiện hành mới duyệt được</em></div>
        <div class="va-body">
            @forelse($proxy['revisions'] as $revision)
                <div class="vp-proxy-rev">
                    <div class="vp-proxy-bar">
                        <div class="vp-proxy-group">
                            <b>Revision {{ $revision['revision'] }}</b>
                            <span class="va-lbl" style="font-weight:400">{{ $revision['created_at']?->format('Y-m-d H:i:s') }} · {{ $revision['created_by'] ?: '—' }}</span>
                            @if($revision['approved'])
                                <span class="va-tag ok">Đã duyệt</span>
                            @endif
                            <span class="va-tag {{ $revision['status'] === 'passed' ? 'ok' : ($revision['status'] === 'awaiting_reference' ? 'amber' : 'dg') }}">{{ \App\Services\Video\YachtProxyService::STATUS_LABELS[$revision['status']] ?? $revision['status'] }}</span>
                            @if(! $revision['current'])
                                <span class="va-tag mute" title="{{ implode(', ', $revision['stale']) }}">Nguồn/code cũ</span>
                            @endif
                        </div>
                        <div class="vp-proxy-group">
                            @if(isset($revision['artifacts']['proxy.glb']))
                                <button type="button" class="vp-btn sm" data-proxy-open
                                    data-title="Revision {{ $revision['revision'] }}"
                                    data-glb="{{ route('video-artifacts.show', $revision['artifacts']['proxy.glb']['artifact_id']) }}"
                                    data-cameras='@json($revision['cameras'])'
                                    data-states='@json(collect($revision['states'])->mapWithKeys(fn ($s) => [$s['id'] => isset($revision['artifacts'][$s['file']]) ? route('video-artifacts.show', $revision['artifacts'][$s['file']]['artifact_id']) : null]))'>Xem 3D</button>
                                <a class="vp-btn sm" href="{{ route('video-artifacts.show', $revision['artifacts']['proxy.glb']['artifact_id']) }}" download="proxy-r{{ $revision['revision'] }}.glb">Tải .glb</a>
                            @endif
                            @if($revision['current'] && ! $revision['approved'] && $revision['status'] === 'passed')
                                <form method="POST" action="{{ route('video-projects.proxy-approve', $project->id) }}">
                                    @csrf
                                    <input type="hidden" name="stage_id" value="{{ $revision['id'] }}">
                                    <button class="vp-btn ok">✓ Duyệt proxy</button>
                                </form>
                            @endif
                        </div>
                    </div>
                    @foreach(['failed' => 'var(--vp-red)', 'pending' => 'var(--vp-amber-fg)'] as $key => $color)
                        @foreach($revision['verdict'][$key] ?? [] as $line)
                            <div class="va-lbl" style="font-weight:400;color:{{ $color }}">{{ $line }}</div>
                        @endforeach
                    @endforeach
                    @if($revision['comparison'] !== [])
                        <div class="va-lbl" style="font-weight:400">Đối chiếu: {{ collect($revision['comparison'])->map(fn ($c, $v) => $v.' '.($c['status'] === 'compared' ? 'IoU '.$c['iou'] : $c['status']))->implode(' · ') }}</div>
                    @endif
                    @if($revision['checks'] !== [])
                        <details>
                            <summary class="va-lbl">Bảng kiểm geometry ({{ collect($revision['checks'])->where('pass', true)->count() }}/{{ count($revision['checks']) }} đạt)</summary>
                            <table class="vp-proxy-table">
                                <thead><tr><th>Hạng mục</th><th>Thiết kế</th><th>Proxy</th><th>Ngưỡng</th><th></th></tr></thead>
                                <tbody>
                                    @foreach($revision['checks'] as $check)
                                        <tr>
                                            <td>{{ $check['label'] }}</td>
                                            <td>{{ $check['design'] }}</td>
                                            <td>{{ $check['measured'] }}</td>
                                            <td>{{ $check['tolerance'] }}</td>
                                            <td><span class="va-tag {{ $check['pass'] ? 'ok' : 'dg' }}">{{ $check['pass'] ? 'Đạt' : 'Lỗi' }}</span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </details>
                    @endif
                    @php $thumbs = collect($revision['artifacts'])->filter(fn ($a) => in_array($a['kind'], ['clay', 'parts', 'mask', 'overlay'], true))->sortBy(fn ($a) => $a['view_id'].$a['kind']); @endphp
                    @if($thumbs->isNotEmpty())
                        <details>
                            <summary class="va-lbl">Ảnh kiểm ({{ $thumbs->count() }})</summary>
                            <div class="vp-proxy-thumbs">
                                @foreach($thumbs as $artifact)
                                    <a href="{{ route('video-artifacts.show', $artifact['artifact_id']) }}" target="_blank" rel="noopener">
                                        <img src="{{ route('video-artifacts.show', $artifact['artifact_id']) }}" alt="{{ $artifact['view_id'] }} {{ $artifact['kind'] }}" loading="lazy">
                                        <span>{{ $artifact['view_id'] }} · {{ $artifact['kind'] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </details>
                    @endif
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
{ "imports": { "three": "{{ asset('assets/vendor/three/three.module.js') }}", "three/addons/": "{{ asset('assets/vendor/three/addons') }}/" } }
</script>
<script type="module" src="{{ asset('assets/js/proxy/page.js') }}?v={{ filemtime(public_path('assets/js/proxy/page.js')) }}"></script>
@endsection
