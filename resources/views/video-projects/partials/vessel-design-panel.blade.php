            @php
                $yachtCheck = $yacht['check'];
                $yachtKept = $yacht['locked'];
            @endphp
            <div class="vp-panel" id="designPanel" data-ajax-part>
                <div class="va-head">
                    <span class="n">4</span>
                    <b>DESIGN</b>
                    <em>Phase 3 — hồ sơ thiết kế (draft)</em>
                    <span class="grow"></span>
                    @if($yachtKept)<span class="va-tag ok">Đã duyệt (model sheet)</span>@elseif($yacht['analysed'])<span class="va-tag blue">Đã thiết kế</span>@endif
                    @if($yachtKept || ($yacht['analysed'] && ! $yacht['dna_stale'] && ($yachtCheck['verdict'] ?? null) === \App\Video\YachtDesign\YachtDesignCheck::PASS))
                        <a class="vp-btn sm" href="#modelSheetPanel">Model sheet →</a>
                    @endif
                    @if($yacht['running'])
                        <button class="vp-btn sm" disabled>Đang thiết kế…</button>
                    @elseif($yacht['pending'])
                        @include('video-projects.partials.task-pending', ['reset' => route('video-projects.vessel-design-reset', $project->id)])
                    @elseif($yacht['stuck'])
                        <form method="POST" action="{{ route('video-projects.vessel-design-reset', $project->id) }}">
                            @csrf
                            <button class="vp-btn sm dg">Reset lượt bị kẹt</button>
                        </form>
                    @elseif($yacht['can_run'] || $yacht['can_regenerate'])
                        <form method="POST" action="{{ route('video-projects.vessel-design', $project->id) }}"
                              id="yachtDesignForm" data-modal="confirmYachtDesign" onsubmit="return vpLockForm(this)">
                            @csrf
                            @if($yacht['can_regenerate'])<input type="hidden" name="force" value="1">@endif
                            <input type="hidden" name="operation_id" value="{{ $yacht['operation_id'] }}">
                        </form>
                        <button type="button" class="vp-btn sm pri" data-toggle="modal" data-target="#confirmYachtDesign"
                                data-busy="Đang thiết kế…">{{ $yacht['can_regenerate'] ? 'Thiết kế lại (tính phí)' : 'Creat design' }}</button>
                        @include('modal.confirm_action', [
                            'id' => 'confirmYachtDesign',
                            'form' => 'yachtDesignForm',
                            'content' => 'Gọi '.$yacht['run_model'].' viết hồ sơ thiết kế theo prompt Phase 3 từ Design đã đạt — tác vụ này tính tiền.',
                            'detail' => ! $yacht['can_regenerate'] ? '' : ($yacht['changes']['design'] !== [] ? 'Thay đổi: '.implode(', ', $yacht['changes']['design']).'. ' : '')
                                .($yacht['last_cost_usd'] !== null ? 'Lượt trước tốn $'.number_format($yacht['last_cost_usd'], 3).'. ' : '')
                                .'Bản đang có vẫn nằm trong lịch sử; bản mới sẽ thay nguồn và cần model sheet mới.',
                        ])
                    @else
                        <button class="vp-btn sm" disabled title="{{ $yacht['blocked_reason'] }}">Creat design</button>
                    @endif
                </div>
                <div class="va-body">
                    <div class="va-brief">
                        @if($yacht['prompt'])
                            <div class="line"><span class="k">Prompt khi chạy lại</span><span class="v">{{ $yacht['prompt'] }}</span></div>
                        @endif
                        @if($yacht['run_error'])
                            <div class="line"><span class="v" style="color:var(--vp-red)">{{ $yacht['run_error'] }}</span></div>
                        @endif
                        @include('video-projects.partials.task-state', ['task' => $yacht['task'], 'projectId' => $project->id])
                        @if($yacht['error'])
                            <div class="line"><span class="v" style="color:var(--vp-red)">{{ $yacht['error'] }}</span></div>
                        @endif
                        @if(! $yacht['analysed'])
                            @if(! $yacht['error'])
                                <div class="line"><span class="v">Chưa thiết kế</span></div>
                            @endif
                        @else
                            @if($yacht['dna_stale'])
                                <div class="line"><span class="v" style="color:var(--vp-red)">Bản thiết kế này thuộc Design DNA cũ.</span></div>
                            @endif
                            @if($yacht['integrity_error'])
                                <div class="line"><span class="v" style="color:var(--vp-red)">{{ $yacht['integrity_error'] }}</span></div>
                            @endif
                            @if($yacht['changes']['design'] !== [])
                                <div class="line"><span class="k">Đầu vào hiện hành</span><span class="v">Nguồn hoặc hợp đồng đã đổi ({{ implode(', ', $yacht['changes']['design']) }}). {{ $yachtKept ? 'Bản đã duyệt được giữ nguyên.' : 'Bản đang có được giữ; muốn áp dụng thì bấm Thiết kế lại (tính phí).' }}</span></div>
                            @endif
                            @if($yacht['changes']['next_sheet'] !== [])
                                <div class="line"><span class="k">Đầu vào hiện hành</span><span class="v">Cấu hình model sheet hiện hành đã thay đổi ({{ implode(', ', $yacht['changes']['next_sheet']) }}). Bản thiết kế này vẫn sử dụng dữ liệu đã ghim của lượt tạo; thay đổi áp dụng cho lượt model sheet mới.</span></div>
                            @endif
                            @if($yacht['changes']['next_run'] !== [])
                                <div class="line"><span class="k">Đầu vào hiện hành</span><span class="v">Thiết lập cho lượt mới đã đổi ({{ implode(', ', $yacht['changes']['next_run']) }}). Không ảnh hưởng tính hợp lệ của bản thiết kế này.</span></div>
                            @endif
                            @if($yacht['history_unverified'])
                                <div class="line"><span class="k">Bản lịch sử</span><span class="v">Bản lịch sử đang được giữ; chưa kiểm lại đầy đủ theo bộ kiểm mới.</span></div>
                            @endif
                            @if($yachtCheck !== null)
                                @php
                                    $yachtWarnings = $yachtCheck['warnings'] ?? [];
                                @endphp
                                <div class="line"><span class="k">Kiểm tự động</span><span class="v src-verdict {{ $yachtCheck['verdict'] === 'PASS' && $yachtWarnings !== [] ? 'unscored' : strtolower($yachtCheck['verdict']) }}">
                                    @switch($yachtCheck['verdict'])
                                        @case('PASS') {{ $yachtWarnings !== [] ? 'Cần xác nhận — '.count($yachtWarnings).' cảnh báo' : 'Đạt kiểm tự động' }} @break
                                        @case('FAIL') Không đạt — {{ count($yachtCheck['issues']) }} lỗi @break
                                        @default Đã nhận kết quả, chưa kiểm được — {{ $yachtCheck['error'] }}
                                    @endswitch
                                </span></div>
                                @foreach($yachtCheck['issues'] as $issue)
                                    <div class="line"><span class="v" style="color:var(--vp-red)">{{ $issue }}</span></div>
                                @endforeach
                                @foreach($yachtWarnings as $warning)
                                    <div class="line"><span class="v" style="color:var(--vp-amber-fg)">{{ $warning }}</span></div>
                                @endforeach
                            @endif
                            @if($yacht['name'])
                                <div class="line"><span class="k">{{ $yacht['moto'] ? 'Tên xe' : 'Tên tàu' }}</span><span class="v">{{ $yacht['name'] }}@if($yacht['loa']) · LOA {{ $yacht['loa'] }} m @endif</span></div>
                            @endif
                            @if($yacht['yacht_anchor'])
                                <div class="line"><span class="k">YACHT ANCHOR</span><span class="v">{{ $yacht['yacht_anchor'] }}</span></div>
                            @endif
                            @if($yacht['prose'] !== null)
                                <pre class="raw">{{ $yacht['prose'] }}</pre>
                            @elseif($yacht['json_error'] !== null)
                                <div class="line"><span class="v">Phản hồi không phải JSON ({{ $yacht['json_error'] }})</span></div>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
