@extends('layouts.base', ['title' => 'Environment Library'])
@section('title', 'Environment Library')

@section('css')
<link rel="stylesheet" href="{{ asset('assets/css/video-producer.css') }}?v={{ filemtime(public_path('assets/css/video-producer.css')) }}">
<style>
.ve-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.25fr) 232px minmax(0, 1.35fr);
    overflow: hidden;
    background: #fff;
    border: 1px solid var(--vp-line);
    border-radius: 8px;
}

.ve-name {
    display: block;
    margin-bottom: 6px;
    color: var(--vp-ink);
    font-size: .86rem;
    font-weight: 700;
}

.ve-key {
    color: var(--vp-faint);
    font-family: var(--vp-mono);
    font-size: .68rem;
}

.ve-grid .va-ta {
    min-height: 148px;
}

.ve-set {
    display: grid;
    grid-template-columns: 1fr;
    gap: 8px;
}

.ve-set[hidden] {
    display: none;
}

.ve-set .va-field label {
    display: block;
    margin-bottom: 3px;
    color: var(--vp-dim);
    font-size: .66rem;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
}

.ve-set .ctl {
    width: 100%;
}

.ve-locked {
    color: var(--vp-dim);
    font-size: .68rem;
    line-height: 1.45;
}

.ve-plates {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.ve-empty {
    color: var(--vp-dim);
    font-size: .72rem;
}

@media (max-width: 1200px) {
    .ve-grid {
        grid-template-columns: 1fr;
    }

    .ve-grid .vs-h {
        display: none;
    }

    .ve-grid .vs-c + .vs-c {
        border-left: 0;
    }
}
</style>
@endsection

@section('content')
<div class="container-fluid vp">

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
        $cells = collect($environmentCells);
        $byKey = $cells->groupBy('environment_key');
        $approvedKeys = $cells
            ->where('status', \App\Enums\DesignImageStatus::APPROVED->value)
            ->pluck('environment_key')
            ->filter()
            ->unique();
    @endphp

    <div class="va-page">
        <div>
            <h1>Environment Library</h1>
            <p>Render tấm nền sạch cho từng môi trường — không có con tàu nào trong khung hình</p>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
            <span class="va-tag {{ $approvedKeys->count() === count($environments) && $environments !== [] ? 'ok' : 'mute' }}">
                {{ $approvedKeys->count() }} / {{ count($environments) }} ĐÃ DUYỆT
            </span>
            <a class="vp-btn" href="{{ route('video-projects.reference', $id) }}">← Reference Views</a>
        </div>
    </div>

    @if($mediaModelsError)
        <div class="alert alert-danger">{{ $mediaModelsError }}</div>
    @endif

    @if($environments === [])
        <div class="vp-panel">
            <div class="va-head">
                <b>ENVIRONMENT LIBRARY</b>
            </div>
            <div class="va-body">
                <div class="va-lbl" style="color:var(--vp-red);font-weight:400">
                    {{ __('messages.environment_no_profile') }}
                </div>
            </div>
        </div>
    @else
        <div class="ve-grid">

            <div class="vs-h"><b>1</b> Clean plate prompt</div>
            <div class="vs-h"><b>2</b> Render settings</div>
            <div class="vs-h"><b>3</b> Rendered plates</div>

            @foreach($environments as $environment)
                @php
                    $key = $environment['key'];
                    $rowCells = $byKey->get($key, collect());
                    $form = 'envForm_'.$key;
                    $modal = 'confirmEnv_'.$key;
                @endphp

                <div class="vs-row">

                    <div class="vs-c">
                        <span class="ve-name">{{ $environment['label'] }}</span>
                        <span class="ve-key">{{ $key }}</span>
                        @if($approvedKeys->contains($key))
                            <span class="va-tag ok" style="margin-left:6px">ĐÃ DUYỆT</span>
                        @endif

                        <textarea class="va-ta" style="margin-top:8px" readonly>{{ $environment['prompt'] }}</textarea>
                        <div class="va-count">{{ mb_strlen($environment['prompt']) }} ký tự</div>
                    </div>

                    <div class="vs-c">
                        @if($mediaModelsError)
                            <div class="ve-locked" style="color:var(--vp-red)">
                                Render đang khoá — danh sách model lỗi cấu hình.
                            </div>
                        @else
                            <form method="POST" action="{{ route('video-projects.environment', $id) }}"
                                  id="{{ $form }}" data-modal="{{ $modal }}"
                                  onsubmit="return vpLockForm(this)" hidden>
                                @csrf
                                <input type="hidden" name="environment_key" value="{{ $key }}">
                            </form>

                            <div class="ve-set">
                                <div class="va-field">
                                    <label>Model</label>
                                    <select class="ctl" name="provider_model" form="{{ $form }}"
                                            data-row="{{ $key }}" data-role="model" required>
                                        @foreach($mediaModels as $entry)
                                            <option value="{{ $entry['id'] }}" data-pricing="{{ $entry['pricing'] }}"
                                                    @selected($entry['id'] === $defaultMediaModel['id'])>{{ $entry['label'] }}{{ $entry['pricing'] === 'unpriced' ? ' · chưa định giá' : '' }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                @foreach($mediaModels as $entry)
                                    @php
                                        $active = $entry['id'] === $defaultMediaModel['id'];
                                        $controls = $entry['controls'];
                                    @endphp
                                    <div class="ve-set" data-row="{{ $key }}" data-group="{{ $entry['id'] }}"
                                         data-provider="{{ $entry['provider'] }}" data-label="{{ $entry['label'] }}"
                                         data-default-size="{{ $controls['default_size'] ?? '' }}"
                                         data-default-quality="{{ $controls['default_quality'] ?? '' }}"
                                         @unless($active) hidden @endunless>
                                        @if($entry['provider'] === 'openai')
                                            <div class="va-field">
                                                <label>Quality</label>
                                                <select class="ctl" name="quality" form="{{ $form }}" required @disabled(! $active)>
                                                    @foreach($controls['qualities'] as $quality)
                                                        <option value="{{ $quality }}"
                                                                @selected($quality === $controls['default_quality'])>{{ $quality }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="va-field">
                                                <label>Size</label>
                                                <select class="ctl" name="size" form="{{ $form }}" required @disabled(! $active)>
                                                    @foreach($controls['sizes'] as $size)
                                                        <option value="{{ $size }}"
                                                                @selected($size === $controls['default_size'])>{{ $size }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        @else
                                            <div class="va-field">
                                                <label>Quality</label>
                                                <div class="ve-locked">không áp dụng</div>
                                            </div>
                                            <div class="va-field">
                                                <label>Aspect ratio</label>
                                                <select class="ctl" name="aspect_ratio" form="{{ $form }}" required @disabled(! $active)>
                                                    @foreach($controls['aspect_ratios'] as $ratio)
                                                        <option value="{{ $ratio }}"
                                                                @selected($ratio === $controls['default_aspect_ratio'])>{{ $ratio }}{{ $ratio === $controls['default_aspect_ratio'] ? ' · khớp keyframe' : '' }}{{ in_array($ratio, $controls['proven_aspect_ratios'], true) ? '' : ' · chưa render thử' }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="va-field">
                                                <label>Image size</label>
                                                <select class="ctl" name="image_size" form="{{ $form }}" required @disabled(! $active)>
                                                    @foreach($controls['image_sizes'] as $imageSize)
                                                        <option value="{{ $imageSize }}"
                                                                @isset($controls['prices'][$imageSize]) data-usd="{{ $controls['prices'][$imageSize] }}" @endisset
                                                                @selected($imageSize === $controls['default_image_size'])>{{ $imageSize }}{{ in_array($imageSize, $controls['proven_image_sizes'], true) ? '' : ' · chưa render thử' }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        @endif
                                        <div class="va-field">
                                            <label>Variations</label>
                                            <select class="ctl" name="variations" form="{{ $form }}" required @disabled(! $active)>
                                                @for($count = 1; $count <= $entry['max_variations']; $count++)
                                                    <option value="{{ $count }}" @selected($count === 1)>{{ $count }}</option>
                                                @endfor
                                            </select>
                                        </div>
                                        @unless(in_array($entry['id'], $renderedModels, true))
                                            <div class="ve-locked ve-unproven" style="color:var(--vp-amber-fg)">
                                                Chưa có lần render Environment thật nào bằng model này.
                                            </div>
                                        @endunless
                                    </div>
                                @endforeach
                            </div>

                            <div class="va-foot" style="padding-left:0;padding-right:0">
                                <button type="button" class="vp-btn pri" data-toggle="modal"
                                        data-target="#{{ $modal }}" data-busy="Đang render…">Render Plate</button>
                            </div>

                            <div class="ve-locked">
                                Mặc định {{ $defaultMediaModel['label'] }} · {{ $defaultMediaModel['controls']['default_size'] ?? '' }}
                                · {{ $defaultMediaModel['controls']['default_quality'] ?? '' }} — cùng khổ với keyframe.
                                Ước lượng chi phí tính theo chất lượng và số ảnh, không theo khổ.
                                Khổ khác 9:16 sẽ bị ép lại khi tấm nền vào scene keyframe, và
                                "chưa render thử" nghĩa là chưa có lần render thật nào ở giá trị đó.
                            </div>

                            @include('modal.confirm_action', [
                                'id' => $modal,
                                'form' => $form,
                                'content' => 'Sinh tấm nền sạch cho "'.$environment['label'].'" — TÁC VỤ NÀY TÍNH TIỀN.',
                                'detail' => 'Đang tính…',
                                'detailId' => 'est_'.$key,
                            ])
                        @endif
                    </div>

                    <div class="vs-c">
                        @php
                            $cards = $rowCells
                                ->flatMap(fn ($cell) => collect($cell['candidates'])->map(fn ($candidate) => [
                                    'cell' => $cell,
                                    'candidate' => $candidate,
                                ]))
                                ->values();
                            $pendingCells = $rowCells->where('candidates', []);
                        @endphp

                        @if($cards->isEmpty() && $pendingCells->isEmpty())
                            <div class="ve-empty">Chưa có tấm nền nào cho môi trường này.</div>
                        @endif

                        @if($cards->isNotEmpty())
                            <div class="va-cands">
                                @foreach($cards as $card)
                                    @php
                                        $cell = $card['cell'];
                                        $candidate = $card['candidate'];
                                    @endphp
                                    <div class="va-cand">
                                        <img src="{{ $candidate['url'] }}" alt="{{ $cell['image_code'] }}"
                                             width="{{ $candidate['width'] }}" height="{{ $candidate['height'] }}">
                                        <div class="cap">
                                            <span>{{ $environment['label'] }}</span>
                                            <span class="act">
                                                <b class="{{ $cell['status_tone'] }}">{{ $cell['status_label'] }}</b>

                                                @if($cell['can_approve'])
                                                    @if($cell['selected_artifact_id'] === $candidate['id'])
                                                        <b class="ok">Đang dùng</b>
                                                    @else
                                                        <form method="POST"
                                                              action="{{ route('video-projects.environment-approve', $id) }}">
                                                            @csrf
                                                            <input type="hidden" name="artifact_id" value="{{ $candidate['id'] }}">
                                                            <button class="vp-btn ok sm">✓ Duyệt</button>
                                                        </form>
                                                    @endif
                                                @endif
                                            </span>
                                        </div>
                                        <div class="meta">
                                            <span>{{ $cell['image_code'] }}</span>
                                            <span>{{ $candidate['width'] }}×{{ $candidate['height'] }}</span>
                                            <span>{{ $cell['variations'] }} ảnh &middot; {{ $cell['quality'] !== '' ? $cell['quality'] : 'không áp dụng' }}
                                                @if($cell['cost_recorded_has_ledger'])
                                                    &middot; @include('video-projects.partials.cost-recorded', ['cell' => $cell])
                                                @endif</span>
                                            <span>Created: {{ $candidate['created_at']?->format('Y-m-d H:i:s') ?? '—' }}</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @foreach($pendingCells as $cell)
                            <div class="va-cell" style="padding-left:0;padding-right:0">
                                <span class="code">{{ $cell['image_code'] }}</span>
                                <span class="va-tag {{ $cell['status_tone'] }}">{{ $cell['status_label'] }}</span>
                                <span class="grow"></span>
                                <span class="d">{{ $cell['variations'] }} ảnh &middot; {{ $cell['quality'] !== '' ? $cell['quality'] : 'không áp dụng' }}</span>
                            </div>

                            @if($cell['render_error'])
                                <div class="alert alert-danger">{{ $cell['render_error'] }}</div>
                            @endif

                            @if($cell['is_live'])
                                <div class="ve-locked" style="color:var(--vp-amber-fg)">
                                    Đang render — tải lại trang để xem tiến độ.
                                </div>
                            @elseif($cell['can_render'] && ! $mediaModelsError)
                                <form method="POST" id="renderEnv_{{ $cell['id'] }}"
                                      action="{{ route('video-projects.design-image-enqueue', [$id, $cell['id']]) }}"
                                      data-modal="confirmRenderEnv_{{ $cell['id'] }}" onsubmit="return vpLockForm(this)">
                                    @csrf
                                </form>
                                <button type="button" class="vp-btn pri" data-toggle="modal"
                                        data-target="#confirmRenderEnv_{{ $cell['id'] }}" data-busy="Đang render…">
                                    {{ $cell['has_failed'] ? 'Render lại →' : 'Render →' }}
                                </button>
                                @include('modal.confirm_action', [
                                    'id' => 'confirmRenderEnv_'.$cell['id'],
                                    'form' => 'renderEnv_'.$cell['id'],
                                    'content' => 'Gửi ô này cho gpt-image-2 render — TÁC VỤ NÀY TÍNH TIỀN.',
                                    'detail' => $cell['variations'].' ảnh · '.($cell['quality'] !== '' ? $cell['quality'] : 'không áp dụng').' · '.$cell['size']
                                        .' — '.($cell['cost_estimate'] === null ? 'chưa định giá' : 'ước lượng $'.number_format($cell['cost_estimate'], 3)),
                                ])
                            @endif
                        @endforeach
                    </div>

                </div>
            @endforeach

        </div>

        <div class="va-lbl" style="margin-top:10px;font-weight:400;color:var(--vp-dim)">
            Profile {{ $profileVersion }} &middot; {{ $plateVersion }}
        </div>
    @endif
</div>
@endsection

@section('script')
<script src="{{ asset('assets/js/video-producer.js') }}?v={{ filemtime(public_path('assets/js/video-producer.js')) }}"></script>
<script>
(function () {
    var costs = @json($qualityCosts ?? []);
    var keys = @json(collect($environments)->pluck('key'));

    keys.forEach(function (key) {
        var box = document.getElementById('est_' + key);
        var picker = document.querySelector('[data-row="' + key + '"][data-role="model"]');
        if (!box || !picker) { return; }

        var groups = document.querySelectorAll('[data-row="' + key + '"][data-group]');

        function field(group, name) {
            return group.querySelector('[name="' + name + '"]');
        }

        function activeGroup() {
            var found = null;
            Array.prototype.forEach.call(groups, function (group) {
                var on = group.getAttribute('data-group') === picker.value;
                group.hidden = !on;
                Array.prototype.forEach.call(group.querySelectorAll('select'), function (el) {
                    el.disabled = !on;
                });
                if (on) { found = group; }
            });
            return found;
        }

        function sync() {
            var group = activeGroup();
            if (!group) { box.textContent = 'Model không có thiết lập.'; return; }

            var count = parseInt(field(group, 'variations').value, 10) || 1;
            var pricing = picker.options[picker.selectedIndex].getAttribute('data-pricing');
            var label = group.getAttribute('data-label');

            if (group.getAttribute('data-provider') !== 'openai') {
                var sizeField = field(group, 'image_size');
                var usd = sizeField.options[sizeField.selectedIndex].getAttribute('data-usd');

                box.textContent = label + ' · ' + field(group, 'aspect_ratio').value + ' · '
                    + sizeField.value + ' · ' + count + ' ảnh — '
                    + (usd === null ? 'chưa định giá' : 'ước tính $' + (parseFloat(usd) * count).toFixed(3));
                box.classList.remove('text-danger');
                box.classList.add('text-muted');
                return;
            }

            var quality = field(group, 'quality').value;
            var size = field(group, 'size').value;
            var unit = costs[quality];
            var off = [];
            if (quality !== group.getAttribute('data-default-quality')) { off.push('chất lượng'); }
            if (size !== group.getAttribute('data-default-size')) { off.push('khổ'); }

            var text = label + ' · ' + size + ' · ' + quality + ' · ' + count + ' ảnh — ước lượng '
                + (unit === undefined ? 'không rõ' : '$' + (unit * count).toFixed(3));

            if (off.length) {
                text += ' ⚠ Lệch mặc định: ' + off.join(' và ')
                    + '. Con số trên chỉ tính theo chất lượng — khổ lớn hơn sẽ đắt hơn.';
            }

            box.textContent = text;
            box.classList.toggle('text-danger', off.length > 0);
            box.classList.toggle('text-muted', off.length === 0);
        }

        picker.addEventListener('change', sync);
        Array.prototype.forEach.call(groups, function (group) {
            Array.prototype.forEach.call(group.querySelectorAll('select'), function (el) {
                el.addEventListener('change', sync);
            });
        });

        sync();
    });
})();
</script>
@endsection
