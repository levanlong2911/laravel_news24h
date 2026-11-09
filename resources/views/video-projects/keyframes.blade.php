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
</script>
@endsection
