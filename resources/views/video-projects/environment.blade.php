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
                        <form method="POST" action="{{ route('video-projects.environment', $id) }}"
                              id="{{ $form }}" data-modal="{{ $modal }}"
                              onsubmit="return vpLockForm(this)" hidden>
                            @csrf
                            <input type="hidden" name="environment_key" value="{{ $key }}">
                        </form>

                        <div class="ve-set">
                            <div class="va-field">
                                <label>Model</label>
                                <select class="ctl" name="model" form="{{ $form }}" data-row="{{ $key }}" required>
                                    @foreach(\App\Enums\ImageModel::cases() as $model)
                                        <option value="{{ $model->value }}"
                                                @selected($model === $defaultModel)>{{ $model->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="va-field">
                                <label>Quality</label>
                                <select class="ctl" name="quality" form="{{ $form }}" data-row="{{ $key }}" required>
                                    @foreach(\App\Enums\ImageQuality::cases() as $quality)
                                        <option value="{{ $quality->value }}" title="{{ $quality->hint() }}"
                                                @selected($quality === $defaultQuality)>{{ $quality->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="va-field">
                                <label>Size</label>
                                <select class="ctl" name="size" form="{{ $form }}" data-row="{{ $key }}" required>
                                    @foreach(\App\Enums\ImageSize::cases() as $size)
                                        <option value="{{ $size->value }}"
                                                @selected($size === $defaultSize)>{{ $size->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="va-field">
                                <label>Variations</label>
                                <select class="ctl" name="variations" form="{{ $form }}" data-row="{{ $key }}" required>
                                    @foreach(\App\Enums\ImageVariations::cases() as $variation)
                                        <option value="{{ $variation->value }}"
                                                @selected($variation === $defaultVariations)>{{ $variation->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="va-foot" style="padding-left:0;padding-right:0">
                            <button type="button" class="vp-btn pri" data-toggle="modal"
                                    data-target="#{{ $modal }}" data-busy="Đang render…">Render Plate</button>
                        </div>

                        <div class="ve-locked">
                            Mặc định {{ $defaultSize->label() }} · {{ $defaultQuality->label() }} — cùng khổ với keyframe.
                            Ước lượng chi phí tính theo chất lượng và số ảnh, không theo khổ.
                        </div>

                        @include('modal.confirm_action', [
                            'id' => $modal,
                            'form' => $form,
                            'content' => 'Sinh tấm nền sạch cho "'.$environment['label'].'" — TÁC VỤ NÀY TÍNH TIỀN.',
                            'detail' => 'Đang tính…',
                            'detailId' => 'est_'.$key,
                        ])
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
                                            <span>{{ $cell['variations'] }} ảnh &middot; {{ $cell['quality'] }}
                                                &middot; ${{ number_format($cell['cost_recorded'], 3) }}</span>
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
                                <span class="d">{{ $cell['variations'] }} ảnh &middot; {{ $cell['quality'] }}</span>
                            </div>

                            @if($cell['render_error'])
                                <div class="alert alert-danger">{{ $cell['render_error'] }}</div>
                            @endif

                            @if($cell['is_live'])
                                <div class="ve-locked" style="color:var(--vp-amber-fg)">
                                    Đang render — tải lại trang để xem tiến độ.
                                </div>
                            @elseif($cell['can_render'])
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
                                    'detail' => $cell['variations'].' ảnh · '.$cell['quality'].' · '.$cell['size']
                                        .' — ước lượng $'.number_format($cell['cost_estimate'], 3),
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
    var fallback = @json($defaultQuality->value ?? '');
    var defaults = {
        quality: @json($defaultQuality->value ?? ''),
        size: @json($defaultSize->value ?? '')
    };

    keys.forEach(function (key) {
        var box = document.getElementById('est_' + key);
        if (!box) { return; }

        var fields = {};
        Array.prototype.forEach.call(
            document.querySelectorAll('[data-row="' + key + '"]'),
            function (el) { fields[el.name] = el; }
        );

        function sync() {
            var quality = fields.quality ? fields.quality.value : fallback;
            var size = fields.size ? fields.size.value : defaults.size;
            var count = fields.variations ? parseInt(fields.variations.value, 10) : 1;
            if (!count || count < 1) { count = 1; }

            var unit = costs[quality];
            var off = [];
            if (quality !== defaults.quality) { off.push('chất lượng'); }
            if (size !== defaults.size) { off.push('khổ'); }

            var text = size + ' · ' + quality + ' · ' + count + ' ảnh — ước lượng '
                + (unit === undefined ? 'không rõ' : '$' + (unit * count).toFixed(3));

            if (off.length) {
                text += ' ⚠ Lệch mặc định: ' + off.join(' và ')
                    + '. Con số trên chỉ tính theo chất lượng — khổ lớn hơn sẽ đắt hơn.';
            }

            box.textContent = text;
            box.classList.toggle('text-danger', off.length > 0);
            box.classList.toggle('text-muted', off.length === 0);
        }

        Object.keys(fields).forEach(function (name) {
            fields[name].addEventListener('change', sync);
        });

        sync();
    });
})();
</script>
@endsection
