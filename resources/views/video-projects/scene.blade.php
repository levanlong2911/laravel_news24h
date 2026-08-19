@extends('layouts.base', ['title' => 'Video Producer'])
@section('title', 'Video Producer')

@section('css')
<link rel="stylesheet" href="{{ asset('assets/css/video-session.css') }}">
<link rel="stylesheet" href="{{ asset('assets/css/video-producer.css') }}">
@endsection

@section('content')
@php
    $requires = $scene['requires_state'] ?? null;
    $proof = $requires !== null ? $provenBy->get($requires) : null;
@endphp

<div class="container-fluid vp">

    <div class="vp-crumb">
        <a href="{{ route('video-session.index') }}">Video Sessions</a>
        <span class="sep">/</span>
        <a href="{{ route('video-session.show', $session->id) }}"><b>{{ \Illuminate\Support\Str::limit($session->project->title, 58) }}</b></a>
        <span class="grow"></span>
        <span class="m">{{ $session->shots->count() }} shot</span><span class="sep">·</span>
        <span class="m">Ước tính <b>${{ number_format($session->cost_estimate_total, 2) }}</b></span><span class="sep">·</span>
        <span class="m">Thực chi <b>${{ number_format($session->cost_actual, 2) }}</b></span><span class="sep">·</span>
        <span class="m">Đã duyệt <b>{{ $session->shots->where('status', 'approved')->count() }}</b></span><span class="sep">·</span>
        <span class="m">Queued <b>{{ $session->shots->where('status', 'queued')->count() }}</b></span>
    </div>

    <div class="vp-steps">
        @foreach(\App\Enums\SceneStep::cases() as $s)
            @if(! $loop->first)<div class="vp-arw">→</div>@endif
            <a class="vp-stp {{ $s->ordinal() < $step->ordinal() ? 'done' : '' }}"
               aria-current="{{ $s === $step ? 'page' : 'false' }}"
               href="{{ route('video-session.scene', [$session->id, $scene['id'], $s->value]) }}">
                <span class="n">{{ $s->ordinal() < $step->ordinal() ? '✓' : $s->ordinal() }}</span>
                <span><b>{{ $s->label() }}</b><em>{{ $s->hint() }}</em></span>
            </a>
        @endforeach
    </div>

    <div class="vp-body">

        <div class="vp-panel">
            <div class="vp-ph"><b>SCENES ({{ $scenes->count() }})</b></div>
            <div class="vp-rail">
            @foreach($scenes as $s)
                @php
                    $sShot = $shots->get($s['id']);
                    $sState = ($s['requires_state'] ?? null) !== null && $provenBy->has($s['requires_state']);
                    $tag = $s['id'] === $scene['id'] ? ['cur', 'Current']
                        : ($sShot && $sShot->status === 'rendered' ? ['done', 'Rendered'] : ['wait', 'Pending']);
                @endphp
                <a class="vp-sc {{ $s['id'] === $scene['id'] ? 'on' : '' }}"
                   href="{{ route('video-session.scene', [$session->id, $s['id'], $step->value]) }}">
                    <span class="n">{{ str_pad($s['ordinal'] ?? $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                    @if($sShot && $sShot->preview_path)
                        <img src="{{ asset($sShot->preview_path) }}" alt="">
                    @else
                        <span class="ph"></span>
                    @endif
                    <span class="t">
                        <b>{{ \Illuminate\Support\Str::headline($s['id']) }}</b>
                        <em>{{ $s['purpose'] ?? '—' }}{{ $sState ? '' : ' · no anchor' }}</em>
                    </span>
                    <span class="vp-tag {{ $tag[0] }}">{{ $tag[1] }}</span>
                </a>
            @endforeach
            </div>
        </div>

        <div>
            @include('video-session._'.$step->value, [
                'session' => $session,
                'scene' => $scene,
                'shot' => $shot,
                'chain' => $chain,
                'provenBy' => $provenBy,
                'requires' => $requires,
                'proof' => $proof,
                'quality' => $quality,
                'step' => $step,
            ])
        </div>
    </div>
</div>
@endsection
