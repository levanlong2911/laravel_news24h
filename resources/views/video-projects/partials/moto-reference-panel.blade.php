@php
    $ref = $motoReference;
    $preview = $ref['preview'];
    $plan = $preview['plan'] ?? null;
    $stage = $ref['stage'];
    $references = $ref['references'];
    $labels = $ref['labels'];
    $choice = $ref['choice'];
    $grid = $ref['grid'];
    $pixels = static fn (string $size): string => str_replace('x', ' × ', $size);
    $oneImage = static fn (array $layout): bool => (int) ($layout['rows'] ?? 0) * (int) ($layout['columns'] ?? 0) === 1;
    $tag = static fn (string $status): string => '<span class="va-tag '.($labels[$status][0] ?? 'mute').'">'.e($labels[$status][1] ?? $status).'</span>';
    $regimeLabel = ['grey' => 'NGUỒN XÁM — chỉ hình học', 'colour' => 'NGUỒN MÀU — có lớp hoàn thiện'];
    $review = $ref['review'];
    $model = collect($ref['models'])->firstWhere('model', $choice['model']);
    $rows = $ref['sheets'] === [] ? [null] : $ref['sheets'];
@endphp

<div class="container-fluid vp" id="motoReferencePage">

    <div class="vp-crumb">
        <a href="{{ route('video-projects.index') }}">Video Projects</a>
        <span class="sep">/</span>
        <a href="{{ route('video-projects.anchor', $id) }}">Kịch bản</a>
        <span class="sep">·</span>
        <a href="{{ route('video-projects.scene', $id) }}">Scenes</a>
        <span class="sep">·</span>
        <b>Reference</b>
        <span class="grow"></span>
        @if($plan !== null)
            <span class="m">{{ count($plan['references']) }} ảnh cần tạo · {{ count($plan['needs']) }} nhu cầu</span>
        @endif
    </div>

    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="vp-panel va-anchor">
        <div class="va-head">
            <b>REFERENCE · PARTS DETAIL</b>
            <em>từ shot đã duyệt — chỉ tạo ảnh còn thiếu, dùng lại nguồn đã duyệt</em>
            <span class="grow"></span>
            <span class="va-tag ok">{{ $ref['counts']['done'] }} đã có ảnh phù hợp</span>
            <span class="va-tag amber">{{ $ref['counts']['pending'] }} đang chờ xử lý/duyệt</span>
            <span class="va-tag dg">{{ $ref['counts']['missing'] }} còn thiếu cần tạo</span>
        </div>
        <div class="va-body">
            <div class="va-brief">
                @if($ref['error'] !== null)
                    <div class="line"><span class="k">Chưa lập được</span><span class="v" style="color:var(--vp-red)">{{ $ref['error'] }}</span></div>
                @else
                    <div class="line"><span class="k">Nguồn</span><span class="v">kế hoạch shot {{ substr($preview['source']['shot_stage_id'], 0, 8) }} · thiết kế {{ substr($preview['source']['design_stage_id'], 0, 8) }} · model sheet {{ substr($preview['source']['model_sheet_artifact_id'], 0, 8) }} ({{ substr($preview['source']['model_sheet_sha256'], 0, 12) }})</span></div>
                    <div class="line">
                        <span class="k">Kế hoạch</span>
                        <span class="v">
                            @if($stage !== null)
                                đã lập — rev {{ $stage->planning_revision }} · {{ substr((string) $stage->id, 0, 8) }} · {{ $plan['version'] }} / {{ $plan['schema'] }}
                            @else
                                chưa lập cho nguồn hiện hành
                            @endif
                        </span>
                    </div>
                @endif
                @if($review['task'] !== null)
                    @include('video-projects.partials.task-state', ['task' => $review['task'], 'projectId' => $id])
                @endif
                @if($review['stream'] !== null)
                    @include('video-projects.partials.stream-state', ['stream' => $review['stream']])
                @endif
                @if($review['error'] !== null)
                    <div class="line"><span class="k">Lượt kiểm prompt gần nhất lỗi</span><span class="v" style="color:var(--vp-red)">{{ $review['error'] }}</span></div>
                @endif
                @if(($review['latest']['issues'] ?? []) !== [])
                    @foreach($review['latest']['issues'] as $issue)
                        <div class="line"><span class="v" style="color:var(--vp-red)">Lỗi kiểm: {{ $issue }}</span></div>
                    @endforeach
                @endif
            </div>
        </div>
    </div>

    @if($plan !== null)
        <div class="vp-panel va-anchor">
            <div class="va-head"><b>MASTER REFERENCE</b><em>người dùng chọn lưới và size — một master, mỗi part một ô, ô dư để trống</em></div>
            <div class="va-rows vr-rows">
                <div class="va-row va-row-head"><div>Lưới</div><div>Master</div><div>Prompt</div><div>Render</div><div>Candidate images</div></div>
                <form method="POST" action="{{ route('video-projects.parts-detail', [$id, 'layout']) }}" id="refLayout" onsubmit="return vpLockForm(this)" hidden>
                    @csrf
                    @if($ref['sheets'] === [])
                        <input type="hidden" name="size" value="{{ $choice['size'] }}">
                        <input type="hidden" name="model" value="{{ $choice['model'] }}">
                        <input type="hidden" name="quality" value="{{ $choice['quality'] }}">
                    @endif
                </form>

                @foreach($rows as $sheet)
                    <div class="va-row">
                        <div class="va-row-who">
                            <div class="vr-grid-fields">
                                <div class="va-field">
                                    <label>Hàng</label>
                                    <select class="ctl" name="rows" form="refLayout" required onchange="this.form.requestSubmit()">
                                        @foreach(range($grid['min'], $grid['max']) as $number)
                                            <option value="{{ $number }}" @selected($number === $choice['rows'])>{{ $number }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="va-field">
                                    <label>Cột</label>
                                    <select class="ctl" name="columns" form="refLayout" required onchange="this.form.requestSubmit()">
                                        @foreach(range($grid['min'], $grid['max']) as $number)
                                            <option value="{{ $number }}" @selected($number === $choice['columns'])>{{ $number }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="va-lbl" style="font-weight:400">Tổng part được yêu cầu: {{ $grid['total'] }}</div>
                            <div class="va-lbl" style="font-weight:400">Đã có crop được duyệt: {{ $grid['approved'] }}</div>
                            <div class="va-lbl" style="font-weight:400">Còn thiếu/chưa duyệt: {{ $grid['unapproved'] }}</div>
                            <div class="va-lbl" style="font-weight:400">Lưới: {{ $choice['rows'] }} × {{ $choice['columns'] }}</div>
                            <div class="va-lbl" style="font-weight:400">Sức chứa: {{ $grid['capacity'] }} ô</div>
                            <div class="va-lbl" style="font-weight:400">Sử dụng: {{ $grid['used'] }} ô</div>
                            <div class="va-lbl" style="font-weight:400">Để trống: {{ $grid['empty'] }} ô</div>
                            @if($grid['short'] > 0 && ($sheet === null || ! $oneImage($sheet['layout'])))
                                <div class="va-lbl" style="font-weight:400;color:var(--vp-red)">Thiếu {{ $grid['short'] }} ô — tăng Hàng hoặc Cột để render.</div>
                            @endif
                        </div>

                        @if($sheet === null)
                            <div class="va-row-who">
                                @if($grid['waiting'] > 0)
                                    <div class="va-lbl" style="font-weight:400">Còn {{ $grid['waiting'] }} part đang giữ ở sheet chờ quyết định bên dưới — từ chối sheet đó để render lại.</div>
                                @else
                                    <div class="va-lbl" style="font-weight:400">Không còn ô nào cần tạo master.</div>
                                @endif
                            </div>
                            <div></div>
                            <div></div>
                            <div></div>
                        @else
                            @php
                                $renderId = 'refRender'.$sheet['id'];
                                $parts = array_column($sheet['cells'], 'reference');
                                $single = $oneImage($sheet['layout']);
                            @endphp
                            <div class="va-row-who">
                                <b>{{ $parts[0]['part'] }}{{ count($parts) > 1 ? '–'.$parts[count($parts) - 1]['part'] : '' }}</b>
                                @if($single)
                                    <div class="va-lbl" style="font-weight:400">Một ảnh nguyên tấm {{ $pixels($sheet['layout']['size']) }}, không lưới, không cắt</div>
                                @else
                                    <div class="va-lbl" style="font-weight:400">Lưới {{ $sheet['layout']['rows'] }} × {{ $sheet['layout']['columns'] }} · {{ $pixels($sheet['layout']['size']) }} · ô {{ $sheet['cell_size']['width'] }} × {{ $sheet['cell_size']['height'] }} · {{ count($sheet['cell_ids']) }} part</div>
                                @endif
                                @foreach($sheet['cells'] as $cell)
                                    @php
                                        $part = $cell['reference'];
                                    @endphp
                                    <div class="vr-cell">
                                        <div class="va-lbl" style="font-weight:400">
                                            @if($single)
                                                {{ $part['part'] }}: {{ $part['name'] }}
                                            @elseif($cell['position'] !== null)
                                                Hàng {{ $cell['position']['row'] }} · cột {{ $cell['position']['column'] }} — {{ $part['part'] }}: {{ $part['name'] }}
                                            @else
                                                Chưa xếp được — {{ $part['part'] }}: {{ $part['name'] }}
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <div class="va-row-prompt">
                                @if($sheet['prompt'] !== null)
                                    <div class="va-lbl">PROMPT GỬI ĐI <em>(ghép từ dữ liệu thiết kế)</em></div>
                                    <textarea class="va-ta" readonly>{{ $sheet['prompt'] }}</textarea>
                                    <div class="va-count"><span>{{ mb_strlen($sheet['prompt']) }}</span> ký tự</div>
                                @endif
                                @if($sheet['prompt_error'] !== null)
                                    <div class="va-lbl" style="font-weight:400;color:var(--vp-red)">{{ $sheet['prompt_error'] }}</div>
                                @endif
                                @if($ref['source_image'] !== null)
                                    <div class="va-lbl">ẢNH NGUỒN <em>(model sheet đã duyệt, gửi nguyên tấm)</em></div>
                                    <a href="{{ $ref['source_image']['url'] }}" target="_blank" rel="noopener" class="va-cand vr-source">
                                        <img src="{{ $ref['source_image']['url'] }}" alt="{{ $ref['source_image']['code'] }}" width="3840" height="2160" loading="lazy">
                                    </a>
                                    <div class="va-lbl" style="font-weight:400">{{ $ref['source_image']['code'] }} ({{ substr($preview['source']['model_sheet_sha256'], 0, 12) }})</div>
                                @endif
                            </div>

                            <div class="va-row-set">
                                <form method="POST" action="{{ route('video-projects.parts-detail', [$id, 'render']) }}" id="{{ $renderId }}" data-modal="confirm{{ $renderId }}" onsubmit="return vpLockForm(this)" hidden>
                                    @csrf
                                    <input type="hidden" name="hash" value="{{ $preview['hash'] }}">
                                    @foreach($sheet['cell_ids'] as $cellId)<input type="hidden" name="cells[]" value="{{ $cellId }}">@endforeach
                                    <input type="hidden" name="sheet_hash" value="{{ $sheet['hash'] }}">
                                    <input type="hidden" name="model" value="{{ $choice['model'] }}">
                                    <input type="hidden" name="quality" value="{{ $choice['quality'] }}">
                                    <input type="hidden" name="operation_id" value="{{ \Illuminate\Support\Str::uuid() }}">
                                    <input type="hidden" name="confirm_cost" value="1">
                                </form>
                                <div class="va-fields">
                                    <div class="va-field">
                                        <label>Asset Name <em>(mã dự kiến)</em></label>
                                        <div class="ctl"><span>{{ $ref['next_image_code'] }}</span><span class="cnt">{{ strlen($ref['next_image_code']) }}/100</span></div>
                                    </div>
                                    <div class="va-field">
                                        <label>Size</label>
                                        <select class="ctl" name="size" form="refLayout" required onchange="this.form.requestSubmit()">
                                            @foreach((array) ($model['controls']['sizes'] ?? []) as $size)
                                                <option value="{{ $size }}" @selected($size === $choice['size'])>{{ $pixels($size) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="va-field">
                                        <label>Model</label>
                                        <select class="ctl" name="model" form="refLayout" required data-ref-model onchange="this.form.requestSubmit()">
                                            @foreach($ref['models'] as $m)
                                                <option value="{{ $m['model'] }}" @selected($m['model'] === $choice['model'])>{{ $m['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="va-field">
                                        <label>Quality</label>
                                        <select class="ctl" name="quality" form="refLayout" required data-ref-quality onchange="this.form.requestSubmit()">
                                            @foreach((array) ($model['controls']['qualities'] ?? []) as $quality)
                                                <option value="{{ $quality }}" @selected($quality === $choice['quality'])>{{ $quality }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="va-field"><label>Variations <em>(cố định)</em></label><select class="ctl" disabled><option>1</option></select></div>
                                </div>
                                <div class="va-lbl" style="font-weight:400">Mỗi ô: {{ $sheet['cell_size']['width'] }} × {{ $sheet['cell_size']['height'] }} px</div>
                                <div class="va-lbl" style="font-weight:400" data-ref-price data-prices='@json($ref['prices'])'>Dự toán: —</div>
                                <div class="va-foot">
                                    <button type="button" class="vp-btn pri" data-toggle="modal" data-target="#confirm{{ $renderId }}"
                                            @if(! $sheet['renderable']) disabled title="{{ $sheet['prompt_error'] ?? 'Master này chưa render được' }}" @endif
                                            data-busy="Đang render…">Render Image</button>
                                    @include('modal.confirm_action', ['id' => 'confirm'.$renderId, 'form' => $renderId, 'content' => 'Gửi prompt master '.$sheet['id'].' ('.($single ? 'một ảnh, không lưới' : count($sheet['cell_ids']).' part, lưới '.$sheet['layout']['rows'].' × '.$sheet['layout']['columns']).') kèm ảnh nguồn cho model đã chọn: một request, một ảnh '.$pixels($sheet['layout']['size']).' — TÁC VỤ NÀY TÍNH TIỀN.', 'detail' => 'Xem dự toán ở cột Render.'])
                                </div>
                            </div>

                            <div class="va-row-cands">
                                <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">Chưa có master cho các ô này — master đã render nằm ở các hàng bên dưới.</div>
                            </div>
                        @endif
                    </div>
                @endforeach

                @foreach($ref['masters'] as $entry)
                    @php
                        $image = $entry['image'];
                        $spec = (array) $image->prompt_spec_json;
                        $layout = (array) ($spec['layout'] ?? []);
                        $columns = max(1, (int) ($layout['columns'] ?? 1));
                        $pinnedCells = array_column((array) ($spec['cells'] ?? []), null, 'id');
                    @endphp
                    <div class="va-row">
                        <div class="va-row-who">
                            <div class="va-lbl" style="font-weight:400">Lưới: {{ $layout['rows'] ?? '—' }} × {{ $layout['columns'] ?? '—' }}</div>
                            <div class="va-lbl" style="font-weight:400">{{ $spec['size'] ?? '' }}</div>
                        </div>
                        <div class="va-row-who">
                            <b>{{ $image->image_code }}</b>
                            <span class="va-tag">{{ $image->status }}</span>
                            <span class="va-tag {{ ($spec['regime'] ?? '') === 'grey' ? 'mute' : 'blue' }}">{{ $regimeLabel[$spec['regime'] ?? 'colour'] ?? '' }}</span>
                            @foreach((array) ($spec['cells'] ?? []) as $index => $cell)
                                @php
                                    $current = $references[$cell['id']] ?? null;
                                @endphp
                                <div class="vr-cell">
                                    @if(isset($cell['part']))
                                        <div class="va-lbl">Hàng {{ intdiv($index, $columns) + 1 }} · cột {{ $index % $columns + 1 }} — {{ $cell['part'] }}{{ $current === null ? '' : ': '.$current['name'] }}</div>
                                    @else
                                        <div class="va-lbl">Ô {{ $index + 1 }} · {{ $current['key'] ?? $cell['id'] }}</div>
                                    @endif
                                    @if($current === null || $current['fingerprint'] !== $cell['fingerprint'])
                                        <span class="va-tag dg">KHÔNG CÒN KHỚP KẾ HOẠCH</span>
                                    @else
                                        {!! $tag($ref['state'][$cell['id']]['status']) !!}
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        <div class="va-row-prompt">
                            <div class="va-lbl">PROMPT ĐÃ GỬI</div>
                            <textarea class="va-ta" readonly>{{ $spec['prompt'] ?? '' }}</textarea>
                            <div class="va-lbl" style="font-weight:400">Ảnh đầu vào: @foreach((array) ($spec['sources'] ?? []) as $source){{ $source['kind'] ?? 'ảnh' }} {{ substr((string) $source['sha256'], 0, 12) }} @endforeach</div>
                        </div>
                        <div class="va-row-set">
                            <div class="va-lbl" style="font-weight:400">{{ $spec['model'] ?? '' }} · {{ $spec['quality'] ?? '' }} · {{ $spec['size'] ?? '' }} · {{ $image->created_at?->format('Y-m-d H:i') }}</div>
                            @if($entry['task'] !== null)
                                @include('video-projects.partials.task-state', ['task' => $entry['task'], 'projectId' => $id])
                            @endif
                            @if($image->render_error)
                                <div class="alert alert-danger">{{ $image->render_error }}</div>
                            @endif
                        </div>
                        <div class="va-row-cands">
                            @forelse($entry['artifacts'] as $item)
                                @php
                                    $artifact = $item['artifact'];
                                @endphp
                                <div class="va-cell">
                                    <span class="code">{{ $image->image_code }}</span>
                                    <span class="va-tag">{{ \App\Enums\DesignImageStatus::tryFrom($image->status)?->label() ?? $image->status }}</span>
                                    <span class="grow"></span>
                                    <span class="d">{{ $spec['model'] ?? '' }} · {{ $spec['size'] ?? '' }}</span>
                                </div>
                                <div class="va-cands">
                                    <div class="va-cand">
                                        <div class="vr-overlay">
                                            <img src="{{ route('video-artifacts.show', $artifact->id) }}" alt="Master {{ $image->image_code }}" width="{{ $artifact->width }}" height="{{ $artifact->height }}">
                                            @foreach((array) ($item['processing']['boxes'] ?? []) as $box)
                                                <span class="vr-box" style="left:{{ round($box['x'] / $artifact->width * 100, 3) }}%;top:{{ round($box['y'] / $artifact->height * 100, 3) }}%;width:{{ round($box['width'] / $artifact->width * 100, 3) }}%;height:{{ round($box['height'] / $artifact->height * 100, 3) }}%"></span>
                                            @endforeach
                                        </div>
                                        <div class="meta">
                                            <span>{{ $image->image_code }}</span>
                                            <span>Model: {{ $spec['model'] ?? '' }}</span>
                                            <span>{{ $artifact->width }}×{{ $artifact->height }}</span>
                                            <span>Created: {{ $artifact->created_at?->format('Y-m-d H:i:s') ?? '—' }}</span>
                                        </div>
                                    </div>
                                </div>
                                @if(($item['processing']['status'] ?? null) !== null && ($item['processing']['status'] ?? null) !== 'processed')
                                    <div class="alert alert-danger">{{ $item['processing']['error'] ?? 'Xử lý master lỗi.' }}</div>
                                @endif
                                <div class="vr-crops">
                                    @foreach($item['crops'] as $crop)
                                        @php
                                            $cropPart = $pinnedCells[$crop['view_id']]['part'] ?? null;
                                        @endphp
                                        <div class="vr-crop">
                                            @if($oneImage($layout))
                                                <div class="va-lbl" style="font-weight:400">Một ảnh nguyên tấm, không cắt</div>
                                            @else
                                                <a href="{{ $crop['url'] }}" target="_blank" rel="noopener" class="va-cand" title="{{ $crop['view_id'] }} · ({{ $crop['x'] }}, {{ $crop['y'] }}) · {{ $crop['width'] }}×{{ $crop['height'] }}">
                                                    <img src="{{ $crop['url'] }}" alt="{{ $cropPart ?? $crop['view_id'] }}" width="{{ $crop['width'] }}" height="{{ $crop['height'] }}" loading="lazy">
                                                </a>
                                            @endif
                                            <div class="va-lbl" style="font-weight:400">{{ $cropPart === null ? ($references[$crop['view_id']]['key'] ?? $crop['view_id']) : $cropPart.($references[$crop['view_id']]['name'] ?? null ? ': '.$references[$crop['view_id']]['name'] : '') }}</div>
                                            <div class="va-lbl" style="font-weight:400">{{ $crop['width'] }} × {{ $crop['height'] }}</div>
                                        </div>
                                    @endforeach
                                </div>
                                @if($item['revoked'])
                                    <div class="va-lbl" style="font-weight:400">Đã thu hồi duyệt — sheet đang chờ quyết định: duyệt lại để dùng, hoặc từ chối cả sheet để render lại các part.</div>
                                @endif
                                <div class="va-foot">
                                    @if($image->status === 'approved')
                                        <span class="va-tag ok">Đã duyệt cả sheet</span>
                                        <form method="POST" action="{{ route('video-projects.parts-detail', [$id, 'revoke']) }}" onsubmit="return vpLockForm(this)">
                                            @csrf
                                            <input type="hidden" name="artifact_id" value="{{ $artifact->id }}">
                                            <button class="vp-btn sm" data-busy="Đang thu hồi…">↩ Thu hồi duyệt</button>
                                        </form>
                                    @elseif($image->status === 'rendered')
                                        <form method="POST" action="{{ route('video-projects.parts-detail', [$id, 'process']) }}" onsubmit="return vpLockForm(this)">
                                            @csrf
                                            <input type="hidden" name="artifact_id" value="{{ $artifact->id }}">
                                            <button class="vp-btn sm" data-busy="Đang xử lý…">{{ $oneImage($layout) ? 'Lưu ảnh để duyệt' : ($item['crops'] === [] ? 'Cắt ảnh từ master' : 'Xử lý lại từ master') }}</button>
                                        </form>
                                        <form method="POST" action="{{ route('video-projects.parts-detail', [$id, 'reject']) }}" onsubmit="return vpLockForm(this)">
                                            @csrf
                                            <input type="hidden" name="artifact_id" value="{{ $artifact->id }}">
                                            <button class="vp-btn sm dg" data-busy="Đang từ chối…">✕ Từ chối cả sheet</button>
                                        </form>
                                        <form method="POST" action="{{ route('video-projects.parts-detail', [$id, 'approve']) }}" onsubmit="return vpLockForm(this)">
                                            @csrf
                                            <input type="hidden" name="artifact_id" value="{{ $artifact->id }}">
                                            <button class="vp-btn sm ok" data-busy="Đang duyệt…" @disabled(! $item['approvable'])>✓ Duyệt và dùng cả sheet</button>
                                        </form>
                                    @endif
                                </div>
                            @empty
                                <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">Chưa có ảnh.</div>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
