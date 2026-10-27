    @php
        $pack = $referencePack;
        $plan = $pack['plan'];
        $groupLabels = ['views' => 'MS1–MS8 (dùng lại model sheet đã duyệt)', 'details' => 'Chi tiết ngoại thất', 'colors' => 'Bảng màu', 'rooms' => 'Phòng nội thất', 'turntable' => 'Turntable', 'style' => 'Style refs'];
        $methodLabels = ['reuse' => 'dùng lại', 'code' => 'dựng bằng code', 'render' => 'render', 'missing_source' => 'thiếu nguồn', 'not_applicable' => 'không áp dụng', 'unsupported' => 'chưa hỗ trợ', 'pending' => 'chờ 5L'];
        $readinessLabels = ['ready' => 'sẵn sàng', 'incomplete' => 'chưa đủ', 'partial' => 'một phần', 'pending' => 'chưa có', 'not_applicable' => 'không áp dụng', 'unsupported' => 'chưa hỗ trợ', 'pending_5l' => 'chờ 5L'];
        $renderForm = 'referencePackRender';
    @endphp

    <div class="vp-panel va-anchor" id="referencePackPanel">
        <div class="va-head">
            <span class="n">6</span>
            <b>PHASE 3R</b>
            <em>Bộ ảnh tham chiếu — dùng lại ảnh đã duyệt, chỉ tạo phần còn thiếu</em>
            <span class="grow"></span>
            @if($pack['pack']['selected'] ?? false)<span class="va-tag ok">Đang dùng bộ này</span>@endif
            @if($pack['review_stage_id'] !== null)
                <form method="POST" action="{{ route('video-projects.reference-pack-plan', $project->id) }}" onsubmit="return vpLockForm(this)">
                    @csrf
                    <input type="hidden" name="review_stage_id" value="{{ $pack['review_stage_id'] }}">
                    <button class="vp-btn sm {{ $plan === null ? 'pri' : '' }}" data-busy="Đang lập…" title="Kiểm nguồn, lập danh sách và dùng lại ảnh đã duyệt — không gọi AI">{{ $plan === null ? 'Lập bộ tham chiếu' : 'Lập lại từ model sheet đã duyệt' }}</button>
                </form>
            @endif
        </div>

        <div class="va-body">
            <div class="va-brief">
                @if($pack['selected'] !== null)
                    @php $sel = $pack['selected']; @endphp
                    <div class="line"><span class="k">Bộ đang dùng</span><span class="v">
                        @if($sel['valid'])
                            kế hoạch rev {{ $sel['plan_revision'] ?? '?' }} · {{ $sel['accepted_count'] }} mục đã duyệt · {{ $sel['locked'] ? 'có DESIGN LOCK' : 'chưa có DESIGN LOCK' }} · chọn lúc {{ $sel['selected_at'] }}
                        @else
                            <span style="color:var(--vp-amber-fg)">không còn hợp lệ — ngừng sử dụng: {{ implode(' ', array_slice($sel['problems'], 0, 3)) }}</span>
                        @endif
                        @if($plan !== null && ! $plan['is_selected'] && $sel['plan_id'] !== '')
                            · <a href="{{ route('video-projects.anchor', ['id' => $project->id, 'reference_plan' => $sel['plan_id']]) }}#referencePackPanel">Mở bộ đang dùng</a>
                        @endif
                    </span></div>
                @endif
                @if($plan !== null && ! $plan['is_latest'])
                    <div class="line"><span class="v">Đang xem kế hoạch rev {{ $plan['planning_revision'] }} (không phải mới nhất) · <a href="{{ route('video-projects.anchor', $project->id) }}#referencePackPanel">Về kế hoạch mới nhất</a></span></div>
                @endif
                @if($pack['source_problem'] !== null && $plan === null)
                    <div class="line"><span class="v" style="color:var(--vp-amber-fg)">Chưa lập được: {{ $pack['source_problem'] }}</span></div>
                @endif
                @if($plan === null)
                    <div class="line"><span class="v">Chưa lập bộ tham chiếu.</span></div>
                @else
                    <div class="line"><span class="k">Nguồn đã ghim</span><span class="v">thiết kế {{ substr($plan['sources']['design_stage_id'], 0, 8) }} (prompt Phase 3 rev {{ $plan['sources']['design_prompt_revision'] ?? '?' }}) · bộ model sheet {{ substr($plan['sources']['model_sheet']['set_id'], 0, 8) }} · lượt duyệt {{ substr($plan['sources']['model_sheet']['review_stage_id'], 0, 8) }} · prompt {{ $plan['sources']['phase']['code'] }} rev {{ $plan['sources']['phase']['revision'] }}</span></div>
                    @if($plan['contract_problem'] !== null)
                        <div class="line"><span class="v" style="color:var(--vp-amber-fg)">{{ $plan['contract_problem'] }}</span></div>
                    @endif
                    @if($plan['stale_source'])
                        <div class="line"><span class="v" style="color:var(--vp-amber-fg)">Model sheet đã duyệt hiện tại khác nguồn của bộ này — bộ này giữ nguyên nguồn cũ; muốn dùng nguồn mới thì lập bộ mới.</span></div>
                    @endif
                    <div class="line"><span class="k">Mức sẵn sàng</span><span class="v">
                        @foreach(['exterior' => 'Ngoại thất', 'interior' => 'Nội thất', 'video' => 'Video', 'style' => 'Phong cách'] as $level => $label)
                            @php $state = $pack['readiness'][$level]['status'] ?? null; @endphp
                            <span class="va-tag {{ $state === 'ready' ? 'ok' : ($state === 'incomplete' ? 'amber' : '') }}">{{ $label }}: {{ $readinessLabels[$state] ?? $state }}</span>
                        @endforeach
                    </span></div>
                    @if(($pack['readiness']['exterior']['blocking'] ?? []) !== [])
                        <div class="line"><span class="k">Còn thiếu (ngoại thất)</span><span class="v">{{ implode(', ', $pack['readiness']['exterior']['blocking']) }}</span></div>
                    @endif
                    <div class="line"><span class="k">Kiểm hình ảnh</span><span class="v">Chưa có cổng Vision tự động — trạng thái “chưa kiểm hình ảnh”; người duyệt quyết định khi bấm duyệt bộ.</span></div>
                    @if($pack['lock'] !== null)
                        <div class="line"><span class="k">DESIGN LOCK</span><span class="v">
                            @if($pack['lock']['locked'])
                                <span class="va-tag ok">Đã khoá</span>
                            @else
                                <span class="va-tag amber">Chưa khoá</span> — pack vẫn duyệt được cho 3B/4/5L; công đoạn yêu cầu nguồn đã khoá sẽ chặn.
                            @endif
                        </span></div>
                        @foreach($pack['lock']['missing'] as $missing)
                            <div class="line"><span class="v" style="color:var(--vp-amber-fg)">{{ $missing }}</span></div>
                        @endforeach
                        @if(! $pack['lock']['locked'])
                            <div class="line">
                                <form method="POST" action="{{ route('video-projects.design-lock', $project->id) }}" onsubmit="return vpLockForm(this)">
                                    @csrf
                                    <input type="hidden" name="plan_id" value="{{ $plan['id'] }}">
                                    <button class="vp-btn sm" data-busy="Đang khoá…" @disabled($pack['lock']['missing'] !== []) title="{{ $pack['lock']['missing'] === [] ? 'Ghi bản ghi khoá thiết kế' : 'Còn thiếu thành phần để khoá' }}">Ghi DESIGN LOCK</button>
                                </form>
                            </div>
                        @endif
                    @endif
                @endif
            </div>
        </div>

        @if($plan !== null)
            <form method="POST" action="{{ route('video-projects.reference-pack-render', $project->id) }}" id="{{ $renderForm }}" data-modal="confirm{{ $renderForm }}" onsubmit="return vpLockForm(this)" hidden>
                @csrf
                <input type="hidden" name="plan_id" value="{{ $plan['id'] }}">
                <input type="hidden" name="attempt" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            </form>

            <div class="va-rows">
                @foreach($pack['groups'] as $group => $items)
                    <div class="va-row va-row-head"><div>{{ $groupLabels[$group] ?? $group }}</div></div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px;padding:8px 14px">
                        @foreach($items as $item)
                            @php
                                $status = $item['status'];
                                $rule = $status['rule'] ?? null;
                            @endphp
                            <div class="va-cand" style="padding:6px">
                                @if($item['url'])
                                    <a href="{{ $item['url'] }}" target="_blank" rel="noopener"><img src="{{ $item['url'] }}" alt="{{ $item['id'] }}" loading="lazy" style="width:100%;max-height:150px;object-fit:contain;background:#f1f5f9"></a>
                                @endif
                                <div class="meta" style="display:flex;flex-direction:column;gap:2px">
                                    <span><b>{{ $item['id'] }}</b> · {{ $item['subject'] }}</span>
                                    <span>
                                        <span class="va-tag {{ $item['requirement'] === 'required' ? 'blue' : '' }}">{{ $item['requirement'] === 'required' ? 'bắt buộc' : 'tuỳ chọn' }}</span>
                                        <span class="va-tag">{{ $methodLabels[$item['method']] ?? $item['method'] }}</span>
                                        @if($rule === 'pass')<span class="va-tag ok">đạt RULE</span>@elseif($rule === 'fail' && $item['requirement'] === 'required')<span class="va-tag dg">chưa đạt</span>@endif
                                        @if(($status['acceptance'] ?? null) === 'inherited')<span class="va-tag ok">đã duyệt (model sheet)</span>@endif
                                    </span>
                                    @if($item['inputs'] !== [])<span style="color:var(--vp-dim)">Nguồn: {{ implode(', ', $item['inputs']) }}</span>@endif
                                    <span style="color:var(--vp-dim)">{{ $item['reason'] }}</span>
                                    @foreach(($status['problems'] ?? []) as $problem)
                                        @if($item['requirement'] === 'required' || $item['image_status'] !== null)
                                            <span style="color:var(--vp-red)">{{ $problem }}</span>
                                        @endif
                                    @endforeach
                                    @if($item['render_error'])<span style="color:var(--vp-red)">{{ $item['render_error'] }}</span>@endif
                                    @if($item['renderable'])
                                        <label class="va-lbl" style="font-weight:400"><input type="checkbox" name="item_ids[]" value="{{ $item['id'] }}" form="{{ $renderForm }}" @checked($item['requirement'] === 'required')> Tạo mục này</label>
                                    @endif
                                    @if($item['image_status'] === 'rendered')
                                        <form method="POST" action="{{ route('video-projects.reference-pack-reject', $project->id) }}" onsubmit="return vpLockForm(this)">
                                            @csrf
                                            <input type="hidden" name="plan_id" value="{{ $plan['id'] }}">
                                            <input type="hidden" name="image_id" value="{{ $item['image_id'] }}">
                                            <button class="vp-btn sm dg" data-busy="Đang từ chối…">✕ Từ chối ảnh</button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>

            <div class="va-foot" style="padding:10px 14px;display:flex;flex-wrap:wrap;gap:8px;align-items:center">
                <select class="ctl" name="model" form="{{ $renderForm }}" required>
                    @foreach($pack['models'] as $m)
                        <option value="{{ $m['value'] }}" @selected($m['default'])>{{ $m['label'] }}</option>
                    @endforeach
                </select>
                <select class="ctl" name="quality" form="{{ $renderForm }}" required>
                    <option value="">Choose quality</option>
                    @foreach($pack['qualities'] as $q)
                        <option value="{{ $q['value'] }}">{{ $q['label'] }}@if($q['usd'] !== null) (≈ ${{ number_format($q['usd'], 3) }}/ảnh)@endif</option>
                    @endforeach
                </select>
                <button type="button" class="vp-btn pri" data-toggle="modal" data-target="#confirm{{ $renderForm }}" data-busy="Đang tạo…">Tạo phần còn thiếu</button>
                @include('modal.confirm_action', [
                    'id' => 'confirm'.$renderForm,
                    'form' => $renderForm,
                    'content' => 'Tạo các mục đã đánh dấu (tối đa '.$pack['max_render_items'].' mục mỗi lần), mỗi mục một ảnh — TÁC VỤ NÀY TÍNH TIỀN. Mục đã có ảnh sẽ không render lại.',
                ])

                <form method="POST" action="{{ route('video-projects.reference-pack-check', $project->id) }}" onsubmit="return vpLockForm(this)">
                    @csrf
                    <input type="hidden" name="plan_id" value="{{ $plan['id'] }}">
                    <button class="vp-btn" data-busy="Đang kiểm…" title="Kiểm cấu trúc, nguồn, file, hash và đo bảng màu; ghi manifest ứng viên — không gọi AI">Kiểm tra bộ</button>
                </form>

                @if($pack['pack'] !== null)
                    @php
                        $canApprove = $pack['pack']['current'] && ! $pack['pack']['selected'] && ($pack['pack']['readiness']['exterior']['status'] ?? null) === 'ready' && ($pack['pack']['validation']['rule'] ?? null) === 'pass';
                    @endphp
                    <form method="POST" action="{{ route('video-projects.reference-pack-approve', $project->id) }}" onsubmit="return vpLockForm(this)">
                        @csrf
                        <input type="hidden" name="pack_stage_id" value="{{ $pack['pack']['id'] }}">
                        <input type="hidden" name="selection_version" value="{{ $pack['selection_version'] }}">
                        <button class="vp-btn ok" data-busy="Đang duyệt…" @disabled(! $canApprove)
                                title="{{ $pack['pack']['selected'] ? 'Bộ này đang được dùng' : (! $pack['pack']['current'] ? 'Nội dung đã đổi sau lần kiểm — bấm Kiểm tra bộ lại' : 'Đóng băng manifest, ghi lượt duyệt và dùng bộ này') }}">✓ Duyệt và dùng bộ này</button>
                    </form>
                    <span class="va-lbl" style="font-weight:400;color:var(--vp-dim)">Manifest {{ substr((string) $pack['pack']['manifest_sha256'], 7, 12) }}@if(! $pack['pack']['current']) · đã cũ so với hiện trạng @endif</span>
                @endif
            </div>
        @endif
    </div>
