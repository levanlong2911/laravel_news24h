@extends('layouts.base', ['title' => 'Keyframes'])
@section('title', 'Keyframes')

@section('css')
<link rel="stylesheet" href="{{ asset('assets/css/video-producer.css') }}?v={{ filemtime(public_path('assets/css/video-producer.css')) }}">
@endsection

@section('content')
    @include('video-projects.partials.moto-keyframes-panel')
@endsection

@section('script')
<script src="{{ asset('assets/js/video-producer.js') }}?v={{ filemtime(public_path('assets/js/video-producer.js')) }}"></script>
<script>
vpTaskWatch({ root: 'keyframesPage' });
document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-copy-prompt]');

    if (!button) { return; }

    var box = document.getElementById(button.dataset.copyPrompt);

    box.select();
    document.execCommand('copy');
    button.textContent = 'Đã sao chép';
});
document.addEventListener('change', function (event) {
    var select = event.target.closest('[data-keyframe-models]');

    if (!select) { return; }

    var entry = JSON.parse(select.dataset.keyframeModels).find(function (model) { return model.model === select.value; });

    if (!entry) { return; }

    [['quality', entry.qualities, entry.default_quality], ['size', entry.sizes, entry.default_size]].forEach(function (control) {
        var target = document.querySelector('select[form="' + select.getAttribute('form') + '"][name="' + control[0] + '"]');
        var values = control[1];
        var keep = values.indexOf(target.value) !== -1 ? target.value : (values.indexOf(control[2]) !== -1 ? control[2] : values[0]);

        target.innerHTML = '';
        values.forEach(function (value) {
            target.add(new Option(control[0] === 'size' ? value.replace('x', ' × ') : value, value, false, value === keep));
        });

        if (values.length === 0) {
            target.add(new Option('—', '', false, true));
        }
    });
});
</script>
@endsection
