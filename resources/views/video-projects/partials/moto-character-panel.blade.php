@php
    $people = $motoCharacters;
    $peopleTag = static fn (string $status): string => '<span class="va-tag '.($people['labels'][$status][0] ?? 'mute').'">'.e($people['labels'][$status][1] ?? $status).'</span>';
    $worn = static fn (array $items): string => $items === [] ? 'bảo hộ trống' : 'bảo hộ: '.implode(', ', $items);
    $kindNames = ['angle' => 'Góc nhận diện', 'item' => 'Trang phục/bảo hộ', 'look' => 'Ảnh phục vụ shot'];
    $useLine = static fn (array $cell): string => $cell['shots'] !== [] ? 'Shot: '.implode(', ', $cell['shots']) : (($cell['kind'] ?? '') === 'angle' ? 'Bộ nhận diện — không gửi vào keyframe' : 'Món riêng, không người mặc — không gửi vào keyframe');
    $gridLine = static fn (array $entry): string => $entry['cuts'] ? 'Lưới: '.($entry['spec']['layout']['rows'] ?? '—').' × '.($entry['spec']['layout']['columns'] ?? '—') : 'Một ảnh nguyên tấm';
    $mastersOf = collect($people['masters'])->groupBy(static fn (array $entry): string => (string) ($entry['spec']['character'] ?? ''));
    $groups = array_map(static fn (array $row): array => $row + ['masters' => $mastersOf->get($row['character'], collect())->all()], $people['rows']);

    foreach ($mastersOf->except(array_column($people['rows'], 'character')) as $character => $entries) {
        $groups[] = ['character' => (string) $character, 'description' => null, 'cells' => [], 'sheet' => null, 'masters' => $entries->all()];
    }
@endphp

<div class="vp-panel va-anchor" id="motoCharacters">
    <div class="va-head">
        <b>NHÂN VẬT</b>
        <em>mỗi ảnh là một bộ trang phục và trạng thái mà shot cần — tách khỏi part xe</em>
    </div>
    <div class="va-body">
        <div class="va-brief">
            <div class="line"><span class="k">Part xe</span><span class="v">{{ $ref['grid']['approved'] }}/{{ $ref['grid']['total'] }}</span></div>
            <div class="line"><span class="k">Ảnh nhân vật</span><span class="v">{{ $people['approved'] }}/{{ $people['total'] }}</span></div>
            @foreach($kindNames as $kind => $name)
                @if(isset($people['kinds'][$kind]))
                    <div class="line"><span class="k">{{ $name }}</span><span class="v">{{ $people['kinds'][$kind]['approved'] }}/{{ $people['kinds'][$kind]['total'] }}</span></div>
                @endif
            @endforeach
            @if($people['error'] !== null)
                <div class="line"><span class="k">Chưa lập được</span><span class="v" style="color:var(--vp-red)">{{ $people['error'] }}</span></div>
            @endif
            @foreach($people['problems'] as $problem)
                <div class="line"><span class="k">Cần kiểm</span><span class="v" style="color:var(--vp-amber-fg)">{{ $problem }}</span></div>
            @endforeach
        </div>
    </div>

    @if($groups !== [])
        <div class="va-rows vr-rows">
            <div class="va-row va-row-head"><div>Lưới</div><div>Master</div><div>Prompt</div><div>Render</div><div>Candidate images</div></div>

            @foreach($groups as $row)
                @php
                    $sheet = $row['sheet'];
                    $latest = $row['masters'][0] ?? null;
                @endphp
                <div class="va-row">
                    <div class="va-row-who">
                        <b>{{ ucfirst($row['character']) }}</b>
                        @if($sheet !== null)
                            <div class="va-lbl" style="font-weight:400">{{ $sheet['single'] ? 'Một ảnh nguyên tấm' : 'Lưới: '.$sheet['layout']['rows'].' × '.$sheet['layout']['columns'] }}</div>
                        @elseif($latest !== null)
                            <div class="va-lbl" style="font-weight:400">{{ $gridLine($latest) }}</div>
                            <div class="va-lbl" style="font-weight:400">{{ $latest['spec']['size'] ?? '' }}</div>
                        @endif
                    </div>
                    <div class="va-row-who">
                        @if($row['description'] !== null || $row['cells'] !== [])
                            <details>
                                <summary class="va-lbl">{{ $row['description'] !== null ? 'Mô tả kịch bản và ' : '' }}{{ count($row['cells']) }} ô — xem</summary>
                                @if($row['description'] !== null)
                                    <div class="va-lbl" style="font-weight:400">Mô tả kịch bản: {{ $row['description'] }}</div>
                                    <div class="va-lbl" style="font-weight:400;color:var(--vp-amber-fg)">Chỉ dùng đúng mô tả này; điều mô tả không ghi (như quần, giày, chiều cao) chưa được xác định.</div>
                                @endif
                                @foreach($row['cells'] as $cell)
                                    <div class="vr-cell">
                                        <div class="va-lbl">
                                            {{ $cell['code'] }}
                                            @if($sheet !== null && ! $sheet['single'] && isset($sheet['positions'][$cell['id']]))
                                                · hàng {{ $sheet['positions'][$cell['id']]['row'] }}, cột {{ $sheet['positions'][$cell['id']]['column'] }}
                                            @endif
                                            — {{ $cell['label'] }}
                                        </div>
                                        {!! $peopleTag($cell['status']) !!}
                                        <div class="va-lbl" style="font-weight:400">{{ $useLine($cell) }}</div>
                                    </div>
                                @endforeach
                            </details>
                        @endif
                    </div>
                    <div class="va-row-prompt">
                        @if($sheet !== null)
                            <div class="vr-block">
                                <div class="va-lbl">PROMPT GỬI ĐI <em>(ghép từ kịch bản và shot đã duyệt)</em></div>
                                @if($sheet['prompt'] !== null)
                                    <textarea class="va-ta" readonly>{{ $sheet['prompt'] }}</textarea>
                                    <div class="va-count"><span>{{ mb_strlen($sheet['prompt']) }}</span> ký tự</div>
                                @endif
                                @if($sheet['prompt_error'] !== null)
                                    <div class="va-lbl" style="font-weight:400;color:var(--vp-red)">{{ $sheet['prompt_error'] }}</div>
                                @endif
                                <div class="va-lbl" style="font-weight:400">Không có ảnh đầu vào.</div>
                            </div>
                        @endif
                        @foreach($row['masters'] as $entry)
                            <div class="vr-block">
                                <div class="va-lbl">PROMPT ĐÃ GỬI <em>({{ $entry['image']->image_code }})</em></div>
                                <textarea class="va-ta" readonly>{{ $entry['spec']['prompt'] ?? '' }}</textarea>
                            </div>
                        @endforeach
                    </div>
                    <div class="va-row-set">
                        @if($sheet !== null)
                            @php
                                $choice = $sheet['choice'];
                                $settingsId = 'charSettings'.$sheet['id'];
                                $renderId = 'charRender'.$sheet['id'];
                            @endphp
                            <form method="POST" action="{{ route('video-projects.character-reference', [$id, $row['character'], 'settings']) }}" id="{{ $settingsId }}" onsubmit="return vpLockForm(this)">
                                @csrf
                                <div class="va-fields">
                                    <div class="va-field">
                                        <label>Size</label>
                                        <select class="ctl" name="size" required onchange="this.form.requestSubmit()">
                                            @foreach($choice['sizes'] as $size)
                                                <option value="{{ $size }}" @selected($size === $choice['size'])>{{ str_replace('x', ' × ', $size) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="va-field">
                                        <label>Model</label>
                                        <select class="ctl" name="model" required onchange="this.form.requestSubmit()">
                                            @foreach($people['models'] as $model)
                                                <option value="{{ $model['model'] }}" @selected($model['model'] === $choice['model'])>{{ $model['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="va-field">
                                        <label>Quality</label>
                                        <select class="ctl" name="quality" required onchange="this.form.requestSubmit()">
                                            @foreach($choice['qualities'] as $quality)
                                                <option value="{{ $quality }}" @selected($quality === $choice['quality'])>{{ $quality }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="va-field"><label>Variations <em>(cố định)</em></label><select class="ctl" disabled><option>1</option></select></div>
                                </div>
                            </form>
                            @unless($sheet['single'])
                                <div class="va-lbl" style="font-weight:400">Mỗi ô: {{ $sheet['cell_width'] }} × {{ $sheet['cell_height'] }} px</div>
                            @endunless
                            <div class="va-lbl" style="font-weight:400">
                                @if($sheet['price'] !== null)
                                    Dự toán: ${{ number_format((float) $sheet['price']['usd'], 3) }} / ảnh (bảng giá {{ $sheet['price']['version'] }}, ước tính, chưa phản ánh size)
                                @else
                                    Dự toán: chưa có giá cho model/chất lượng này
                                @endif
                            </div>
                            <form method="POST" action="{{ route('video-projects.character-reference', [$id, $row['character'], 'render']) }}" id="{{ $renderId }}" data-modal="confirm{{ $renderId }}" onsubmit="return vpLockForm(this)" hidden>
                                @csrf
                                <input type="hidden" name="sheet_hash" value="{{ $sheet['hash'] }}">
                                <input type="hidden" name="operation_id" value="{{ \Illuminate\Support\Str::uuid() }}">
                                <input type="hidden" name="confirm_cost" value="1">
                            </form>
                            <div class="va-foot">
                                <button type="button" class="vp-btn pri" data-toggle="modal" data-target="#confirm{{ $renderId }}" data-busy="Đang render…"
                                        @if($sheet['prompt'] === null) disabled title="{{ $sheet['prompt_error'] }}" @endif>Render Image</button>
                                @include('modal.confirm_action', ['id' => 'confirm'.$renderId, 'form' => $renderId, 'content' => 'Gửi prompt ảnh nhân vật '.$row['character'].' ('.($sheet['single'] ? 'một ảnh, không lưới' : count($sheet['cell_ids']).' ô, lưới '.$sheet['layout']['rows'].' × '.$sheet['layout']['columns']).') cho model đã chọn: một request, một ảnh '.str_replace('x', ' × ', $choice['size']).' — TÁC VỤ NÀY TÍNH TIỀN.', 'detail' => 'Xem dự toán ở cột Render.'])
                            </div>
                        @endif
                    </div>
                    <div class="va-row-cands">
                        @forelse($row['masters'] as $entry)
                            @php
                                $image = $entry['image'];
                                $spec = $entry['spec'];
                                $pinned = array_column((array) ($spec['cells'] ?? []), null, 'id');
                                $action = route('video-projects.character-reference', [$id, (string) ($spec['character'] ?? 'x'), '__ACTION__']);
                            @endphp
                            <div class="vr-block">
                                <div class="va-lbl">
                                    {{ $image->image_code }}
                                    <span class="va-tag">{{ $image->status }}</span>
                                    @unless($entry['fresh'])
                                        <span class="va-tag dg">KHÔNG CÒN KHỚP NHU CẦU</span>
                                    @endunless
                                </div>
                                <div class="va-lbl" style="font-weight:400">{{ $gridLine($entry) }} · {{ $spec['model'] ?? '' }} · {{ $spec['quality'] ?? '' }} · {{ $spec['size'] ?? '' }} · {{ $image->created_at?->format('Y-m-d H:i') }}</div>
                                @unless($entry['fresh'])
                                    @foreach($pinned as $cell)
                                        <div class="vr-cell">
                                            <div class="va-lbl">{{ $cell['code'] }} — {{ $cell['label'] ?? (($cell['view'] ?? '').' · '.$worn((array) ($cell['items'] ?? []))) }}</div>
                                        </div>
                                    @endforeach
                                @endunless
                                @if($entry['task'] !== null)
                                    @include('video-projects.partials.task-state', ['task' => $entry['task'], 'projectId' => $id])
                                @endif
                                @if($image->render_error)
                                    <div class="alert alert-danger">{{ $image->render_error }}</div>
                                @endif
                                @forelse($entry['artifacts'] as $item)
                                    @php
                                        $artifact = $item['artifact'];
                                    @endphp
                                    <div class="va-cands">
                                        <div class="va-cand">
                                            <a href="{{ route('video-artifacts.show', $artifact->id) }}" target="_blank" rel="noopener">
                                                <img src="{{ route('video-artifacts.show', $artifact->id) }}" alt="{{ $image->image_code }}" width="{{ $artifact->width }}" height="{{ $artifact->height }}" loading="lazy">
                                            </a>
                                            <div class="meta">
                                                <span>{{ $image->image_code }}</span>
                                                <span>{{ $artifact->width }}×{{ $artifact->height }}</span>
                                                <span>Created: {{ $artifact->created_at?->format('Y-m-d H:i:s') ?? '—' }}</span>
                                            </div>
                                        </div>
                                    </div>
                                    @if(($item['processing']['status'] ?? null) === 'failed')
                                        <div class="alert alert-danger">{{ $item['processing']['error'] ?? 'Xử lý master lỗi.' }}</div>
                                    @endif
                                    @if($item['crops'] !== [])
                                        <div class="vr-crops">
                                            @foreach($item['crops'] as $crop)
                                                <div class="vr-crop">
                                                    <a href="{{ $crop['url'] }}" target="_blank" rel="noopener" class="va-cand" title="({{ $crop['x'] }}, {{ $crop['y'] }}) · {{ $crop['width'] }}×{{ $crop['height'] }}">
                                                        <img src="{{ $crop['url'] }}" alt="{{ $pinned[$crop['view_id']]['code'] ?? $crop['view_id'] }}" width="{{ $crop['width'] }}" height="{{ $crop['height'] }}" loading="lazy">
                                                    </a>
                                                    <div class="va-lbl" style="font-weight:400">{{ $pinned[$crop['view_id']]['code'] ?? $crop['view_id'] }} · {{ $crop['width'] }} × {{ $crop['height'] }}</div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                    @if($item['revoked'])
                                        <div class="va-lbl" style="font-weight:400">Đã thu hồi duyệt — đang chờ quyết định: duyệt lại để dùng, hoặc từ chối để render lại.</div>
                                    @endif
                                    <div class="va-foot">
                                        @if($image->status === 'approved')
                                            <span class="va-tag ok">Đã duyệt</span>
                                            <form method="POST" action="{{ str_replace('__ACTION__', 'revoke', $action) }}" onsubmit="return vpLockForm(this)">
                                                @csrf
                                                <input type="hidden" name="artifact_id" value="{{ $artifact->id }}">
                                                <button class="vp-btn sm" data-busy="Đang thu hồi…">↩ Thu hồi duyệt</button>
                                            </form>
                                        @elseif($image->status === 'rendered')
                                            @if($entry['cuts'])
                                                <form method="POST" action="{{ str_replace('__ACTION__', 'process', $action) }}" onsubmit="return vpLockForm(this)">
                                                    @csrf
                                                    <input type="hidden" name="artifact_id" value="{{ $artifact->id }}">
                                                    <button class="vp-btn sm" data-busy="Đang xử lý…">{{ $item['crops'] === [] ? 'Cắt ảnh từ master' : 'Xử lý lại từ master' }}</button>
                                                </form>
                                            @endif
                                            <form method="POST" action="{{ str_replace('__ACTION__', 'reject', $action) }}" onsubmit="return vpLockForm(this)">
                                                @csrf
                                                <input type="hidden" name="artifact_id" value="{{ $artifact->id }}">
                                                <button class="vp-btn sm dg" data-busy="Đang từ chối…">✕ {{ $entry['cuts'] ? 'Từ chối cả master' : 'Từ chối ảnh' }}</button>
                                            </form>
                                            <form method="POST" action="{{ str_replace('__ACTION__', 'approve', $action) }}" onsubmit="return vpLockForm(this)">
                                                @csrf
                                                <input type="hidden" name="artifact_id" value="{{ $artifact->id }}">
                                                <button class="vp-btn sm ok" data-busy="Đang duyệt…" @disabled(! $item['approvable'])>✓ {{ $entry['cuts'] ? 'Duyệt và dùng cả master' : 'Duyệt và dùng ảnh' }}</button>
                                            </form>
                                        @endif
                                    </div>
                                @empty
                                    <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">Chưa có ảnh.</div>
                                @endforelse
                            </div>
                        @empty
                            <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">Chưa có ảnh.</div>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
