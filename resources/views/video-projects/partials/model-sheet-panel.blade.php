    @php
        $sheet = $modelSheet;
    @endphp

    <div class="vp-panel va-anchor" id="modelSheetPanel">
        <div class="va-head">
            <span class="n">5</span>
            <b>MODEL SHEET</b>
            <em>Phase 3 — MS1–MS8 của tàu hoàn thiện (chưa DESIGN LOCK)</em>
            <span class="grow"></span>
            @if($sheet['reviewed'])<span class="va-tag ok">Đã duyệt bộ</span>@endif
            @if($sheet['proxy_ready'])
                <a class="vp-btn sm" href="{{ route('video-projects.proxy', $project->id) }}">Proxy 3D →</a>
            @endif
        </div>

        <div class="va-body">
            <div class="va-brief">
                <div class="line"><span class="k">Bản thiết kế nguồn</span><span class="v">{{ $sheet['design_name'] ?? '—' }}@if($sheet['design_stage_id']) · {{ substr($sheet['design_stage_id'], 0, 8) }}@endif</span></div>
                @if($sheet['design_warnings'] > 0)
                    <div class="line"><span class="v" style="color:var(--vp-amber-fg)">{{ $sheet['design_warnings'] }} cảnh báo của bản thiết kế cần đối chiếu với model sheet.</span></div>
                @endif
                @if($sheet['source_error'] !== null)
                    <div class="line"><span class="v" style="color:var(--vp-red)">{{ $sheet['source_error'] }}</span></div>
                @endif
                @if($sheet['set'] !== null)
                    <div class="line"><span class="k">Bộ model sheet</span><span class="v">{{ substr($sheet['set']['id'], 0, 8) }} · prompt phase-3 revision {{ $sheet['set']['phase_revision'] }}</span></div>
                    @if(! $sheet['set']['current_design'])
                        <div class="line"><span class="v" style="color:var(--vp-amber-fg)">Bộ này gắn với một bản thiết kế khác bản hiện hành.</span></div>
                    @endif
                    @if($sheet['set']['compatible_error'] !== null)
                        <div class="line"><span class="v" style="color:var(--vp-red)">{{ $sheet['set']['compatible_error'] }}</span></div>
                    @endif
                    @if($sheet['set']['design_missing'] !== [])
                        <div class="line"><span class="v" style="color:var(--vp-red)">Chưa render được — thiết kế thiếu {{ count($sheet['set']['design_missing']) }} dữ liệu bắt buộc:{{ implode(', ', $sheet['set']['design_missing']) }}</span></div>
                    @endif
                @endif
            </div>
        </div>

        @if($sheet['set'] !== null)
            <div class="va-rows">
                <div class="va-row va-row-head">
                    <div>Góc</div>
                    <div>Prompt</div>
                    <div>Render</div>
                    <div>Candidate images</div>
                </div>

                @foreach($sheet['rows'] as $row)
                    @php
                        $n = 'ms'.$loop->index;
                        $cards = collect($row['cells'])->flatMap(fn (array $cell) => collect($cell['candidates'])->map(fn (array $candidate) => ['cell' => $cell, 'candidate' => $candidate]));
                    @endphp
                    <div class="va-row">
                        <div>
                            <b>{{ $row['id'] }}</b>
                            <div class="va-lbl" style="font-weight:400">{{ $row['view'] }}</div>
                            @if($row['approved'] !== null && $row['approved']['stale'])
                                <span class="va-tag amber">Cần xác nhận lại</span>
                            @elseif($row['approved'] !== null)
                                <span class="va-tag ok">Đã duyệt</span>
                            @elseif($row['locked'])
                                <span class="va-tag">Chờ duyệt MS1</span>
                            @else
                                <span class="va-tag">Chưa duyệt</span>
                            @endif
                        </div>

                        <div>
                            <div class="va-lbl">PROMPT GỬI ĐI</div>
                            <textarea class="va-ta" readonly>{{ $row['prompt'] }}</textarea>
                            <div class="va-count"><span>{{ mb_strlen($row['prompt']) }}</span> ký tự</div>
                        </div>

                        <div class="va-row-set">
                            @if($row['locked'])
                                <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">Duyệt một ảnh MS1 trước — các góc sau dùng ảnh MS1 đã duyệt làm tham chiếu.</div>
                            @else
                                @php
                                    $formId = 'modelSheetRender'.$n;
                                @endphp
                                <form method="POST" action="{{ route('video-projects.model-sheet-render', $project->id) }}"
                                      id="{{ $formId }}" data-modal="confirm{{ $formId }}" onsubmit="return vpLockForm(this)" hidden>
                                    @csrf
                                    <input type="hidden" name="view_id" value="{{ $row['id'] }}">
                                    <input type="hidden" name="attempt" value="{{ $row['attempt'] }}">
                                    <input type="hidden" name="prompt_sha256" value="{{ $row['prompt_sha256'] }}">
                                </form>

                                <div class="va-fields">
                                    <div class="va-field">
                                        <label>Asset Name <em>(mã dự kiến)</em></label>
                                        <div class="ctl"><span>{{ $sheet['next_image_code'] }}</span><span class="cnt">{{ strlen($sheet['next_image_code']) }}/100</span></div>
                                    </div>
                                    <div class="va-field">
                                        <label>Size</label>
                                        <select class="ctl" name="size" form="{{ $formId }}" required>
                                            @foreach(\App\Enums\ImageSize::cases() as $r)
                                                @if($row['size'] === null || $row['size'] === $r->value)
                                                    <option value="{{ $r->value }}" @selected(($row['size'] ?? $sheet['default_size']) === $r->value)>{{ $r->label() }}</option>
                                                @endif
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="va-field">
                                        <label>Model</label>
                                        <select class="ctl" name="model" form="{{ $formId }}" required>
                                            <option value="">Choose model</option>
                                            @foreach($sheet['models'] as $m)
                                                <option value="{{ $m['value'] }}" @selected($m['default'])>{{ $m['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="va-field">
                                        <label>Quality</label>
                                        <select class="ctl" name="quality" form="{{ $formId }}" required>
                                            <option value="" selected>Choose quality</option>
                                            @foreach(\App\Enums\ImageQuality::cases() as $q)
                                                <option value="{{ $q->value }}" title="{{ $q->hint() }}"
                                                        data-models="{{ collect($sheet['models'])->filter(fn ($m) => \App\Enums\ImageModel::from($m['value'])->supports($q))->pluck('value')->implode(' ') }}">{{ $q->label() }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="va-field">
                                        <label>Variations</label>
                                        <select class="ctl" name="variations" form="{{ $formId }}" required>
                                            @foreach(\App\Enums\ImageVariations::cases() as $v)
                                                <option value="{{ $v->value }}" @selected($v->value === 1)>{{ $v->label() }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>

                                <div class="va-foot">
                                    <button type="button" class="vp-btn pri" data-anchor-form="{{ $formId }}"
                                            data-toggle="modal" data-target="#confirm{{ $formId }}"
                                            @if(! $sheet['set']['active'] || $sheet['set']['compatible_error'] !== null || $sheet['set']['design_missing'] !== []) disabled data-blocked="1" @endif
                                            data-busy="Đang render…">Render Image</button>
                                    @include('modal.confirm_action', [
                                        'id' => 'confirm'.$formId,
                                        'form' => $formId,
                                        'content' => 'Gửi prompt '.$row['id'].' cho model đã chọn render — TÁC VỤ NÀY TÍNH TIỀN.',
                                        'detail' => 'Đang tính…',
                                    ])
                                </div>
                            @endif
                        </div>

                        <div class="va-row-cands">
                            @foreach($row['cells'] as $cell)
                                @if($cell['candidates'] === [])
                                    <div class="va-cell">
                                        <span class="code">{{ $cell['image_code'] }}</span>
                                        <span class="va-tag {{ $cell['status_tone'] }}">{{ $cell['status_label'] }}</span>
                                        <span class="grow"></span>
                                        <span class="d">{{ $cell['render_model'] }} · {{ $cell['size'] }}</span>
                                    </div>
                                @endif
                                @if($cell['render_error'])
                                    <div class="alert alert-danger">{{ $cell['render_error'] }}</div>
                                @endif
                            @endforeach

                            @if($cards->isEmpty())
                                <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">Chưa có ảnh nào.</div>
                            @else
                                <form method="POST" action="{{ route('video-projects.model-sheet-approve', $project->id) }}"
                                      id="approveModelSheet{{ $n }}" onsubmit="return vpLockForm(this)" hidden>
                                    @csrf
                                </form>
                                <div class="va-cands">
                                    @foreach($cards as $cardIndex => $card)
                                        @php
                                            $cell = $card['cell'];
                                            $candidate = $card['candidate'];
                                        @endphp
                                        <div class="va-cand">
                                            <img src="{{ $candidate['url'] }}" alt="{{ $row['id'] }} candidate {{ $cardIndex + 1 }}"
                                                 width="{{ $candidate['width'] }}" height="{{ $candidate['height'] }}">
                                            <div class="cap">
                                                <span>
                                                    <input type="radio" name="artifact_id" form="approveModelSheet{{ $n }}" required
                                                           value="{{ $candidate['id'] }}" @disabled(! $sheet['set']['active'] || ! $cell['can_approve'] || $cell['stale'])>
                                                    CANDIDATE {{ $cardIndex + 1 }}
                                                </span>
                                                @if($cell['status'] === 'approved' && $cell['selected_artifact_id'] === $candidate['id'])
                                                    <b>{{ $cell['stale'] ? 'Cần xác nhận lại' : 'Đã duyệt' }}</b>
                                                @elseif($cell['stale'])
                                                    <b>Từ MS1 cũ</b>
                                                @endif
                                            </div>
                                            <div class="meta">
                                                <span>{{ $cell['image_code'] }}</span>
                                                <span>Model: {{ $cell['render_model'] }}</span>
                                                <span>{{ $candidate['width'] }}×{{ $candidate['height'] }}</span>
                                                <span>Created: {{ $candidate['created_at']?->format('Y-m-d H:i:s') ?? '—' }}</span>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="va-foot">
                                    <button type="submit" form="approveModelSheet{{ $n }}" class="vp-btn ok"
                                            data-approve-form="approveModelSheet{{ $n }}" disabled
                                            title="Chọn một ảnh candidate trước" data-busy="Đang duyệt…">✓ Duyệt {{ $row['id'] }}</button>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="va-foot" style="padding:10px 14px">
                @if($sheet['reviewed'])
                    <span class="va-tag ok">Đã duyệt bộ model sheet — bước tiếp theo là trace và DESIGN LOCK.</span>
                @else
                    <form method="POST" action="{{ route('video-projects.model-sheet-review', $project->id) }}" onsubmit="return vpLockForm(this)">
                        @csrf
                        <button class="vp-btn ok" @disabled(! $sheet['set']['active'] || ! $sheet['complete']) title="{{ $sheet['complete'] ? '' : 'Cần đủ tám góc được duyệt và còn hợp lệ' }}" data-busy="Đang duyệt…">Duyệt bộ model sheet</button>
                    </form>
                @endif
            </div>
        @endif
    </div>
