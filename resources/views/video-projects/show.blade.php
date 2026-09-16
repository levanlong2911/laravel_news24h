@extends('layouts.base', ['title' => 'Render video'])
@section('title', 'Render video')

@section('css')
<link rel="stylesheet" href="{{ asset('assets/css/video-producer.css') }}?v={{ filemtime(public_path('assets/css/video-producer.css')) }}">
@endsection

@section('content')

<div class="container-fluid vp">

    <div class="vp-crumb">
        <a href="{{ route('video-projects.index') }}">Video Projects</a>
        <span class="sep">/</span>
        <a href="{{ route('video-projects.scene', $id) }}">Scenes</a>
        <span class="sep">·</span>
        <b>Render video</b>
        <span class="grow"></span>
        <span class="m">{{ count($scenes) }} scene</span><span class="sep">·</span>
        <span class="m">Ảnh duyệt <b>{{ $summary['with_keyframe'] }}</b></span><span class="sep">·</span>
        <span class="m">Đang dựng <b id="clipRunning">{{ $summary['running'] }}</b></span><span class="sep">·</span>
        <span class="m">Xong <b id="clipDone">{{ $summary['done'] }}</b></span>
        @if($summary['failed'])
            <span class="sep">·</span>
            <span class="m" style="color:var(--vp-amber-fg)">Hỏng <b>{{ $summary['failed'] }}</b></span>
        @endif
        <a class="vp-btn sm" href="{{ route('video-projects.final-composition-preview', $id) }}"
           title="Mở giao diện Final Composition tĩnh">
            <i class="fas fa-film"></i> Final Composition
        </a>
    </div>

    @if($clipModels === [])
        <div class="vs-c" style="margin-bottom:10px;color:var(--vp-amber-fg)">
            <b>Chưa có model clip nào được xác minh.</b>
            Chạy <code>php artisan video:list-gemini-models --json</code> rồi điền
            <code>config/video.php → media_models.video.scene_clip</code>.
        </div>
    @endif

    <div class="vs-grid vs-clip">

        <div class="vs-h"><b>1</b> Prompt clip</div>
        <div class="vs-h"><b>2</b> Ảnh</div>
        <div class="vs-h"><b>3</b> Thiết lập</div>
        <div class="vs-h"><b>4</b> Video</div>

        @forelse($scenes as $s)
            @php($sceneId = $s['scene_id'])
            @php($clip = $clips[$sceneId] ?? null)
            @php($approved = $keyframes[$sceneId]['approved'] ?? null)
            @php($status = $clip['status'] ?? null)
            @php($running = in_array($status, ['submitting', 'submitted', 'provider_running', 'polling'], true))
            @php($model = $clipModels[0] ?? null)
            {{-- Shot duoc sinh luc bam Render, nen thieu shot KHONG con la ly do chan. --}}
            @php($blocked = match (true) {
                ! $approved => 'cần ảnh đã duyệt trước',
                ! $s['video_prompt'] => 'chưa có prompt clip',
                $clipModels === [] => 'chưa có model clip',
                default => null,
            })

            <div class="vs-row" data-scene="{{ $sceneId }}"
                 data-poll-url="{{ $clip && $clip['render_id'] ? route('video-projects.scene-clip-poll', [$id, $clip['render_id']]) : '' }}"
                 @if($running) data-watch="1" @endif>

                <div class="vs-c vs-prompt">
                    <span class="o">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                    <span class="t">{{ $s['title'] ?? $sceneId }}</span>
                    @if($s['video_prompt'])
                        <div class="body">{{ $s['video_prompt'] }}</div>
                    @else
                        <div class="m">chưa có prompt clip — sinh ở màn Scenes</div>
                    @endif
                </div>

                <div class="vs-c">
                    @php($chosen = $approved
                        ? collect($approved['artifacts'])->firstWhere('id', $approved['selected_artifact_id'])
                        : null)
                    @if($chosen)
                        <a class="vs-shot" href="{{ $chosen['url'] }}" target="_blank">
                            <img src="{{ $chosen['url'] }}" alt="">
                        </a>
                    @else
                        <div class="vs-shot empty">chưa duyệt ảnh</div>
                    @endif
                </div>

                <div class="vs-c vs-ctrl js-ctrl">
                    <form class="vs-ctrl js-clip-form"
                          data-action="{{ route('video-projects.scene-clip-render', [$id, $sceneId]) }}">
                        @csrf

                        <label>Model</label>
                        <select name="model_id" class="js-model">
                            @forelse($clipModels as $choice)
                                <option value="{{ $choice['id'] }}"
                                        data-resolutions="{{ json_encode($choice['controls']['resolutions']) }}"
                                        data-aspects="{{ json_encode($choice['controls']['aspect_ratios']) }}"
                                        data-durations="{{ json_encode($choice['controls']['durations']) }}"
                                        data-long="{{ json_encode($choice['controls']['long_resolutions'] ?? []) }}"
                                        data-longduration="{{ $choice['controls']['long_resolution_duration'] ?? '' }}"
                                        @selected($choice['default'])>{{ $choice['label'] }}</option>
                            @empty
                                <option>—</option>
                            @endforelse
                        </select>

                        <label>Quality</label>
                        <select name="resolution" class="js-resolution">
                            @foreach($model['controls']['resolutions'] ?? [] as $value)
                                <option value="{{ $value }}" @selected($value === ($model['controls']['default_resolution'] ?? null))>{{ $value }}</option>
                            @endforeach
                        </select>

                        <label>Size</label>
                        <select name="aspect_ratio" class="js-aspect">
                            @foreach($model['controls']['aspect_ratios'] ?? [] as $value)
                                <option value="{{ $value }}" @selected($value === ($model['controls']['default_aspect_ratio'] ?? null))>{{ $value }}</option>
                            @endforeach
                        </select>

                        <label>Thời lượng</label>
                        <select name="duration_seconds" class="js-duration">
                            @foreach($model['controls']['durations'] ?? [] as $value)
                                <option value="{{ $value }}" @selected($value === ($model['controls']['default_duration'] ?? null))>{{ $value }}s</option>
                            @endforeach
                        </select>

                        {{--
                          So luong khoa o 1: duong render ghi DUNG mot artifact va manifest
                          cung doi dung mot. Mo o day ma duoi khong doc duoc la tra tien cho
                          nhung clip khong ai nhan.
                        --}}
                        <label>Số lượng video</label>
                        <select disabled title="Đường render hiện tại ghi đúng một clip mỗi lượt">
                            <option>1</option>
                        </select>

                        <button type="submit" class="vp-btn pri sm js-submit"
                                {{ $blocked === null && ! $running ? '' : 'disabled' }}>
                            {{ $status === 'failed' ? '↻ Dựng lại clip' : '▸ Render video' }}
                        </button>
                    </form>

                    <div class="m js-note">
                        @if($running)
                            đang dựng · đã hỏi {{ $clip['poll_count'] }} lần
                        @elseif($blocked !== null)
                            {{ $blocked }}
                        @elseif($status === 'failed')
                            <span style="color:var(--vp-amber-fg)">{{ $clip['error'] }}</span>
                        @endif
                    </div>

                    {{-- Chi hien khi da het luot tu kiem tra: mot o dang dung ma khong con
                         duong nao tien len la mot ngo cut, te hon mot cai nut. --}}
                    <button type="button" class="vp-btn sm js-poll" hidden>↻ Kiểm tra lại</button>
                </div>

                <div class="vs-c">
                    @if($status === 'succeeded' && ($clip['artifact_path'] ?? null))
                        <div class="vs-stage js-stage">
                            {{-- `#t=0.1`: khong co no thi trinh duyet chi tai metadata roi de o den.
                                 Doan fragment nay bat no seek toi 0.1s va ve mot khung hinh that. --}}
                            <video preload="metadata" muted playsinline
                                   src="{{ route('video-projects.scene-clip-file', [$id, $clip['render_id']]) }}#t=0.1"></video>
                        </div>
                        <div class="m js-meta">{{ $clip['width'] }}×{{ $clip['height'] }}
                            @if($clip['duration_ms']) · {{ round($clip['duration_ms'] / 1000, 1) }}s @endif
                            · bấm để phóng to</div>
                    @elseif($running)
                        <div class="vs-stage js-stage"><span><span class="dot"></span>đang dựng video — chờ một chút</span></div>
                        <div class="m js-meta"></div>
                    @elseif($status === 'failed')
                        <div class="vs-stage broken js-stage">hỏng</div>
                        <div class="m js-meta"></div>
                    @else
                        <div class="vs-stage idle js-stage">chờ — chưa gửi render</div>
                        <div class="m js-meta"></div>
                    @endif
                </div>

            </div>
        @empty
            <div class="vs-c" style="grid-column:1/-1;color:var(--vp-dim)">
                Chưa có scene nào — sinh danh sách scene ở màn hình Scenes trước.
            </div>
        @endforelse

    </div>
</div>

<div class="modal fade" id="clipModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <video id="clipModalVideo" controls autoplay playsinline></video>
    </div>
  </div>
</div>

@endsection

@section('script')
<script>
(function () {
    var token = document.querySelector('meta[name="csrf-token"]');
    token = token ? token.content : (document.querySelector('input[name="_token"]') || {}).value;

    // Nhip tu kiem tra, tinh bang giay. Gian dan roi dung han: mot clip 8 giay ma
    // sau chung nay van chua xong thi co chuyen, va hoi mai chi ton quota cua nguoi
    // dung. Het luot thi tra nut lai cho nguoi bam.
    var STEPS = [15, 20, 30, 45, 60, 60, 60, 60, 60, 60];

    function stage(row) { return row.querySelector('.js-stage'); }
    function note(row) { return row.querySelector('.js-note'); }

    function waiting(row, text) {
        stage(row).className = 'vs-stage js-stage';
        stage(row).innerHTML = '<span><span class="dot"></span>' + text + '</span>';
    }

    function showVideo(row, url, meta) {
        stage(row).className = 'vs-stage js-stage';
        stage(row).innerHTML = '<video preload="metadata" muted playsinline src="' + url + '#t=0.1"></video>';
        row.querySelector('.js-meta').textContent = (meta || '') + ' · bấm để phóng to';
    }

    // Doi model thi ba o duoi phai doi theo controls CUA model do — khong de nguoi
    // dung gui mot to hop ma registry khong nhan.
    function syncControls(form) {
        var model = form.querySelector('.js-model');
        var option = model ? model.options[model.selectedIndex] : null;

        if (!option || !option.dataset.resolutions) { return; }

        [['resolutions', '.js-resolution', ''], ['aspects', '.js-aspect', ''], ['durations', '.js-duration', 's']]
            .forEach(function (pair) {
                var select = form.querySelector(pair[1]);
                var values = JSON.parse(option.dataset[pair[0]] || '[]');
                var keep = select.value;
                select.innerHTML = '';
                values.forEach(function (value) {
                    var el = document.createElement('option');
                    el.value = value;
                    el.textContent = value + pair[2];
                    el.selected = String(value) === keep;
                    select.appendChild(el);
                });
            });
    }

    // Luat cheo doc tu registry (data-long / data-longduration), KHONG viet lai o
    // day: server cung doc dung cai do, nen man hinh khong bao gio cho chon mot to
    // hop ma server se tu choi.
    function applyCrossRule(form) {
        var model = form.querySelector('.js-model');
        var option = model ? model.options[model.selectedIndex] : null;

        if (!option) { return; }

        var long = JSON.parse(option.dataset.long || '[]');
        var locked = option.dataset.longduration;

        if (!long.length || !locked) { return; }

        var resolution = form.querySelector('.js-resolution');
        var duration = form.querySelector('.js-duration');
        var mustLock = long.indexOf(resolution.value) !== -1;

        Array.prototype.forEach.call(duration.options, function (o) {
            o.disabled = mustLock && o.value !== String(locked);
        });

        if (mustLock) { duration.value = String(locked); }
    }

    function apply(row, data) {
        var submit = row.querySelector('.js-submit');
        var again = row.querySelector('.js-poll');

        note(row).textContent = data.note || '';

        if (data.poll_url) { row.dataset.pollUrl = data.poll_url; }

        if (data.state === 'running') {
            submit.disabled = true;
            again.hidden = true;
            watch(row);

            return;
        }

        stopWatch(row);
        submit.disabled = false;
        again.hidden = true;

        if (data.state === 'succeeded') {
            showVideo(row, data.file_url, data.meta);

            return;
        }

        if (data.state === 'failed') {
            stage(row).className = 'vs-stage broken js-stage';
            stage(row).textContent = 'hỏng';
        }
    }

    function send(url, row, busyText) {
        if (busyText) { waiting(row, busyText); }

        var body = new FormData();
        var form = row.querySelector('.js-clip-form');

        if (form) {
            ['model_id', 'resolution', 'aspect_ratio', 'duration_seconds'].forEach(function (name) {
                var field = form.querySelector('[name="' + name + '"]');
                if (field) { body.append(name, field.value); }
            });
        }

        return fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: body,
        })
            .then(function (res) {
                // Trang loi HTML hay JSON la deu KHONG duoc nuot: payload cua Laravel
                // (validation/404) khong co `state`, o se tu reset va nguoi bam khong
                // bao gio biet vi sao.
                return res.text().then(function (text) {
                    var data;

                    try { data = JSON.parse(text); } catch (err) {
                        throw new Error('máy chủ trả về HTTP ' + res.status + ', không phải JSON');
                    }

                    if (typeof data.state !== 'string') {
                        throw new Error(data.message || ('HTTP ' + res.status + ': không đọc được trạng thái'));
                    }

                    return data;
                });
            })
            .then(function (data) { apply(row, data); })
            .catch(function (e) {
                stopWatch(row);
                note(row).textContent = e.message;
                stage(row).className = 'vs-stage idle js-stage';
                stage(row).textContent = 'chờ — chưa gửi render';
                row.querySelector('.js-submit').disabled = false;
                row.querySelector('.js-poll').hidden = false;
            });
    }

    // ---- tu kiem tra --------------------------------------------------------
    //
    // Vong hen gio nay chay o TRINH DUYET, khong phai o server: dac ta Pha 4 cam
    // vong lap trong controller, khong cam man hinh tu hoi. Moi luot van la dung
    // mot request len provider, nen no phai gian dan, dung khi tab an, va co tran.

    var timers = {};

    function stopWatch(row) {
        var id = row.dataset.scene;

        if (timers[id]) { clearTimeout(timers[id]); delete timers[id]; }

        delete row.dataset.watching;
        delete row.dataset.step;
    }

    function watch(row) {
        if (row.dataset.watching) { return; }

        row.dataset.watching = '1';
        row.dataset.step = '0';
        schedule(row);
    }

    function schedule(row) {
        var step = parseInt(row.dataset.step || '0', 10);

        if (step >= STEPS.length) {
            note(row).textContent = 'đã dừng tự kiểm tra sau ' + STEPS.length + ' lượt — bấm để hỏi lại';
            row.querySelector('.js-poll').hidden = false;
            stopWatch(row);

            return;
        }

        var wait = STEPS[step];

        row.dataset.step = String(step + 1);
        countdown(row, wait);

        timers[row.dataset.scene] = setTimeout(function () {
            // Tab an thi khoan hoi — de nguyen lich, doi nguoi dung quay lai.
            if (document.hidden) { row.dataset.step = String(step); schedule(row); return; }

            send(row.dataset.pollUrl, row, null);
        }, wait * 1000);
    }

    function countdown(row, seconds) {
        var left = seconds;

        function tick() {
            if (!row.dataset.watching) { return; }

            waiting(row, 'đang dựng video — tự kiểm tra sau ' + left + 's');
            left--;

            if (left >= 0) { setTimeout(tick, 1000); }
        }

        tick();
    }

    // ---- su kien ------------------------------------------------------------

    document.addEventListener('change', function (e) {
        var form = e.target.closest('.js-clip-form');

        if (!form) { return; }

        if (e.target.classList.contains('js-model')) { syncControls(form); }

        applyCrossRule(form);
    });

    document.addEventListener('submit', function (e) {
        var form = e.target.closest('.js-clip-form');

        if (!form) { return; }

        e.preventDefault();

        if (!form.dataset.action) { return; }

        var scene = form.closest('.vs-row').querySelector('.t').textContent.trim();

        if (!confirm('Gửi request dựng clip cho "' + scene + '"? Đây là lượt TÍNH TIỀN.')) { return; }

        form.querySelector('.js-submit').disabled = true;
        send(form.dataset.action, form.closest('.vs-row'), 'đang gửi request…');
    });

    document.addEventListener('click', function (e) {
        var again = e.target.closest('.js-poll');

        if (again) {
            var row = again.closest('.vs-row');
            again.hidden = true;
            send(row.dataset.pollUrl, row, 'đang hỏi provider…');

            return;
        }

        var video = e.target.closest('.vs-stage video');

        if (video) {
            var modal = document.getElementById('clipModalVideo');
            modal.src = video.getAttribute('src');
            $('#clipModal').modal('show');
        }
    });

    $('#clipModal').on('hidden.bs.modal', function () {
        var modal = document.getElementById('clipModalVideo');
        modal.pause();
        modal.removeAttribute('src');
        modal.load();
    });

    document.querySelectorAll('.js-clip-form').forEach(applyCrossRule);

    // Hang dang dung san luc mo trang cung duoc trong — nhung lech nhau vai giay
    // de mo mot trang 20 scene khong ban 20 request cung luc.
    Array.prototype.forEach.call(document.querySelectorAll('.vs-row[data-watch]'), function (row, index) {
        setTimeout(function () { watch(row); }, index * 2000);
    });
})();
</script>@endsection
