    @php
        $sheet = $modelSheet;
    @endphp

    <div class="vp-panel va-anchor" id="modelSheetPanel">
        <div class="va-head">
            <span class="n">5</span>
            <b>MODEL SHEET</b>
            <em>Phase 3 — một master {{ $sheet['size'] }}, lưới 2 × 4, MS1–MS8 (chưa DESIGN LOCK)</em>
            <span class="grow"></span>
            @if($sheet['reviewed'] || $sheet['sheet'] !== null)<span class="va-tag ok">Đã duyệt model sheet</span>@endif
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
                @if($sheet['design_note'] !== null)
                    <div class="line"><span class="v">{{ $sheet['design_note'] }}</span></div>
                @endif
                @if($sheet['sheet'] !== null && ($sheet['set'] === null || $sheet['sheet']['set_id'] !== $sheet['set']['id']))
                    <div class="line"><span class="k">Model sheet đã duyệt</span><span class="v">master {{ substr($sheet['sheet']['master_image_id'], 0, 8) }} · bộ {{ substr($sheet['sheet']['set_id'], 0, 8) }} (bộ trước) — vẫn là model sheet hợp lệ.</span></div>
                @endif
                @if($sheet['set'] !== null)
                    <div class="line"><span class="k">Bộ model sheet</span><span class="v">{{ substr($sheet['set']['id'], 0, 8) }} · prompt QA revision {{ $sheet['set']['phase_revision'] }}</span></div>
                    @if(! $sheet['set']['current_design'])
                        <div class="line"><span class="v" style="color:var(--vp-amber-fg)">Bộ này gắn với một bản thiết kế khác bản hiện hành.</span></div>
                    @endif
                    @if($sheet['set']['compatible_error'] !== null)
                        <div class="line"><span class="v" style="color:var(--vp-red)">{{ $sheet['set']['compatible_error'] }}</span></div>
                    @endif
                    @foreach($sheet['set']['blocking'] as $blocking)
                        <div class="line"><span class="v" style="color:var(--vp-red)">{{ $blocking }}</span></div>
                    @endforeach
                @endif
            </div>
        </div>

        @if($sheet['set'] !== null)
            @php
                $master = $sheet['master'];
                $qa = $sheet['qa'];
                $formId = 'modelSheetRenderMaster';
            @endphp
            <div class="va-rows">
                <div class="va-row va-row-head">
                    <div>Master</div>
                    <div>Prompt</div>
                    <div>Render</div>
                    <div>Candidate images</div>
                </div>

                <div class="va-row">
                    <div>
                        <b>MS1–MS8</b>
                        @foreach($master['views'] as $view)
                            <div class="va-lbl" style="font-weight:400">Hàng {{ $view['row'] }} · cột {{ $view['column'] }} — {{ $view['id'] }}: {{ $view['view'] }}</div>
                        @endforeach
                        @if($sheet['approved'] !== null)
                            <span class="va-tag ok">Đã duyệt</span>
                        @else
                            <span class="va-tag">Chưa duyệt</span>
                        @endif
                    </div>

                    <div>
                        @if(($qa['prompt'] ?? null) !== null)
                            <div class="va-lbl">PROMPT GỬI ĐI <em>(đã QA)</em></div>
                            <textarea class="va-ta" readonly>{{ $qa['prompt'] }}</textarea>
                            <div class="va-count"><span>{{ mb_strlen($qa['prompt']) }}</span> ký tự</div>
                        @endif
                        <div class="va-lbl">BẢN NHÁP CODE <em>(trước QA — không gửi đi)</em></div>
                        <textarea class="va-ta" readonly>{{ $master['draft'] }}</textarea>
                        <div class="va-count"><span>{{ mb_strlen($master['draft']) }}</span> ký tự</div>
                        @if($master['layout_guide'])
                            <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">Thử nghiệm: gửi kèm ảnh mẫu lưới trống (không có hình tàu).</div>
                        @endif

                        @if($qa !== null)
                            <div class="va-foot">
                                @if($qa['status'] === 'running')
                                    <span class="va-tag amber">Đang QA…</span>
                                @elseif($qa['ready'])
                                    <span class="va-tag ok">QA đạt kiểm</span>
                                @elseif($qa['status'] === 'none')
                                    <span class="va-tag">Chưa QA</span>
                                @else
                                    <span class="va-tag dg">QA chưa đạt</span>
                                @endif
                                @if($qa['cost_usd'] !== null && $qa['status'] !== 'none')
                                    <span class="va-lbl" style="font-weight:400;color:var(--vp-dim)">{{ $qa['model'] }} · {{ $qa['tokens']['in'] }}/{{ $qa['tokens']['out'] }} token · ${{ number_format($qa['cost_usd'], 3) }}</span>
                                @endif
                                @if($qa['can_run'])
                                    <form method="POST" action="{{ route('video-projects.model-sheet-qa', $project->id) }}"
                                          id="modelSheetQaForm" data-modal="confirmModelSheetQa" onsubmit="return vpLockForm(this)">
                                        @csrf
                                        @if($qa['retry'])<input type="hidden" name="retry" value="1">@endif
                                    </form>
                                    <button type="button" class="vp-btn sm pri" data-toggle="modal" data-target="#confirmModelSheetQa"
                                            data-busy="Đang QA…">{{ $qa['retry'] || $qa['status'] === 'failed' ? 'QA lại' : 'QA & hoàn thiện prompt' }}</button>
                                    @include('modal.confirm_action', [
                                        'id' => 'confirmModelSheetQa',
                                        'form' => 'modelSheetQaForm',
                                        'content' => 'Gọi '.$qa['model'].' kiểm thiết kế, bổ sung và biên tập prompt model sheet — tác vụ này tính tiền ('.$qa['estimate'].').',
                                    ])
                                @endif
                            </div>
                            @if($qa['stream'] !== null)
                                <div class="va-brief">@include('video-projects.partials.stream-state', ['stream' => $qa['stream']])</div>
                            @endif
                            @if($qa['error'] !== null)
                                <div class="va-lbl" style="font-weight:400;color:var(--vp-red)">{{ $qa['error'] }}</div>
                            @endif
                            @foreach($qa['validation']['errors'] ?? [] as $line)
                                <div class="va-lbl" style="font-weight:400;color:var(--vp-red)">Lỗi kiểm: {{ $line }}</div>
                            @endforeach
                            @foreach($qa['validation']['blocking'] ?? [] as $line)
                                <div class="va-lbl" style="font-weight:400;color:var(--vp-red)">Chặn render: {{ $line }}</div>
                            @endforeach
                        @endif
                    </div>

                    <div class="va-row-set">
                        <form method="POST" action="{{ route('video-projects.model-sheet-render', $project->id) }}"
                              id="{{ $formId }}" data-modal="confirm{{ $formId }}" onsubmit="return vpLockForm(this)" hidden>
                            @csrf
                            <input type="hidden" name="attempt" value="{{ $master['attempt'] }}">
                            <input type="hidden" name="qa_stage_id" value="{{ $qa['stage_id'] ?? '' }}">
                            <input type="hidden" name="prompt_sha256" value="{{ $qa['prompt_sha256'] ?? '' }}">
                        </form>

                        <div class="va-fields">
                            <div class="va-field">
                                <label>Asset Name <em>(mã dự kiến)</em></label>
                                <div class="ctl"><span>{{ $sheet['next_image_code'] }}</span><span class="cnt">{{ strlen($sheet['next_image_code']) }}/100</span></div>
                            </div>
                            <div class="va-field">
                                <label>Size <em>(cố định)</em></label>
                                <select class="ctl" name="size" form="{{ $formId }}" required>
                                    <option value="{{ $sheet['size'] }}" selected>{{ \App\Enums\ImageSize::tryFrom($sheet['size'])?->label() ?? $sheet['size'] }}</option>
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
                                <label>Variations <em>(cố định)</em></label>
                                <select class="ctl" name="variations" form="{{ $formId }}" required>
                                    <option value="{{ $sheet['variations'] }}" selected>{{ \App\Enums\ImageVariations::tryFrom($sheet['variations'])?->label() ?? $sheet['variations'] }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="va-foot">
                            <button type="button" class="vp-btn pri" data-anchor-form="{{ $formId }}"
                                    data-toggle="modal" data-target="#confirm{{ $formId }}"
                                    @if(! $sheet['set']['active'] || $sheet['set']['compatible_error'] !== null || $sheet['set']['blocking'] !== [] || ! ($qa['ready'] ?? false)) disabled data-blocked="1" title="Cần lượt QA đạt kiểm trước khi render" @endif
                                    data-busy="Đang render…">Render Image</button>
                            @include('modal.confirm_action', [
                                'id' => 'confirm'.$formId,
                                'form' => $formId,
                                'content' => 'Gửi prompt master đã QA cho model đã chọn: một request tạo một ảnh '.$sheet['size'].' chứa tám góc MS1–MS8 — TÁC VỤ NÀY TÍNH TIỀN.',
                                'detail' => 'Đang tính…',
                            ])
                        </div>
                    </div>

                    <div class="va-row-cands">
                        @forelse($sheet['masters'] as $cell)
                            <div class="va-cell">
                                <span class="code">{{ $cell['image_code'] }}</span>
                                <span class="va-tag {{ $cell['status_tone'] }}">{{ $cell['status_label'] }}</span>
                                <span class="grow"></span>
                                <span class="d">{{ $cell['render_model'] }} · {{ $cell['size'] }}</span>
                            </div>
                            @if($cell['render_error'])
                                <div class="alert alert-danger">{{ $cell['render_error'] }}</div>
                            @endif

                            @foreach($cell['candidates'] as $candidate)
                                @php
                                    $crops = $cell['crops'][$candidate['id']] ?? [];
                                    $processing = $cell['processing'][$candidate['id']] ?? [];
                                    $problem = $cell['problems'][$candidate['id']] ?? null;
                                    $approveId = 'approveModelSheet'.substr($candidate['id'], 0, 8);
                                @endphp
                                <div class="va-cands">
                                    <div class="va-cand">
                                        <img src="{{ $candidate['url'] }}" alt="Master {{ $cell['image_code'] }}"
                                             width="{{ $candidate['width'] }}" height="{{ $candidate['height'] }}">
                                        <div class="meta">
                                            <span>{{ $cell['image_code'] }}</span>
                                            <span>Model: {{ $cell['render_model'] }}</span>
                                            <span>{{ $candidate['width'] }}×{{ $candidate['height'] }}</span>
                                            <span>Created: {{ $candidate['created_at']?->format('Y-m-d H:i:s') ?? '—' }}</span>
                                        </div>
                                    </div>
                                </div>

                                @if($crops !== [])
                                    <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:6px;margin-top:6px">
                                        @foreach($crops as $crop)
                                            <a href="{{ $crop['url'] }}" target="_blank" rel="noopener" class="va-cand" style="position:relative" title="{{ $crop['view_id'] }} · ({{ $crop['x'] }}, {{ $crop['y'] }}) · {{ $crop['width'] }}×{{ $crop['height'] }}">
                                                <img src="{{ $crop['url'] }}" alt="{{ $crop['view_id'] }}" width="{{ $crop['width'] }}" height="{{ $crop['height'] }}" loading="lazy">
                                                <span class="va-tag" style="position:absolute;top:4px;left:4px">{{ $crop['view_id'] }}</span>
                                            </a>
                                        @endforeach
                                    </div>
                                @endif

                                @if(($processing['ok'] ?? null) === false)
                                    <div class="alert alert-danger">{{ $processing['error'] ?? 'Xử lý master lỗi.' }}</div>
                                @endif

                                <div class="va-foot">
                                    @if(in_array($cell['status'], ['rendered', 'approved'], true))
                                        <form method="POST" action="{{ route('video-projects.model-sheet-process', $project->id) }}" onsubmit="return vpLockForm(this)">
                                            @csrf
                                            <input type="hidden" name="image_id" value="{{ $cell['id'] }}">
                                            <button class="vp-btn" data-busy="Đang xử lý…" @disabled(! $sheet['set']['active'])>Xử lý lại từ master</button>
                                        </form>
                                    @endif
                                    @if($cell['status'] === 'rendered')
                                        <form method="POST" action="{{ route('video-projects.model-sheet-reject', $project->id) }}" onsubmit="return vpLockForm(this)">
                                            @csrf
                                            <input type="hidden" name="image_id" value="{{ $cell['id'] }}">
                                            <button class="vp-btn dg" data-busy="Đang từ chối…" @disabled(! $sheet['set']['active'])>✕ Từ chối cả sheet</button>
                                        </form>
                                    @endif
                                    @if($cell['status'] === 'approved' && $cell['selected_artifact_id'] === $candidate['id'])
                                        <span class="va-tag ok">Đã duyệt cả sheet</span>
                                    @elseif(in_array($cell['status'], ['rendered', 'approved'], true))
                                        <form method="POST" action="{{ route('video-projects.model-sheet-approve', $project->id) }}"
                                              id="{{ $approveId }}" onsubmit="return vpLockForm(this)" hidden>
                                            @csrf
                                        </form>
                                        <label class="va-lbl" style="font-weight:400">
                                            <input type="radio" name="artifact_id" form="{{ $approveId }}" required value="{{ $candidate['id'] }}"
                                                   @disabled(! $sheet['set']['active'] || $problem !== null)>
                                            Chọn master này
                                        </label>
                                        <button type="submit" form="{{ $approveId }}" class="vp-btn ok"
                                                data-approve-form="{{ $approveId }}" disabled
                                                title="{{ $problem ?? 'Chọn master trước' }}" data-busy="Đang duyệt…">✓ Duyệt cả sheet</button>
                                    @endif
                                </div>
                                @if($problem !== null && in_array($cell['status'], ['rendered', 'approved'], true))
                                    <div class="va-lbl" style="font-weight:400;color:var(--vp-red)">{{ $problem }}</div>
                                @endif
                            @endforeach
                        @empty
                            <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">Chưa có ảnh nào.</div>
                        @endforelse
                    </div>
                </div>
            </div>

            <div class="va-foot" style="padding:10px 14px">
                @if($sheet['reviewed'])
                    <span class="va-tag ok">Đã duyệt model sheet — bước tiếp theo là đo MS1/MS6, đối chiếu thiết kế và khóa hình học.</span>
                @elseif($sheet['approved'] !== null && $sheet['approved']['problem'] !== null)
                    <span class="va-tag amber">{{ $sheet['approved']['problem'] }}</span>
                @endif
            </div>
        @endif
    </div>
