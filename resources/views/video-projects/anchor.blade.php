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

            <div class="vp-panel">
                <div class="va-head">
                    <span class="n">3</span>
                    <b>KỊCH BẢN PHIM</b>
                    <em>nội dung — chưa phân cảnh</em>
                    <span class="grow"></span>
                    @if($screenplayFoundation['foundation'] !== null)<span class="va-tag ok">Đã có nội dung</span>@endif
                    @if(! $brief['analysed'])
                        <button class="vp-btn sm" disabled title="Cần brief Haiku trước">Creat screen play</button>
                    @elseif($screenplayFoundation['running'])
                        <button class="vp-btn sm" disabled>Đang viết…</button>
                        <form method="POST" action="{{ route('video-projects.screenplay-foundation-reset', $project->id) }}">
                            @csrf
                            <button class="vp-btn sm dg">Reset</button>
                        </form>
                    @else
                        @php
                            $hasFoundation = $screenplayFoundation['foundation'] !== null;
                            $screenplayModel = (string) config('video.screenplay.model');
                            $foundationMaxTokens = number_format((int) config('video.screenplay.foundation.max_tokens'), 0, ',', '.');
                            $foundationMinutes = intdiv((int) config('video.screenplay.foundation.timeout_seconds'), 60);
                        @endphp
                        <form method="POST" action="{{ route('video-projects.screenplay-foundation', $project->id) }}"
                              id="screenplayFoundationForm" data-modal="confirmScreenplayFoundation" onsubmit="return vpLockForm(this)">
                            @csrf
                            @if($hasFoundation)<input type="hidden" name="force" value="1">@endif
                        </form>
                        <button type="button" class="vp-btn sm pri" data-toggle="modal" data-target="#confirmScreenplayFoundation"
                                data-busy="Đang viết…">{{ $hasFoundation ? 'Tạo bản khác (tính phí)' : 'Viết nội dung kịch bản' }}</button>
                        @include('modal.confirm_action', [
                            'id' => 'confirmScreenplayFoundation',
                            'form' => 'screenplayFoundationForm',
                            'content' => $hasFoundation
                                ? 'Gọi '.$screenplayModel.' viết một bản nội dung MỚI, dù brief không đổi. Bản đang có vẫn được giữ trong lịch sử — tác vụ này tính tiền.'
                                : 'Gọi '.$screenplayModel.' viết nội dung kịch bản: ý tưởng thiết kế, tiền đề, tóm tắt, diễn biến qua năm giai đoạn và kết thúc. Bước này chưa tạo scene — tác vụ này tính tiền.',
                            'detail' => ($hasFoundation
                                    ? 'Bỏ qua bản đã lưu và gọi model. '
                                    : 'Cùng brief + cùng bộ luật thì dùng lại bản đã lưu, không gọi model. ')
                                .'Tối đa '.$foundationMaxTokens.' token đầu ra (tính cả phần suy nghĩ), chờ tối đa '.$foundationMinutes.' phút.',
                        ])
                    @endif
                </div>
                <div class="va-body">
                    @if($screenplayFoundation['error'])
                        <div class="va-lbl" style="color:var(--vp-red);font-weight:400">{{ $screenplayFoundation['error'] }}</div>
                    @elseif($screenplayFoundation['foundation'] === null)
                        <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">Chưa viết nội dung kịch bản.</div>
                    @else
                        <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">
                            <b>{{ $screenplayFoundation['foundation']['schema_version'] ?? '—' }}</b>
                            &middot; {{ count($screenplayFoundation['foundation']['stage_treatments'] ?? []) }} giai đoạn
                            &middot; chưa phân cảnh
                            &middot; {{ $screenplayFoundation['written_at'] ?? '—' }}
                        </div>
                        <textarea class="va-ta" readonly>{{ \App\Video\Screenplay\ScreenplayFoundationText::render($screenplayFoundation['foundation']) }}</textarea>
                    @endif
                </div>

                @php
                    $castSteps = [
                        ['part' => 'characters', 'title' => 'NHÂN VẬT', 'noun' => 'nhân vật', 'state' => $screenplayCharacters],
                        ['part' => 'locations', 'title' => 'ĐỊA ĐIỂM', 'noun' => 'địa điểm', 'state' => $screenplayLocations],
                    ];
                @endphp
                @foreach($castSteps as $step)
                    @php
                        $castState = $step['state'];
                        $castForm = 'screenplay'.ucfirst($step['part']).'Form';
                        $castModal = 'confirmScreenplay'.ucfirst($step['part']);
                        $hasCast = $castState['rows'] !== null;
                        $castMaxTokens = number_format((int) config('video.screenplay.'.$step['part'].'.max_tokens'), 0, ',', '.');
                        $castMinutes = intdiv((int) config('video.screenplay.'.$step['part'].'.timeout_seconds'), 60);
                    @endphp
                    <div class="va-head" style="border-top:1px solid var(--vp-line)">
                        <b>{{ $step['title'] }}</b>
                        <em>tạo từ nội dung kịch bản, phân cảnh chỉ dùng lại</em>
                        <span class="grow"></span>
                        @if($hasCast)<span class="va-tag ok">Đã có {{ $step['noun'] }}</span>@endif
                        @if($castState['running'])
                            <button class="vp-btn sm" disabled>Đang tạo {{ $step['noun'] }}…</button>
                            <form method="POST" action="{{ route('video-projects.screenplay-'.$step['part'].'-reset', $project->id) }}">
                                @csrf
                                <button class="vp-btn sm dg">Reset lượt bị kẹt</button>
                            </form>
                        @elseif(! $screenplayFoundation['selectable'])
                            <button class="vp-btn sm" disabled title="Cần nội dung kịch bản bản mới (screenplay_foundation_v2) trước">Tạo {{ $step['noun'] }}</button>
                        @else
                            <form method="POST" action="{{ route('video-projects.screenplay-'.$step['part'], $project->id) }}"
                                  id="{{ $castForm }}" data-modal="{{ $castModal }}" onsubmit="return vpLockForm(this)">
                                @csrf
                                <input type="hidden" name="foundation_stage_id" value="{{ $screenplayFoundation['stage_id'] }}">
                                @if($hasCast)<input type="hidden" name="force" value="1">@endif
                            </form>
                            <button type="button" class="vp-btn sm pri" data-toggle="modal" data-target="#{{ $castModal }}"
                                    data-busy="Đang tạo {{ $step['noun'] }}…">{{ $hasCast ? 'Tạo lại '.$step['noun'] : 'Tạo '.$step['noun'] }}</button>
                            @include('modal.confirm_action', [
                                'id' => $castModal,
                                'form' => $castForm,
                                'content' => 'Gọi '.config('video.screenplay.model').' tạo danh sách '.$step['noun'].' cho nội dung kịch bản rev '.$screenplayFoundation['revision']
                                    .' phía trên. Bước phân cảnh chỉ dùng lại danh sách này, không tự thêm — tác vụ này tính tiền.',
                                'detail' => ($hasCast ? 'Bỏ qua danh sách đã lưu và gọi model. ' : 'Cùng nội dung + cùng bộ luật thì dùng lại bản đã lưu, không gọi model. ')
                                    .'Tối đa '.$castMaxTokens.' token đầu ra, chờ tối đa '.$castMinutes.' phút.',
                            ])
                        @endif
                    </div>
                    <div class="va-body">
                        @if($castState['error'])
                            <div class="va-lbl" style="color:var(--vp-red);font-weight:400">Lượt tạo {{ $step['noun'] }} gần nhất lỗi: {{ $castState['error'] }}</div>
                        @endif
                        @if(! $hasCast)
                            @if(! $castState['error'])
                                <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">Chưa tạo {{ $step['noun'] }}.</div>
                            @endif
                        @else
                            @if(! $castState['usable'])
                                <div class="alert alert-warning">
                                    Danh sách {{ $step['noun'] }} này tạo từ nội dung rev {{ $castState['foundation_revision'] }}, không phải bản nội dung đang hiển thị phía trên.
                                </div>
                            @endif
                            <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">
                                rev {{ $castState['revision'] }}
                                &middot; {{ count($castState['rows']) }} {{ $step['noun'] }}
                                &middot; {{ $castState['written_at'] ?? '—' }}
                            </div>
                            <textarea class="va-ta" readonly>@foreach($castState['rows'] as $row){{ $row['id'] ?? '?' }} · {{ $row['name'] ?? '' }}@if(isset($row['kind'])) · {{ $row['kind'] }} / {{ $row['role'] ?? '' }}@endif

    {{ $row['description'] ?? '' }}
@if(filled($row['appearance'] ?? null))
    Ngoại hình: {{ $row['appearance'] }}
@endif

@endforeach</textarea>
                        @endif
                    </div>
                @endforeach

                <div class="va-head" style="border-top:1px solid var(--vp-line)">
                    <b>PHÂN CẢNH ĐẦY ĐỦ</b>
                    <em>scene, coverage, build state</em>
                    <span class="grow"></span>
                    @if($screenplay['screenplay'] !== null)
                        <span class="va-tag ok">Đã có phân cảnh</span>
                        @if($screenplay['approved'])
                            <span class="va-tag ok">Đã duyệt</span>
                            @if($screenplay['selected'])
                                <span class="va-tag ok">Đang dùng cho production</span>
                            @else
                                <form method="POST" action="{{ route('video-projects.screenplay-select', $project->id) }}">
                                    @csrf
                                    <input type="hidden" name="stage_id" value="{{ $screenplay['stage_id'] }}">
                                    <input type="hidden" name="expected_selection_version" value="{{ $screenplay['selection_version'] }}">
                                    <button class="vp-btn sm pri" type="submit">Chọn cho production</button>
                                </form>
                            @endif
                        @else
                            <form method="POST" action="{{ route('video-projects.screenplay-approve', $project->id) }}">
                                @csrf
                                <input type="hidden" name="stage_id" value="{{ $screenplay['stage_id'] }}">
                                <input type="hidden" name="operation_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                <button class="vp-btn sm" type="submit">Duyệt phân cảnh rev {{ $screenplay['revision'] }}</button>
                            </form>
                        @endif
                    @endif
                    @if($screenplay['running'])
                        <button class="vp-btn sm" disabled>Đang tạo phân cảnh…</button>
                        <form method="POST" action="{{ route('video-projects.screenplay-reset', $project->id) }}">
                            @csrf
                            <button class="vp-btn sm dg">Reset lượt bị kẹt</button>
                        </form>
                    @elseif(! $screenplayFoundation['selectable'])
                        <button class="vp-btn sm" disabled title="Cần nội dung kịch bản bản mới (screenplay_foundation_v2) trước">Tạo phân cảnh</button>
                    @elseif(! $screenplayCharacters['usable'] || ! $screenplayLocations['usable'])
                        <button class="vp-btn sm" disabled title="Cần nhân vật và địa điểm tạo từ đúng bản nội dung phía trên">Tạo phân cảnh</button>
                    @else
                        @php
                            $hasScenes = $screenplay['screenplay'] !== null;
                            $sceneMaxTokens = number_format((int) config('video.screenplay.scenes.max_tokens'), 0, ',', '.');
                            $sceneMinutes = intdiv((int) config('video.screenplay.scenes.timeout_seconds'), 60);
                        @endphp
                        <form method="POST" action="{{ route('video-projects.screenplay', $project->id) }}"
                              id="screenplayForm" data-modal="confirmScreenplay" onsubmit="return vpLockForm(this)">
                            @csrf
                            <input type="hidden" name="foundation_stage_id" value="{{ $screenplayFoundation['stage_id'] }}">
                            <input type="hidden" name="characters_stage_id" value="{{ $screenplayCharacters['stage_id'] }}">
                            <input type="hidden" name="locations_stage_id" value="{{ $screenplayLocations['stage_id'] }}">
                            @if($hasScenes)<input type="hidden" name="force" value="1">@endif
                        </form>
                        <button type="button" class="vp-btn sm pri" data-toggle="modal" data-target="#confirmScreenplay"
                                data-busy="Đang tạo phân cảnh…">{{ $hasScenes ? 'Tạo lại phân cảnh' : 'Tạo phân cảnh' }}</button>
                        @include('modal.confirm_action', [
                            'id' => 'confirmScreenplay',
                            'form' => 'screenplayForm',
                            'content' => 'Gọi '.config('video.screenplay.model').' tạo phân cảnh cho nội dung kịch bản rev '.$screenplayFoundation['revision']
                                .' phía trên: chỉ tạo scene, coverage và build state, dùng đúng nhân vật và địa điểm phía trên. Nội dung, nhân vật và địa điểm được giữ nguyên, không viết lại — tác vụ này tính tiền.',
                            'detail' => ($hasScenes ? 'Bỏ qua phân cảnh đã lưu và gọi model. ' : 'Cùng nội dung + cùng bộ luật thì dùng lại bản đã lưu, không gọi model. ')
                                .'Tối đa '.$sceneMaxTokens.' token đầu ra, chờ tối đa '.$sceneMinutes.' phút; chi phí chưa đo, sẽ ghi lại sau lượt đầu.',
                        ])
                    @endif
                </div>
                <div class="va-body">
                    @if($screenplay['error'])
                        <div class="va-lbl" style="color:var(--vp-red);font-weight:400">
                            Lượt tạo phân cảnh gần nhất lỗi: {{ $screenplay['error'] }}
                            @if($screenplay['screenplay'] !== null)
                                <br>Bên dưới là bản thành công gần nhất (rev {{ $screenplay['revision'] }}) — nút Duyệt/Chọn áp vào bản này.
                            @endif
                        </div>
                    @endif
                    @if($screenplay['screenplay'] === null)
                        @if(! $screenplay['error'])
                            <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">Chưa tạo phân cảnh.</div>
                        @endif
                    @else
                        @if($screenplay['selected_stage_id'] !== null && ! $screenplay['selected'])
                            <div class="alert alert-warning">
                                Production vẫn đang dùng một bản phân cảnh cũ đã duyệt. Bản đang hiển thị chưa thay đổi production.
                            </div>
                        @endif
                        @if($screenplay['foundation_stage_id'] !== null && $screenplay['foundation_stage_id'] !== $screenplayFoundation['stage_id'])
                            <div class="alert alert-warning">
                                Phân cảnh này tạo từ nội dung rev {{ $screenplay['foundation_revision'] }}, không phải bản nội dung đang hiển thị phía trên.
                            </div>
                        @endif
                        @if($screenplay['warnings'] !== [])
                            <div class="alert alert-warning">
                                <b>{{ count($screenplay['warnings']) }} cảnh báo biên tập</b>
                                <ul class="mb-0 pl-3">
                                    @foreach($screenplay['warnings'] as $warning)
                                        <li>{{ $warning }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        @php
                            $scenes = $screenplay['screenplay']['scenes'] ?? [];
                            $seconds = round(array_sum(array_column($scenes, 'duration_estimate_ms')) / 1000, 1);
                        @endphp
                        <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">
                            <b>{{ $screenplay['screenplay']['schema_version'] ?? 'bản cũ' }}</b>
                            &middot; rev {{ $screenplay['revision'] }}
                            &middot; {{ count($scenes) }} scene
                            @if($seconds > 0)&middot; {{ $seconds }}s @endif
                            @if($screenplay['foundation_revision'] !== null)&middot; từ nội dung rev {{ $screenplay['foundation_revision'] }} @endif
                            &middot; {{ $screenplay['written_at'] ?? '—' }}
                        </div>
                        <textarea class="va-ta" readonly>{{ \App\Video\Screenplay\ScreenplayText::renderScenes($screenplay['screenplay']) }}</textarea>
                    @endif
                </div>
            </div>

        </div>

    </div>

    @php
        $promptModel = config('canonical_concept.'.config('canonical_concept.provider').'.model');
    @endphp

    <div class="vp-panel va-anchor">
        <div class="va-head">
            <span class="n">4</span>
            <b>ANCHOR PROMPT</b>
            <em>{{ $anchorRows[0]['character'] === null ? 'anchor từ brief Haiku' : count($anchorRows).' nhân vật chính' }}</em>
            <span class="grow"></span>
            @if($anchorPrompt['running'])
                <span class="va-tag">Đang viết prompt…</span>
                <form method="POST" action="{{ route('video-projects.concept-reset', $project->id) }}">
                    @csrf
                    <button class="vp-btn sm dg">Reset lượt bị kẹt</button>
                </form>
            @endif
        </div>

        @if($anchorPrompt['error'])
            <div class="va-lbl" style="padding:10px 14px 0;color:var(--vp-red);font-weight:400">{{ $anchorPrompt['error'] }}</div>
        @endif

        <div class="va-rows">
            <div class="va-row va-row-head">
                <div>Nhân vật</div>
                <div>Prompt</div>
                <div>Thiết lập</div>
                <div>Candidate images</div>
            </div>

            @foreach($anchorRows as $row)
                @php
                    $n = $loop->index;
                    $character = $row['character'];
                    $name = $character['name'] ?? 'Anchor';
                    $hasPrompt = $row['prompt'] !== null;
                    $cards = collect($row['cells'])
                        ->flatMap(fn ($cell) => collect($cell['candidates'])->map(fn ($candidate) => [
                            'cell' => $cell,
                            'candidate' => $candidate,
                        ]))
                        ->values();
                @endphp

                <div class="va-row">
                    <div class="va-row-who">
                        <b>{{ $name }}</b>
                        <span class="m">{{ $character !== null ? $character['kind'].' · nhân vật chính' : 'từ brief Haiku' }}</span>
                        <span class="va-tag {{ $hasPrompt ? 'ok' : '' }}">{{ $hasPrompt ? 'Đã có prompt' : 'Chưa có prompt' }}</span>
                        @if(collect($row['cells'])->contains('status', \App\Enums\DesignImageStatus::APPROVED->value))
                            <span class="va-tag ok">Đã duyệt anchor</span>
                        @endif
                    </div>

                    <div class="va-row-prompt">
                        <div class="va-row-bar">
                            @if($hasPrompt)
                                <div class="m">Viết bởi <b>{{ $row['prompt_version'] ?? '—' }}</b></div>
                            @else
                                <div class="m" style="color:var(--vp-red)">
                                    {{ $character !== null ? 'Chưa có prompt cho '.$name.' — bấm Creat Prompt.' : $compileReason }}
                                </div>
                            @endif

                            @if(! $brief['analysed'])
                                <button class="vp-btn sm" disabled title="Cần brief Haiku trước">Creat Prompt</button>
                            @elseif($anchorPrompt['running'])
                                <button class="vp-btn sm" disabled>Đang viết…</button>
                            @else
                                <form method="POST" action="{{ route('video-projects.concept', $project->id) }}"
                                      id="conceptForm{{ $n }}" data-modal="confirmConcept{{ $n }}"
                                      onsubmit="return vpLockForm(this)" hidden>
                                    @csrf
                                    @if($character !== null)
                                        <input type="hidden" name="character_id" value="{{ $character['id'] }}">
                                        @if($hasPrompt)<input type="hidden" name="force" value="1">@endif
                                    @endif
                                </form>
                                <button type="button" class="vp-btn sm pri" data-toggle="modal"
                                        data-target="#confirmConcept{{ $n }}" data-busy="Đang viết…">
                                    {{ $hasPrompt ? 'Viết lại prompt' : 'Creat Prompt' }}
                                </button>
                                @include('modal.confirm_action', [
                                    'id' => 'confirmConcept'.$n,
                                    'form' => 'conceptForm'.$n,
                                    'content' => $character !== null
                                        ? 'Gọi '.$promptModel.' viết prompt ảnh anchor cho nhân vật "'.$name.'" từ bản phân cảnh — tác vụ này tính tiền.'
                                        : 'Gọi '.$promptModel.' viết prompt ảnh từ brief Haiku — tác vụ này tính tiền.',
                                    'detail' => $character !== null && $hasPrompt
                                        ? 'Bỏ qua prompt đã lưu của nhân vật này và gọi model viết bản mới.'
                                        : 'Cùng nội dung + cùng bộ luật thì dùng lại bản đã lưu, không gọi lại model.',
                                ])
                            @endif
                        </div>
                        <textarea class="va-ta" readonly>{{ $row['prompt'] ?? '' }}</textarea>
                        <div class="va-count"><span>{{ mb_strlen($row['prompt'] ?? '') }}</span> ký tự</div>
                    </div>

                    <div class="va-row-set">
                        <form method="POST" action="{{ route('video-projects.anchor-image', $project->id) }}"
                              id="anchorImageForm{{ $n }}" data-modal="confirmAnchorImage{{ $n }}"
                              onsubmit="return vpLockForm(this)" hidden>
                            @csrf
                            <input type="hidden" name="prompt_sha256" value="{{ $row['prompt_hash'] ?? '' }}">
                            @if($character !== null)
                                <input type="hidden" name="character_id" value="{{ $character['id'] }}">
                            @endif
                        </form>

                        <div class="va-fields">
                            <div class="va-field">
                                <label>Asset Name <em>(mã dự kiến)</em></label>
                                <div class="ctl"><span>{{ $nextImageCode }}</span><span class="cnt">{{ strlen($nextImageCode) }}/100</span></div>
                            </div>
                            <div class="va-field">
                                <label>Size</label>
                                <select class="ctl" name="size" form="anchorImageForm{{ $n }}" required @disabled(! $hasPrompt)>
                                    <option value="" @selected($row['size'] === null)>Choose size</option>
                                    @foreach(\App\Enums\ImageSize::cases() as $r)
                                        <option value="{{ $r->value }}" @selected($row['size']?->value === $r->value)>{{ $r->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="va-field">
                                <label>Model</label>
                                <select class="ctl" name="model" form="anchorImageForm{{ $n }}" required @disabled(! $hasPrompt)>
                                    <option value="" @selected($row['model'] === null)>Choose model</option>
                                    @foreach(\App\Enums\ImageModel::cases() as $m)
                                        <option value="{{ $m->value }}" @selected($row['model']?->value === $m->value)>{{ $m->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="va-field">
                                <label>Quality</label>
                                <select class="ctl" name="quality" form="anchorImageForm{{ $n }}" required @disabled(! $hasPrompt)>
                                    <option value="" @selected($row['quality'] === null)>Choose quality</option>
                                    @foreach(\App\Enums\ImageQuality::cases() as $q)
                                        <option value="{{ $q->value }}" title="{{ $q->hint() }}"
                                                data-models="{{ collect(\App\Enums\ImageModel::cases())->filter(fn ($m) => $m->supports($q))->map(fn ($m) => $m->value)->implode(' ') }}"
                                                @selected($row['quality']?->value === $q->value)>{{ $q->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="va-field">
                                <label>Variations</label>
                                <select class="ctl" name="variations" form="anchorImageForm{{ $n }}" required @disabled(! $hasPrompt)>
                                    @foreach(\App\Enums\ImageVariations::cases() as $v)
                                        <option value="{{ $v->value }}" @selected($row['variations']?->value === $v->value)>{{ $v->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="va-foot">
                            <button type="button" class="vp-btn pri" data-anchor-form="anchorImageForm{{ $n }}"
                                    data-toggle="modal" data-target="#confirmAnchorImage{{ $n }}"
                                    data-busy="Đang render…">Render Image</button>
                            @include('modal.confirm_action', [
                                'id' => 'confirmAnchorImage'.$n,
                                'form' => 'anchorImageForm'.$n,
                                'content' => 'Gửi prompt của "'.$name.'" cho model đã chọn render — TÁC VỤ NÀY TÍNH TIỀN.',
                                'detail' => 'Đang tính…',
                            ])
                        </div>
                    </div>

                    <div class="va-row-cands">
                        @foreach($row['cells'] as $cell)
                            @php
                                $cellKey = $n.'_'.$loop->index;
                            @endphp

                            @if($cell['candidates'] === [])
                                <div class="va-cell">
                                    <span class="code">{{ $cell['image_code'] }}</span>
                                    <span class="va-tag {{ $cell['status_tone'] }}">{{ $cell['status_label'] }}</span>
                                    <span class="grow"></span>
                                    <span class="d">{{ $cell['variations'] }} ảnh &middot; {{ $cell['quality'] }} &middot; {{ $cell['size'] }}</span>
                                </div>
                            @endif

                            @if($cell['render_error'])
                                <div class="alert alert-danger">{{ $cell['render_error'] }}</div>
                            @endif

                            @if($cell['is_live'])
                                <div class="va-lbl" style="color:var(--vp-amber-fg);font-weight:400">
                                    @if($cell['worker'] === null)
                                        Đang chờ worker nhận việc{{ $cell['queued_at'] ? ' — vào hàng đợi '.$cell['queued_at']->diffForHumans() : '' }}.
                                        Tải lại trang để xem tiến độ.
                                    @elseif(in_array($cell['worker'], ['laravel:direct', 'laravel:canonical'], true))
                                        Laravel đang render ngay trong request này — trang sẽ đứng đợi tới khi có ảnh.
                                        Nếu request bị ngắt, tải lại trang là kết quả được đối chiếu từ đĩa.
                                    @else
                                        Worker <b>{{ $cell['worker'] }}</b> đang render. Tải lại trang để xem tiến độ.
                                    @endif
                                </div>
                            @elseif($cell['can_render'])
                                <form method="POST" id="renderCell{{ $cellKey }}"
                                      action="{{ route('video-projects.design-image-enqueue', [$project->id, $cell['id']]) }}"
                                      data-modal="confirmRender{{ $cellKey }}" onsubmit="return vpLockForm(this)">
                                    @csrf
                                </form>
                                <button type="button" class="vp-btn pri" data-toggle="modal"
                                        data-target="#confirmRender{{ $cellKey }}" data-busy="Đang xếp hàng…">
                                    {{ $cell['has_failed'] ? 'Render lại →' : 'Render Anchor →' }}
                                </button>
                                @include('modal.confirm_action', [
                                    'id' => 'confirmRender'.$cellKey,
                                    'form' => 'renderCell'.$cellKey,
                                    'content' => 'Gửi ô này cho gpt-image-2 render — TÁC VỤ NÀY TÍNH TIỀN.',
                                    'detail' => $cell['variations'].' ảnh · '.$cell['quality'].' · '.$cell['size']
                                        .' — ước lượng $'.number_format($cell['cost_estimate'], 3),
                                ])
                            @endif
                        @endforeach

                        @if($cards->isEmpty())
                            <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">
                                Chưa có hình ảnh nào - bấm <b>Render Image</b>.
                            </div>
                        @else
                            <form method="POST" action="{{ route('video-projects.anchor-approve', $project->id) }}"
                                  id="approveAnchorForm{{ $n }}" onsubmit="return vpLockForm(this)" hidden>
                                @csrf
                            </form>

                            <div class="va-cands">
                                @foreach($cards as $cardIndex => $card)
                                    @php
                                        $cell = $card['cell'];
                                        $candidate = $card['candidate'];
                                    @endphp
                                    <div class="va-cand">
                                        <img src="{{ $candidate['url'] }}" alt="Candidate {{ $cardIndex + 1 }}"
                                             width="{{ $candidate['width'] }}" height="{{ $candidate['height'] }}">
                                        <div class="cap">
                                            <span>
                                                <input type="radio" name="artifact_id" form="approveAnchorForm{{ $n }}" required
                                                       value="{{ $candidate['id'] }}"
                                                       @disabled(! $cell['can_approve'])>
                                                CANDIDATE {{ $cardIndex + 1 }}
                                            </span>
                                            @if($cell['selected_artifact_id'] === $candidate['id'])
                                                <b>Preferred</b>
                                            @endif
                                        </div>
                                        <div class="meta">
                                            <span>{{ $cell['image_code'] }}</span>
                                            <span>Model: {{ $cell['model'] ?? '—' }}</span>
                                            <span>{{ $candidate['width'] }}×{{ $candidate['height'] }}</span>
                                            <span>Created: {{ $candidate['created_at']?->format('Y-m-d H:i:s') ?? '—' }}</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <div class="va-foot">
                                <button type="submit" form="approveAnchorForm{{ $n }}" class="vp-btn ok"
                                        data-approve-form="approveAnchorForm{{ $n }}" disabled
                                        title="Chọn một ảnh candidate trước"
                                        data-busy="Đang duyệt…">✓ Approve as Canonical Anchor 🔒</button>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
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

            button.disabled = !(hasPrompt && missing.length === 0);
            button.title = !hasPrompt
                ? 'Chưa có anchor prompt'
                : (missing.length ? 'Chưa chọn: ' + missing.join(', ') : '');

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
