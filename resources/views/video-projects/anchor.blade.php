@extends('layouts.base', ['title' => 'Anchor Creation'])
@section('title', 'Anchor Creation')

@section('css')
<link rel="stylesheet" href="{{ asset('assets/css/video-producer.css') }}?v={{ filemtime(public_path('assets/css/video-producer.css')) }}">
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
                    <b>HAIKU INSPIRATION</b>
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
                        <div class="va-lbl" style="margin-top:0">Tóm tắt ý tưởng (Haiku)</div>
                        <div class="va-brief">
                            @if($brief['error'])
                                <div class="line"><span class="v" style="color:var(--vp-red)">{{ $brief['error'] }}</span></div>
                            @elseif(! $brief['analysed'])
                                <div class="line"><span class="v">Chưa phân tích</span></div>
                            @else
                                <div class="line">
                                    <span class="tick">✓</span>
                                    <span class="k">Chủ đề chính</span>
                                    <span class="v">{{ $brief['focus'] }}</span>
                                </div>
                                @foreach($brief['insights'] as $insight)
                                    <div class="line">
                                        <span class="tick">✓</span>
                                        <span class="k">{{ str_replace('_', ' ', $insight['aspect']) }}</span>
                                        <span class="v">{{ $insight['summary'] }}</span>
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    </div>

                </div>
            </div>

        </div>

        <div class="va-col">

            @if($designFirst)
                @include('video-projects.partials.vessel-design-panel')
            @else
                @include('video-projects.partials.screenplay-panel')
            @endif

        </div>

    </div>

    @include('video-projects.partials.anchor-panel')

    @if($designFirst)
        @include('video-projects.partials.screenplay-panel')
    @endif
</div>
@endsection

@section('script')
<script src="{{ asset('assets/js/video-producer.js') }}?v={{ filemtime(public_path('assets/js/video-producer.js')) }}"></script>
<script>
(function () {
    var prices = @json(collect(\App\Enums\ImageQuality::cases())
        ->mapWithKeys(fn ($q) => [$q->value => $q->estimatedCostUsd()]));

    document.querySelectorAll('[data-anchor-form]').forEach(function (button) {
        var form = document.getElementById(button.getAttribute('data-anchor-form'));
        var box = document.querySelector(button.getAttribute('data-target') + ' .modal-body p:last-child');
        if (!form || !box) { return; }

        function sync() {
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
                : (!hasPrompt ? 'Chưa có anchor prompt' : (missing.length ? 'Chưa chọn: ' + missing.join(', ') : ''));

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

    document.querySelectorAll('[data-approve-form]').forEach(function (button) {
        var formId = button.getAttribute('data-approve-form');
        var radios = document.querySelectorAll('input[type="radio"][form="' + formId + '"]');

        function sync() {
            var chosen = Array.from(radios).some(function (radio) { return radio.checked && !radio.disabled; });
            button.disabled = !chosen;
            button.title = chosen ? '' : 'Chọn một ảnh candidate trước';
        }

        radios.forEach(function (radio) { radio.addEventListener('change', sync); });
        sync();
    });
})();
</script>
@endsection
