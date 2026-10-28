@extends('layouts.base', ['title' => 'Anchor Creation'])
@section('title', 'Anchor Creation')

@section('css')
<link rel="stylesheet" href="{{ asset('assets/css/video-producer.css') }}?v={{ filemtime(public_path('assets/css/video-producer.css')) }}">
@endsection

@section('content')
<div class="container-fluid vp" id="anchorPage">

    <div id="anchorFlash" hidden data-ajax-flash data-success="{{ session('success') }}" data-error="{{ session('error') }}"></div>

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
            <h1>Anchor Creation</h1>
            <p>Tạo identity anchor cho video project</p>
        </div>
        <a class="vp-btn" href="{{ route('video-projects.index') }}">← Quay lại dự án</a>
    </div>

    <div class="va-grid va-top">

        <div class="va-col">

            <div class="vp-panel">
                <div class="va-head">
                    <span class="n">1</span>
                    <b>ARTICLE</b>
                    <span class="grow"></span>
                    <span class="va-tag {{ $project->article ? 'ok' : '' }}">{{ $project->article ? 'Đã thu thập' : 'Chưa gắn bài' }}</span>
                </div>
                <div class="va-art">
                    @if($project->article === null)
                        <div class="t">Không gắn với bài viết nào</div>
                    @else
                        <div class="t">{{ $project->article->title }}</div>
                        @if($project->article->source_url)
                            <a class="u" href="{{ $project->article->source_url }}" target="_blank" rel="noopener">{{ \Illuminate\Support\Str::limit($project->article->source_url, 60) }} ↗</a>
                        @endif
                        <div class="d">Ngày tạo dự án: {{ $project->created_at?->format('d/m/Y H:i') ?? '—' }}</div>
                    @endif
                </div>
            </div>

            <div class="vp-panel">
                <div class="va-head">
                    <span class="n">2</span>
                    <b>INSPIRATION</b>
                    <span class="grow"></span>
                    @if($brief['analysed'])<span class="va-tag blue">Đã phân tích</span>@endif
                    @if($brief['running'])
                        <button class="vp-btn sm" disabled>Đang phân tích…</button>
                    @elseif($brief['stuck'])
                        <form method="POST" action="{{ route('video-projects.inspiration-reset', $project->id) }}">
                            @csrf
                            <button class="vp-btn sm dg">Reset lượt bị kẹt</button>
                        </form>
                    @elseif($brief['can_run'])
                        <form method="POST" action="{{ route('video-projects.inspiration', $project->id) }}"
                              id="inspirationForm" data-modal="confirmInspiration" onsubmit="return vpLockForm(this)">
                            @csrf
                        </form>
                        <button type="button" class="vp-btn sm pri" data-toggle="modal" data-target="#confirmInspiration"
                                data-busy="Đang phân tích…">{{ $brief['analysed'] ? 'Phân tích lại' : 'Analysis' }}</button>
                        @include('modal.confirm_action', [
                            'id' => 'confirmInspiration',
                            'form' => 'inspirationForm',
                            'content' => 'Gọi Claude Haiku phân tích bài viết — tác vụ này tính tiền.',
                        ])
                    @endif
                </div>

                <div class="va-insp-grid">
                    <div>
                        <div class="va-lbl" style="margin-top:0">Tóm tắt ý tưởng (Phase 1)</div>
                        <div class="va-brief">
                            @if($brief['source'])
                                <div class="line"><span class="k">Nguồn kết quả</span><span class="v">{{ $brief['source'] }}</span></div>
                            @endif
                            @if($brief['prompt'])
                                <div class="line"><span class="k">Prompt khi chạy lại</span><span class="v">{{ $brief['prompt'] }}</span></div>
                            @endif
                            @if($brief['run_error'])
                                <div class="line"><span class="v" style="color:var(--vp-red)">{{ $brief['run_error'] }}</span></div>
                            @endif
                            @include('video-projects.partials.stream-state', ['stream' => $brief['stream']])
                            @if($brief['error'])
                                <div class="line"><span class="v" style="color:var(--vp-red)">{{ $brief['error'] }}</span></div>
                            @elseif(! $brief['analysed'])
                                <div class="line"><span class="v">Chưa phân tích</span></div>
                            @elseif($brief['format'] === 'phase1')
                                @php
                                    $check = $brief['source_check'];
                                    $points = $check === null ? '' : ($check['score_min'] === $check['score_max'] ? $check['score_min'] : $check['score_min'].'–'.$check['score_max']).'/10 điểm';
                                @endphp
                                @if($check === null)
                                    <div class="line"><span class="k">Đánh giá nguồn</span><span class="v">Lượt phân tích này chưa có đánh giá nguồn — bấm Analysis để chạy lại.</span></div>
                                @else
                                    @if(! $brief['source_current'])
                                        <div class="line"><span class="v" style="color:var(--vp-red)">Đánh giá này thuộc bài viết hoặc prompt cũ — không dùng để tạo thiết kế. Bấm Analysis để chạy lại.</span></div>
                                    @endif
                                    <div class="line"><span class="k">Đánh giá nguồn</span><span class="v src-verdict {{ strtolower($check['verdict']) }}">
                                        @switch($check['verdict'])
                                            @case('STOP') Bài viết không nói về một siêu du thuyền hoặc thiết kế cụ thể. Vui lòng chọn bài viết khác. @break
                                            @case('REJECT') Bài viết chưa đủ thông tin thiết kế — {{ $points }}. Vui lòng chọn bài viết khác. @break
                                            @case('RUN') Đạt — {{ $points }}.@if(in_array('THIN_SOURCE', $check['flags'], true)) Nguồn ít thông tin (THIN_SOURCE).@endif @if($brief['source_passed'] && $designFirst) Bước tiếp theo: Design DNA (Phase 2).@endif @break
                                            @default Chưa thể chấm điểm — {{ $check['error'] ?? 'còn tiêu chí chưa xác minh ('.$points.'), chưa đủ cơ sở kết luận' }}.
                                        @endswitch
                                    </span></div>
                                @endif
                                @if($brief['json'] !== null)
                                    <pre class="raw">{{ $brief['json'] }}</pre>
                                @else
                                    <div class="line"><span class="v">Phản hồi không phải JSON ({{ $brief['json_error'] }})</span></div>
                                @endif
                            @else
                                <div class="line"><span class="va-tag mute">Brief cũ (inspiration-v2) — lịch sử</span></div>
                                <pre class="raw">{{ $brief['json'] }}</pre>
                            @endif
                        </div>
                    </div>

                </div>
            </div>

            @if($designFirst)
                <div class="vp-panel">
                    <div class="va-head">
                        <span class="n">3</span>
                        <b>DESIGN DNA</b>
                        <span class="grow"></span>
                        @if($dna['analysed'])<span class="va-tag blue">Đã tạo</span>@endif
                        @if($dna['running'])
                            <button class="vp-btn sm" disabled>Đang tạo DNA…</button>
                        @elseif($dna['stuck'])
                            <form method="POST" action="{{ route('video-projects.design-dna-reset', $project->id) }}">
                                @csrf
                                <button class="vp-btn sm dg">Reset lượt bị kẹt</button>
                            </form>
                        @elseif($dna['can_run'])
                            <form method="POST" action="{{ route('video-projects.design-dna', $project->id) }}"
                                  id="designDnaForm" data-modal="confirmDesignDna" onsubmit="return vpLockForm(this)">
                                @csrf
                            </form>
                            <button type="button" class="vp-btn sm pri" data-toggle="modal" data-target="#confirmDesignDna"
                                    data-busy="Đang tạo DNA…">{{ $dna['analysed'] ? 'Tạo lại DNA' : 'Tạo Design DNA' }}</button>
                            @include('modal.confirm_action', [
                                'id' => 'confirmDesignDna',
                                'form' => 'designDnaForm',
                                'content' => 'Gọi Claude Sonnet 5.5 tạo Design DNA theo prompt Phase 2 — tác vụ này tính tiền.',
                            ])
                        @elseif($dna['can_regenerate'])
                            <form method="POST" action="{{ route('video-projects.design-dna', $project->id) }}"
                                  id="designDnaRegenerateForm" data-modal="confirmDesignDnaRegenerate" onsubmit="return vpLockForm(this)">
                                @csrf
                                <input type="hidden" name="regenerate" value="1">
                            </form>
                            <button type="button" class="vp-btn sm pri" data-toggle="modal" data-target="#confirmDesignDnaRegenerate"
                                    data-busy="Đang tạo DNA…">Tạo lại DNA (tính phí)</button>
                            @include('modal.confirm_action', [
                                'id' => 'confirmDesignDnaRegenerate',
                                'form' => 'designDnaRegenerateForm',
                                'content' => 'DNA hiện tại chưa đạt kiểm RULE. Gọi Claude Sonnet 5.5 tạo một bản mới — lượt cũ vẫn được giữ, tác vụ này tính tiền.',
                            ])
                        @else
                            <button class="vp-btn sm" disabled title="{{ $dna['run_error'] ?? 'Đã có DNA đạt kiểm RULE cho đúng kết quả Phase 1 và prompt hiện tại' }}">Tạo Design DNA</button>
                        @endif
                    </div>

                    <div class="va-insp-grid">
                        <div>
                            <div class="va-brief">
                                @if($dna['source'])
                                    <div class="line"><span class="k">Nguồn kết quả</span><span class="v">{{ $dna['source'] }}</span></div>
                                @endif
                                @if($dna['prompt'])
                                    <div class="line"><span class="k">Prompt khi chạy lại</span><span class="v">{{ $dna['prompt'] }}</span></div>
                                @endif
                                @if($dna['run_error'])
                                    <div class="line"><span class="v" style="color:var(--vp-red)">{{ $dna['run_error'] }}</span></div>
                                @endif
                                @include('video-projects.partials.stream-state', ['stream' => $dna['stream']])
                                @if($dna['error'])
                                    <div class="line"><span class="v" style="color:var(--vp-red)">{{ $dna['error'] }}</span></div>
                                @elseif(! $dna['analysed'])
                                    <div class="line"><span class="v">Chưa tạo</span></div>
                                @else
                                    @php($dnaCheck = $dna['check'])
                                    @if(! $dna['current'])
                                        <div class="line"><span class="v" style="color:var(--vp-red)">DNA này thuộc kết quả Phase 1 hoặc prompt cũ — không dùng để tạo thiết kế.</span></div>
                                    @endif
                                    @if($dnaCheck !== null)
                                        <div class="line"><span class="k">Kiểm RULE</span><span class="v src-verdict {{ strtolower($dnaCheck['verdict']) }}">
                                            @switch($dnaCheck['verdict'])
                                                @case('PASS') Đạt @break
                                                @case('FAIL') Không đạt — {{ count($dnaCheck['issues']) }} lỗi @break
                                                @default Chưa thể kiểm — {{ $dnaCheck['error'] }}
                                            @endswitch
                                        </span></div>
                                        @foreach($dnaCheck['issues'] as $issue)
                                            <div class="line"><span class="v" style="color:var(--vp-red)">{{ $issue }}</span></div>
                                        @endforeach
                                        @foreach($dna['judges_required'] ? ['novelty' => 'Novelty', 'plausibility' => 'Plausibility'] : [] as $kind => $kindLabel)
                                            @php($judge = $dna['judges'][$kind] ?? null)
                                            <div class="line">
                                                <span class="k">{{ $kindLabel }}</span>
                                                <span class="v">
                                                    @if($judge === null)
                                                        Chưa kiểm (cần DNA đạt kiểm RULE)
                                                    @else
                                                        <span class="src-verdict {{ $judge['verdict'] === 'PASS' ? 'run' : ($judge['verdict'] === 'FAIL' ? 'reject' : 'unscored') }}">{{ ['PASS' => 'Đạt', 'FAIL' => 'Không đạt'][$judge['verdict']] ?? 'Chưa kiểm' }}</span>
                                                        @if($judge['running']) · đang chấm… @endif
                                                        @if($judge['error'] || $judge['run_error'])<span style="color:var(--vp-red)"> · {{ $judge['error'] ?? $judge['run_error'] }}</span>@endif
                                                        @if($judge['can_run'])
                                                            <form method="POST" action="{{ route('video-projects.design-dna-judge', [$project->id, $kind]) }}"
                                                                  id="dnaJudge{{ $kind }}Form" data-modal="confirmDnaJudge{{ $kind }}" onsubmit="return vpLockForm(this)">
                                                                @csrf
                                                            </form>
                                                            <button type="button" class="vp-btn sm" data-toggle="modal" data-target="#confirmDnaJudge{{ $kind }}"
                                                                    data-busy="Đang chấm…">Chấm {{ $kindLabel }}</button>
                                                            @include('modal.confirm_action', [
                                                                'id' => 'confirmDnaJudge'.$kind,
                                                                'form' => 'dnaJudge'.$kind.'Form',
                                                                'content' => 'Gọi '.config('video.superyacht.judge.model').' chấm '.$kindLabel.' của Design DNA theo rubric Phase 2 — tác vụ này tính tiền.',
                                                            ])
                                                        @elseif($judge['can_rejudge'])
                                                            <form method="POST" action="{{ route('video-projects.design-dna-judge', [$project->id, $kind]) }}"
                                                                  id="dnaRejudge{{ $kind }}Form" data-modal="confirmDnaRejudge{{ $kind }}" onsubmit="return vpLockForm(this)">
                                                                @csrf
                                                                <input type="hidden" name="rejudge" value="1">
                                                            </form>
                                                            <button type="button" class="vp-btn sm" data-toggle="modal" data-target="#confirmDnaRejudge{{ $kind }}"
                                                                    data-busy="Đang chấm…">Chấm lại {{ $kindLabel }} (tính phí)</button>
                                                            @include('modal.confirm_action', [
                                                                'id' => 'confirmDnaRejudge'.$kind,
                                                                'form' => 'dnaRejudge'.$kind.'Form',
                                                                'content' => 'Kết quả '.$kindLabel.' đã lưu không đọc được. Gọi '.config('video.superyacht.judge.model').' chấm lại — lượt cũ vẫn được giữ, tác vụ này tính tiền.',
                                                            ])
                                                        @endif
                                                    @endif
                                                </span>
                                            </div>
                                        @endforeach
                                    @endif
                                    @if($dna['json'] !== null)
                                        <pre class="raw">{{ $dna['json'] }}</pre>
                                    @else
                                        <div class="line"><span class="v">Phản hồi không phải JSON ({{ $dna['json_error'] }})</span></div>
                                    @endif
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endif

        </div>

        <div class="va-col">

            @if($designFirst)
                @include('video-projects.partials.vessel-design-panel')
            @else
                @include('video-projects.partials.screenplay-panel')
            @endif

        </div>

    </div>

    @if(($modelSheet ?? null) !== null)
        @include('video-projects.partials.model-sheet-panel')

        @if(($scriptStep ?? null) !== null)
            @include('video-projects.partials.script-panel')
        @endif
    @else
        @include('video-projects.partials.anchor-panel')

        @if($designFirst)
            @include('video-projects.partials.screenplay-panel')
        @endif
    @endif
</div>
@endsection

@section('script')
<script src="{{ asset('assets/js/video-producer.js') }}?v={{ filemtime(public_path('assets/js/video-producer.js')) }}"></script>
<script>
(function () {
    var prices = @json(collect(\App\Enums\ImageQuality::cases())
        ->mapWithKeys(fn ($q) => [$q->value => $q->estimatedCostUsd()]));

    function init(root) {
        root.querySelectorAll('[data-anchor-form]').forEach(function (button) {
            var form = document.getElementById(button.getAttribute('data-anchor-form'));
            var box = document.querySelector(button.getAttribute('data-target') + ' .modal-body p:last-child');
            if (!form || !box) { return; }

            var promptBox = document.getElementById(button.getAttribute('data-prompt-box'));
            var promptCount = document.getElementById(button.getAttribute('data-prompt-count'));
            var sized = {};
            try { sized = JSON.parse(button.getAttribute('data-sized-prompts') || '{}') || {}; } catch (e) { sized = {}; }
            var sizedEnabled = Object.keys(sized).length > 0;
            var written = {
                prompt: promptBox ? promptBox.value : '',
                hash: form.elements.prompt_sha256 ? form.elements.prompt_sha256.value : ''
            };
            var sizeUnsupported = false;

            function syncPrompt() {
                sizeUnsupported = false;
                if (!sizedEnabled || !promptBox || !form.elements.prompt_sha256) { return; }
                var size = form.elements.size.value;
                var entry = size === '' ? null : sized[size];
                if (size !== '' && !entry) {
                    sizeUnsupported = true;
                    form.elements.prompt_sha256.value = '';
                    return;
                }
                var prompt = entry ? entry.prompt : written.prompt;
                promptBox.value = prompt;
                form.elements.prompt_sha256.value = entry ? entry.prompt_sha256 : written.hash;
                if (promptCount) { promptCount.textContent = Array.from(prompt).length; }
            }

            function sync() {
                syncPrompt();
                var model = form.elements.model.value;
                Array.from(form.elements.quality.options).forEach(function (option) {
                    var models = (option.getAttribute('data-models') || '').split(' ');
                    option.disabled = option.value !== '' && model !== '' && models.indexOf(model) === -1;
                });
                if (form.elements.quality.selectedOptions[0] && form.elements.quality.selectedOptions[0].disabled) {
                    form.elements.quality.value = '';
                }

                var missing = ['size', 'model', 'quality', 'variations'].filter(function (name) {
                    var el = form.elements[name];
                    return !el || el.value === '';
                });
                var promptHash = form.elements.prompt_sha256;
                var hasPrompt = promptHash && promptHash.value.trim() !== '';

                var blocked = button.hasAttribute('data-blocked');
                button.disabled = blocked || !(hasPrompt && missing.length === 0);
                button.title = blocked
                    ? 'Thiết kế chưa sẵn sàng'
                    : (sizeUnsupported
                        ? 'Prompt này không ghép lại được cho tỷ lệ của Size đang chọn — chọn Size cùng tỷ lệ hoặc viết lại prompt'
                        : (!hasPrompt ? 'Chưa có anchor prompt' : (missing.length ? 'Chưa chọn: ' + missing.join(', ') : '')));

                var unit = prices[form.elements.quality.value];
                var count = parseInt(form.elements.variations.value, 10) || 1;
                box.textContent = count + ' ảnh · ' + form.elements.quality.value
                    + ' · ' + form.elements.size.value
                    + (unit === null
                        ? ' — chưa có ước tính giá cho mức này.'
                        : ' — ước lượng $' + (unit || 0).toFixed(3) + ' × ' + count
                            + ' = $' + ((unit || 0) * count).toFixed(3) + '.')
                    + ' Trang sẽ đứng đợi tới khi có ảnh.';
            }

            Array.from(form.elements)
                .filter(function (el) { return el.tagName === 'SELECT'; })
                .forEach(function (el) { el.addEventListener('change', sync); });
            sync();
        });

        root.querySelectorAll('[data-approve-form]').forEach(function (button) {
            var formId = button.getAttribute('data-approve-form');
            var radios = root.querySelectorAll('input[type="radio"][form="' + formId + '"]');

            function sync() {
                var chosen = Array.from(radios).some(function (radio) { return radio.checked && !radio.disabled; });
                button.disabled = !chosen;
                button.title = chosen ? '' : 'Chọn một ảnh candidate trước';
            }

            radios.forEach(function (radio) { radio.addEventListener('change', sync); });
            sync();
        });
    }

    var page = document.getElementById('anchorPage');

    if (page) {
        init(page);
        // vpAjaxPage('anchorPage', init);
    }
})();
</script>
@endsection
