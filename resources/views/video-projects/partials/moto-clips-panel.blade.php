@php
    $panel = $motoClips;
    $fragment = $fragment ?? false;
    $groups = $panel['groups'];
    $motionLabels = ['ai_video' => 'AI Video — model tạo chuyển động', 'dynamic_crop' => 'Dynamic Crop — dịch khung trên ảnh tĩnh'];
    $inputLabels = ['keyframe' => 'Từ bộ keyframe đã duyệt', 'references' => 'Từ ảnh tham chiếu'];
    $audioLabels = ['allow_silent' => 'Cho phép file không tiếng', 'require_audio' => 'Bắt buộc có luồng tiếng'];
    $sec = static fn (?int $ms): string => $ms === null ? '—' : rtrim(rtrim(number_format($ms / 1000, 3, '.', ''), '0'), '.').' s';
    $tag = static fn (array $state): string => '<span class="va-tag '.e($state['tone']).'">'.e($state['label']).'</span>';
@endphp

@unless($fragment)
<div class="container-fluid vp" id="clipsPage">
    @include('video-projects.partials.moto-reference-flash', ['flashId' => 'clipsFlash'])

    <div class="vp-crumb">
        <a href="{{ route('video-projects.index') }}">Video Projects</a>
        <span class="sep">/</span>
        <a href="{{ route('video-projects.anchor', $id) }}">Kịch bản</a>
        <span class="sep">·</span>
        <a href="{{ route('video-projects.scene', $id) }}">Scenes</a>
        <span class="sep">·</span>
        <a href="{{ route('video-projects.reference', $id) }}">Reference</a>
        <span class="sep">·</span>
        <a href="{{ route('video-projects.keyframes', $id) }}">Keyframes</a>
        <span class="sep">·</span>
        <b>Render video</b>
        <span class="grow"></span>
        <span class="m">{{ $panel['counts']['shots'] }} shot</span><span class="sep">·</span>
        <span class="m">Đang dùng <b>{{ $panel['counts']['in_use'] }}</b></span><span class="sep">·</span>
        <span class="m">Chờ duyệt <b>{{ $panel['counts']['pending'] }}</b></span><span class="sep">·</span>
        <span class="m">Đang chạy <b>{{ $panel['counts']['running'] }}</b></span>
        <a class="vp-btn sm" href="{{ route('video-projects.final-composition-preview', $id) }}"><i class="fas fa-film"></i> Final Composition</a>
    </div>

    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="vp-panel va-anchor">
        <div class="va-head">
            <b>CLIP VIDEO</b>
            <em>mỗi shot: chọn cách tạo chuyển động và nguồn ảnh, render ứng viên, kiểm bằng trình phát và dải ảnh, rồi duyệt — chỉ clip đã duyệt được bàn giao cho Phase 11</em>
        </div>
        <div class="va-body">
            <div class="va-brief">
                @if($panel['problem'] !== null)
                    <div class="line"><span class="k">Chưa dùng được</span><span class="v" style="color:var(--vp-red)">{{ $panel['problem'] }}</span></div>
                @else
                    <div class="line"><span class="k">Kế hoạch shot</span><span class="v">rev {{ $panel['stage']['revision'] }} · {{ substr($panel['stage']['id'], 0, 8) }} · {{ $panel['stage']['content_hash'] }}</span></div>
                    <div class="line"><span class="k">Hợp đồng</span><span class="v">Phase 10 rev {{ $panel['contract']['phase_revision'] }} · {{ $panel['contract']['builder'] }} · {{ $panel['contract']['writer'] }} · {{ $panel['contract']['policy'] }}</span></div>
                    <div class="line"><span class="k">Nguyên tắc</span><span class="v">Đổi thiết lập chỉ chuẩn bị lượt render kế tiếp: không gọi AI, không render, không đổi clip đang dùng. Một lượt render xong chỉ là ứng viên; chỉ bấm duyệt mới thay clip đang dùng.</span></div>
                @endif
            </div>
        </div>
    </div>
@endunless

    @foreach($panel['rows'] as $row)
        @php
            $shotId = $row['shot_id'];
            $clip = $row['clip'];
            $settings = $row['settings'];
            $choices = $row['choices'];
            $render = $row['render'];
            $writer = $row['writer'];
            $crop = $row['crop'];
            $settingsForm = 'cpSettings'.$shotId;
            $renderForm = 'cpRender'.$shotId;
            $promptForm = 'cpPrompt'.$shotId;
            $byReferences = $choices['motion'] === 'ai_video' && $choices['mode'] === 'references';
            $isCrop = $choices['motion'] === 'dynamic_crop';
            $used = $clip['used'];
            $sentAt = array_column($clip['images'], 'position', 'artifact_id');
            $shown = $writer['shown'] ?? null;
            $running = $row['current'];
            $inUse = $row['in_use'];
        @endphp
        <div class="vs-shot-detail" id="clip-{{ $shotId }}" data-ajax-part>
            <div class="vs-shot-grid vc-grid">
                <div class="vs-h"><b>1</b> ShotSpec</div>
                <div class="vs-h"><b>2</b> Nguồn và thiết lập</div>
                <div class="vs-h"><b>3</b> Prompt video</div>
                <div class="vs-h"><b>4</b> Clip</div>

                <div class="vs-c vs-plan">
                    <span class="o">{{ $shotId }}</span>
                    <span class="t">{{ $row['scene_id'] }} · thứ tự {{ $row['order'] }}</span>
                    <div class="d">{{ $row['spec']['purpose'] }}</div>
                    <div class="d"><b>Khung mở đầu:</b> {{ $row['spec']['first_frame'] }}</div>
                    @foreach($row['spec']['steps'] as $step)
                        <div class="d"><b>{{ $step['id'] }} [{{ $step['start_ms'] }}–{{ $step['end_ms'] }} ms]</b> {{ $step['action'] }} → <i>{{ $step['result'] }}</i></div>
                    @endforeach
                    @foreach($row['spec']['camera'] as $segment)
                        <div class="d"><b>Camera {{ $segment['id'] }} [{{ $segment['start_ms'] }}–{{ $segment['end_ms'] }} ms]</b> {{ $segment['description'] }}</div>
                    @endforeach
                    <div class="d"><b>Khung cuối:</b> {{ $row['spec']['end_frame'] }}</div>
                    <div class="d">Nội dung {{ $sec($clip['content_duration_ms']) }} · phim giữ {{ $used['start_ms'] }}–{{ $used['start_ms'] + $used['duration_ms'] }} ms</div>
                </div>

                <div class="vs-c">
                    <form method="POST" action="{{ route('video-projects.clip-prompt-action', [$id, $shotId, 'settings']) }}" id="{{ $settingsForm }}" data-clip-settings>
                        @csrf
                        <input type="hidden" name="expected_revision" value="{{ $settings['revision'] }}" data-ajax-fresh>
                        <input type="hidden" name="expected_source_stage" value="{{ $panel['stage']['id'] }}" data-ajax-fresh>
                        <input type="hidden" name="expected_source_hash" value="{{ $panel['stage']['content_hash'] }}" data-ajax-fresh>
                        <div class="vk-controls">
                            <div class="va-field">
                                <label>Cách tạo chuyển động</label>
                                <select class="ctl" name="motion_method">
                                    @foreach($choices['motions'] as $choice)
                                        <option value="{{ $choice['value'] }}" @selected($choice['value'] === $choices['motion']) @disabled(! $choice['available'] && $choice['value'] !== $choices['motion']) title="{{ $choice['reason'] }}">{{ $motionLabels[$choice['value']] ?? $choice['value'] }}{{ $choice['available'] ? '' : ' — chưa dùng được' }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="va-field">
                                <label>Nguồn khung mở đầu</label>
                                <select class="ctl" name="input_mode">
                                    @foreach($choices['inputs'] as $choice)
                                        <option value="{{ $choice['value'] }}" @selected($choice['value'] === $choices['mode']) @disabled(! $choice['available'] && $choice['value'] !== $choices['mode']) title="{{ $choice['reason'] }}">{{ $inputLabels[$choice['value']] ?? $choice['value'] }}{{ $choice['available'] ? '' : ' — chưa dùng được' }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @if($choices['models'] !== [])
                                <div class="va-field">
                                    <label>Model</label>
                                    <select class="ctl" name="model">
                                        <option value="" @selected($settings['model'] === null)>Mặc định của chính sách: {{ $choices['default_model'] ?? 'chưa có model dùng được' }}</option>
                                        @foreach($choices['models'] as $choice)
                                            <option value="{{ $choice['value'] }}" @selected($choice['value'] === $settings['model']) @disabled(! $choice['available'] && $choice['value'] !== $settings['model']) title="{{ $choice['reason'] }}">{{ $choice['label'] }}{{ $choice['available'] ? '' : ' — chưa dùng được' }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                            <div class="va-field">
                                <label>Resolution</label>
                                <select class="ctl" name="resolution">
                                    <option value="" @selected($settings['resolution'] === null)>Mặc định: {{ $choices['default_resolution'] ?? '—' }}</option>
                                    @foreach($choices['resolutions'] as $choice)
                                        <option value="{{ $choice['resolution'] }}" @selected($choice['resolution'] === $settings['resolution']) @disabled(! $choice['available'] && $choice['resolution'] !== $settings['resolution']) title="{{ $choice['reason'] }}">{{ $choice['resolution'] }} · file {{ $sec($choice['duration_ms']) }}{{ $choice['available'] ? '' : ' — chưa dùng được' }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="va-field">
                                <label>Âm thanh (kiểm file)</label>
                                <select class="ctl" name="audio_policy">
                                    <option value="" @selected($settings['audio_policy'] === null)>Theo chính sách: {{ $audioLabels[$choices['policy_audio']] ?? $choices['policy_audio'] }}</option>
                                    @foreach($choices['audio'] as $choice)
                                        <option value="{{ $choice['value'] }}" @selected($choice['value'] === $settings['audio_policy']) @disabled(! $choice['available'] && $choice['value'] !== $settings['audio_policy']) title="{{ $choice['reason'] }}">{{ $audioLabels[$choice['value']] }}{{ $choice['available'] ? '' : ' — '.$choice['reason'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        @foreach([...$choices['motions'], ...$choices['inputs'], ...$choices['models']] as $choice)
                            @if(! $choice['available'] && $choice['reason'])
                                <div class="d" style="color:var(--vp-dim)">{{ $motionLabels[$choice['value']] ?? $inputLabels[$choice['value']] ?? ($choice['label'] ?? $choice['value']) }} chưa dùng được: {{ $choice['reason'] }}</div>
                            @endif
                        @endforeach
                        @foreach($choices['resolutions'] as $choice)
                            @if(! $choice['available'] && $choice['reason'])
                                <div class="d" style="color:var(--vp-dim)">{{ $choice['resolution'] }}: {{ $choice['reason'] }}</div>
                            @endif
                        @endforeach

                        @if($byReferences)
                            <input type="hidden" name="images_sent" value="1">
                            <div class="va-lbl">Ảnh tham chiếu gửi đi ({{ count($clip['images']) }}{{ $row['limit'] > 0 ? '/'.$row['limit'] : '' }}), đúng thứ tự gửi</div>
                            <div class="vs-refs">
                                @forelse($row['cards'] as $card)
                                    <label class="vs-ref">
                                        @if($card['url'] !== null)
                                            <img src="{{ $card['url'] }}" alt="{{ $card['name'] }}" loading="lazy">
                                        @endif
                                        <span>
                                            <input type="checkbox" name="images[]" value="{{ $card['artifact_id'] }}" @checked(in_array($card['artifact_id'], $settings['images'], true)) @disabled($card['artifact_id'] === null || $card['problem'] !== null)>
                                            @if(isset($sentAt[$card['artifact_id']])){{ $sentAt[$card['artifact_id']] + 1 }}. @endif{{ $card['name'] }}@if($card['problem'] !== null) — {{ $card['problem'] }}@endif
                                        </span>
                                    </label>
                                @empty
                                    <div class="d">Shot này không có ảnh tham chiếu nào.</div>
                                @endforelse
                            </div>
                        @endif
                    </form>

                    <div class="d">
                        Thiết lập revision {{ $settings['revision'] }} ·
                        @if($settings['saved'])
                            đã lưu{{ $settings['updated_at'] ? ' lúc '.\Illuminate\Support\Carbon::parse($settings['updated_at'])->format('Y-m-d H:i') : '' }}
                        @else
                            chưa lưu — đang theo mặc định của chính sách
                        @endif
                    </div>
                    @if(is_string($settings['problem'] ?? null))
                        <div class="d" style="color:var(--vp-red)">{{ $settings['problem'] }}</div>
                    @endif

                    @if($isCrop)
                        @if($crop !== null)
                            @php
                                $start = $crop['start'];
                                $end = $crop['end'];
                                $pct = static fn (int $value, int $total): string => rtrim(rtrim(number_format($value / $total * 100, 4, '.', ''), '0'), '.').'%';
                            @endphp
                            <form method="POST" action="{{ route('video-projects.clip-prompt-action', [$id, $shotId, 'settings']) }}" class="vk-frame-form" data-clip-crop>
                                @csrf
                                <input type="hidden" name="expected_revision" value="{{ $settings['revision'] }}" data-ajax-fresh>
                                <input type="hidden" name="expected_source_stage" value="{{ $panel['stage']['id'] }}" data-ajax-fresh>
                                <input type="hidden" name="expected_source_hash" value="{{ $panel['stage']['content_hash'] }}" data-ajax-fresh>
                                <input type="hidden" name="crop_x0" value="{{ $start['x'] }}">
                                <input type="hidden" name="crop_y0" value="{{ $start['y'] }}">
                                <input type="hidden" name="crop_w0" value="{{ $start['width'] }}">
                                <input type="hidden" name="crop_h0" value="{{ $start['height'] }}">
                                <div class="va-lbl">Đường crop trên ảnh master {{ $crop['master']['width'] }} × {{ $crop['master']['height'] }} → {{ $crop['output'][0] }} × {{ $crop['output'][1] }}</div>
                                <div class="vk-frame" data-frame="crop-{{ $shotId }}" data-width="{{ $crop['master']['width'] }}" data-height="{{ $crop['master']['height'] }}" data-unit-x="{{ $crop['unit'][0] }}" data-unit-y="{{ $crop['unit'][1] }}" data-k-min="{{ $crop['k_min'] }}" data-k-max="{{ $crop['k_max'] }}" data-delivery="{{ $crop['output'][0] }} × {{ $crop['output'][1] }}">
                                    <div class="vk-frame-view">
                                        <div class="vk-frame-stage" data-frame-stage>
                                            <img src="{{ $crop['master']['url'] }}" alt="master" loading="lazy">
                                            <div class="vc-crop-start" style="left:{{ $pct($start['x'], $crop['master']['width']) }};top:{{ $pct($start['y'], $crop['master']['height']) }};width:{{ $pct($start['width'], $crop['master']['width']) }};height:{{ $pct($start['height'], $crop['master']['height']) }}" title="Khung mở đầu = vùng crop đã duyệt ở Phase 9"></div>
                                            <div class="vk-frame-window" data-frame-window></div>
                                        </div>
                                        <div class="vk-frame-preview" data-frame-preview style="aspect-ratio: {{ $crop['unit'][0] }} / {{ $crop['unit'][1] }}; background-image:url('{{ $crop['master']['url'] }}')"></div>
                                    </div>
                                    <input type="hidden" name="crop_x1" data-frame-field="x" value="{{ $end['x'] }}">
                                    <input type="hidden" name="crop_y1" data-frame-field="y" value="{{ $end['y'] }}">
                                    <input type="hidden" name="crop_w1" data-frame-field="width" value="{{ $end['width'] }}">
                                    <input type="hidden" name="crop_h1" data-frame-field="height" value="{{ $end['height'] }}">
                                    <label class="d">Ngang <input type="range" min="0" max="1000" step="any" value="{{ $crop['ranges']['x'] }}" data-frame-range="x"></label>
                                    <label class="d">Dọc <input type="range" min="0" max="1000" step="any" value="{{ $crop['ranges']['y'] }}" data-frame-range="y"></label>
                                    <label class="d">Cỡ khung <input type="range" min="{{ $crop['k_min'] }}" max="{{ $crop['k_max'] }}" value="{{ $crop['ranges']['k'] }}" data-frame-range="k"></label>
                                    <div class="d" data-frame-readout>{{ $end['width'] }} × {{ $end['height'] }} tại x {{ $end['x'] }}, y {{ $end['y'] }}</div>
                                </div>
                                <label class="d">Khung kết thúc tới đúng mốc <input class="ctl" type="number" name="crop_at_ms" min="1" max="{{ $crop['content_ms'] }}" value="{{ (int) ($end['at_ms'] ?? $crop['content_ms']) }}" style="width:90px"> ms (sau mốc này khung giữ nguyên)</label>
                                <div class="d">Khung mở đầu là vùng {{ $start['width'] }} × {{ $start['height'] }} tại x {{ $start['x'] }}, y {{ $start['y'] }} đã duyệt cùng bộ keyframe — không sửa ở đây. Khung kết thúc đúng tỉ lệ 9:16, không nhỏ hơn {{ $crop['output'][0] }} × {{ $crop['output'][1] }} (không phóng lớn), nằm trong ảnh master.</div>
                                <div class="d">Vùng bảo vệ chủ thể: màn này chưa có công cụ khai{{ $crop['protected'] !== null ? ' — đang giữ vùng đã lưu '.$crop['protected']['width'].' × '.$crop['protected']['height'].' tại x '.$crop['protected']['x'].', y '.$crop['protected']['y'] : '' }}; code chỉ kiểm hình học, giữ trọn chủ thể kiểm bằng xem clip và dải ảnh trước khi duyệt.</div>
                                @if(! $crop['saved'])
                                    <div class="d" style="color:var(--vp-amber-fg)">Chưa lưu đường crop — bấm Lưu (giữ nguyên khung là một crop tĩnh).</div>
                                @endif
                                <button class="vp-btn sm" data-busy="Đang lưu…">Lưu đường crop</button>
                            </form>
                        @else
                            <div class="d" style="color:var(--vp-amber-fg)">Dynamic Crop cần bộ keyframe đã duyệt có ảnh master 16:9 và vùng crop — {{ $row['keyframe']['blocked']['message'] ?? 'bộ keyframe của shot chưa có vùng crop đã duyệt.' }}</div>
                        @endif
                    @elseif(! $byReferences)
                        @if($row['keyframe']['url'] !== null)
                            <div class="va-lbl">Khung mở đầu — ảnh video (delivery 9:16) của bộ keyframe đã duyệt</div>
                            <a class="vs-shot" href="{{ $row['keyframe']['url'] }}" target="_blank" rel="noopener"><img src="{{ $row['keyframe']['url'] }}" alt="keyframe" loading="lazy"></a>
                            @if(is_array($row['keyframe']['set']['crop'] ?? null))
                                <div class="d">Cắt từ master {{ $row['keyframe']['set']['master']['width'] }} × {{ $row['keyframe']['set']['master']['height'] }} · vùng {{ $row['keyframe']['set']['crop']['width'] }} × {{ $row['keyframe']['set']['crop']['height'] }} tại x {{ $row['keyframe']['set']['crop']['x'] }}, y {{ $row['keyframe']['set']['crop']['y'] }}</div>
                            @endif
                        @else
                            <div class="d" style="color:var(--vp-amber-fg)">{{ $row['keyframe']['blocked']['message'] ?? 'Shot chưa có bộ keyframe đã duyệt.' }} Không tự chuyển sang ảnh tham chiếu.</div>
                        @endif
                    @endif

                    <div class="vc-render">
                        <div class="d"><b>Lượt render kế tiếp:</b> {{ $render['label'] }} · {{ $clip['render']['resolution'] ?? '—' }} · {{ $clip['render']['aspect_ratio'] }} · file {{ $sec($clip['render']['duration_ms']) }} · phim giữ {{ $used['start_ms'] }}–{{ $used['start_ms'] + $used['duration_ms'] }} ms</div>
                        @if(is_int($clip['render']['duration_ms']) && $clip['render']['duration_ms'] > $used['duration_ms'])
                            <div class="d">Model chỉ làm file {{ $sec($clip['render']['duration_ms']) }}: hành động diễn ra trong {{ $used['start_ms'] }}–{{ $used['start_ms'] + $used['duration_ms'] }} ms, phần sau là đuôi bỏ đi.</div>
                        @endif
                        <div class="d">Âm thanh: {{ $audioLabels[$clip['audio_policy']] ?? $clip['audio_policy'] }}</div>
                        <div class="d">
                            @if($render['local'])
                                Chi phí: không tính tiền — chạy trên máy này, không gọi provider.
                            @elseif($row['cost'] !== null)
                                Dự toán: ${{ number_format($row['cost']['usd'], 3) }} — {{ $row['cost']['basis'] }}
                            @else
                                Dự toán: chưa có bảng giá cho model này.
                            @endif
                        </div>
                        @if($row['blocked'] !== null)
                            <div class="d" style="color:var(--vp-amber-fg)">{{ $row['blocked']['message'] }}</div>
                        @endif
                        <form method="POST" action="{{ route('video-projects.clip-prompt-action', [$id, $shotId, 'render']) }}" id="{{ $renderForm }}" data-modal="confirm{{ $renderForm }}" onsubmit="return vpLockForm(this)" hidden>
                            @csrf
                            <input type="hidden" name="expected_clip_hash" value="{{ $render['hash'] }}">
                            <input type="hidden" name="expected_settings" value="{{ $render['expected_settings'] }}">
                            <input type="hidden" name="expected_intent_version" value="{{ $row['intent_version'] }}">
                            <input type="hidden" name="operation_id" value="{{ \Illuminate\Support\Str::uuid() }}">
                            <input type="hidden" name="model_id" value="{{ $render['model_id'] }}">
                            <input type="hidden" name="resolution" value="{{ $render['resolution'] }}">
                            <input type="hidden" name="aspect_ratio" value="{{ $render['aspect_ratio'] }}">
                            <input type="hidden" name="duration_seconds" value="{{ $render['duration_seconds'] }}">
                            <input type="hidden" name="confirm_cost" value="1">
                        </form>
                        <div class="vk-actions">
                            <button type="button" class="vp-btn pri" data-toggle="modal" data-target="#confirm{{ $renderForm }}" data-busy="Đang gửi…"
                                    @if(! $render['renderable']) disabled title="{{ $render['why'] }}" @endif>{{ $render['local'] ? 'Tạo clip Dynamic Crop' : ($row['history'] === [] ? 'Render video' : 'Render ứng viên mới') }}</button>
                        </div>
                        @include('modal.confirm_action', ['id' => 'confirm'.$renderForm, 'form' => $renderForm,
                            'content' => $render['local']
                                ? 'Tạo clip Dynamic Crop của '.$shotId.' trên máy này từ ảnh master và đường crop đã lưu — không gọi provider, không tính tiền. Kết quả là một ứng viên; clip đang dùng không đổi.'
                                : 'Gửi request render video của '.$shotId.' bằng '.$render['label'].' ở '.$render['resolution'].', file '.$render['duration_seconds'].' s (phim giữ '.$used['start_ms'].'–'.($used['start_ms'] + $used['duration_ms']).' ms) — TÁC VỤ NÀY TÍNH TIỀN. Kết quả là một ứng viên; clip đang dùng không đổi.',
                            'detail' => $row['cost'] !== null ? 'Dự toán $'.number_format($row['cost']['usd'], 3).'.' : ''])
                    </div>
                </div>

                <div class="vs-c vs-prompt">
                    @if($writer === null)
                        <div class="d">Dynamic Crop không cần prompt video — chuyển động là khung nhìn dịch trên ảnh tĩnh.</div>
                    @else
                        <div class="va-lbl">Prompt video — AI viết, gửi nguyên văn bản đã duyệt</div>
                        @if($clip['prompt'] !== null)
                            <div class="d">Đang dùng: lượt {{ substr($clip['prompt']['stage_id'], 0, 8) }} · {{ $clip['prompt']['version'] }}</div>
                            <textarea class="va-ta" readonly>{{ $clip['prompt']['text'] }}</textarea>
                        @elseif($writer['selected'] !== null)
                            <div class="d" style="color:var(--vp-amber-fg)">Prompt đã duyệt (lượt {{ substr($writer['selected']['stage_id'], 0, 8) }}) viết cho thiết lập khác — cần viết hoặc duyệt lại cho thiết lập này.</div>
                        @endif
                        @if($shown !== null && ! $shown['selected'])
                            <div class="d"><b>Lượt viết mới nhất ({{ substr($shown['id'], 0, 8) }}) — chưa duyệt:</b></div>
                            <textarea class="va-ta" readonly>{{ $shown['prompt'] }}</textarea>
                        @endif
                        @if($shown !== null)
                            <div class="d">Lượt {{ $shown['revision'] }} · {{ $shown['verdict'] ?? '—' }} · {{ $shown['status'] ?? '—' }} · {{ $shown['model'] }}@if($shown['cost_usd'] !== null) · ${{ number_format($shown['cost_usd'], 4) }}@endif</div>
                            @foreach([...$shown['schema'], ...$shown['issues']] as $issue)
                                <div class="d" style="color:var(--vp-red)">{{ $issue }}</div>
                            @endforeach
                            @foreach($shown['requirements'] as $requirement)
                                <div class="d" style="color:var(--vp-amber-fg)">{{ $requirement }}</div>
                            @endforeach
                            @foreach($shown['gaps'] as $gap)
                                <div class="d" style="color:var(--vp-amber-fg)">Thiếu dữ kiện: {{ $gap['missing'] ?? '' }} — {{ $gap['reason'] ?? '' }}</div>
                            @endforeach
                            @foreach($shown['conflicts'] as $conflict)
                                <div class="d" style="color:var(--vp-amber-fg)">Mâu thuẫn ({{ implode(' / ', (array) ($conflict['source_refs'] ?? [])) }}): {{ $conflict['reason'] ?? '' }}</div>
                            @endforeach
                            @foreach($shown['flags'] as $flag)
                                <div class="d" style="color:var(--vp-dim)">Ghi chú của AI: {{ $flag }}</div>
                            @endforeach
                            @if(! $shown['current'])
                                <div class="d" style="color:var(--vp-amber-fg)">Lượt này viết từ nguồn hoặc thiết lập cũ</div>
                            @endif
                            @if($shown['approvable'])
                                <form method="POST" action="{{ route('video-projects.clip-prompt-action', [$id, $shotId, 'prompt-approve']) }}" onsubmit="return vpLockForm(this)">
                                    @csrf
                                    <input type="hidden" name="stage_id" value="{{ $shown['id'] }}">
                                    <button class="vp-btn sm ok" data-busy="Đang duyệt…">✓ Duyệt và dùng prompt này</button>
                                </form>
                            @endif
                        @endif
                        @if($writer['task'] !== null)
                            <div class="va-brief">@include('video-projects.partials.task-state', ['task' => $writer['task'], 'projectId' => $id])</div>
                        @endif
                        @if($writer['running'])
                            <div class="d"><span class="dot"></span>Đang viết prompt video…</div>
                            <div class="va-brief">@include('video-projects.partials.stream-state', ['stream' => $writer['stream']])</div>
                        @elseif($writer['pending'])
                            <div class="d">Đang chờ máy chạy nền nhận lượt viết.</div>
                        @elseif($writer['error'] !== null)
                            <div class="d" style="color:var(--vp-red)">{{ $writer['error'] }}</div>
                        @endif
                        @if($writer['unavailable'] !== null)
                            <div class="d" style="color:var(--vp-amber-fg)">Chưa viết được: {{ $writer['unavailable'] }}</div>
                        @endif
                        <form method="POST" action="{{ route('video-projects.clip-prompt-action', [$id, $shotId, 'prompt']) }}" id="{{ $promptForm }}" data-modal="confirm{{ $promptForm }}" onsubmit="return vpLockForm(this)" hidden>
                            @csrf
                            <input type="hidden" name="operation_id" value="{{ $writer['operation_id'] }}">
                            <input type="hidden" name="confirm_cost" value="1">
                            <input type="hidden" name="regenerate" value="{{ $shown !== null ? 1 : 0 }}">
                            <input type="hidden" name="expected_source_stage" value="{{ $panel['stage']['id'] }}">
                            <input type="hidden" name="expected_source_hash" value="{{ $panel['stage']['content_hash'] }}">
                        </form>
                        <div class="vk-actions">
                            <button type="button" class="vp-btn sm pri" data-toggle="modal" data-target="#confirm{{ $promptForm }}" data-busy="Đang gửi…"
                                    @if(! $writer['can_write']) disabled title="{{ $writer['unavailable'] ?? 'Đang có lượt viết chờ hoặc đang chạy' }}" @endif>{{ $shown !== null ? 'Viết lại prompt video' : 'Viết prompt video bằng AI' }}</button>
                        </div>
                        @if($writer['estimate'] !== null)
                            @include('modal.confirm_action', ['id' => 'confirm'.$promptForm, 'form' => $promptForm, 'content' => 'Gọi '.$writer['estimate']['model'].' (effort '.$writer['estimate']['effort'].') một lần để viết prompt video của '.$shotId.' cho thiết lập đang lưu — TÁC VỤ NÀY TÍNH TIỀN. Viết xong không tự duyệt và không render.', 'detail' => 'Dự toán '.$writer['estimate']['text'].'.'])
                        @endif
                    @endif
                </div>

                <div class="vs-c vc-clips">
                    @if($running !== null)
                        <div class="vc-block">
                            {!! $tag($running['technical']) !!} <span class="d">Lượt {{ $running['attempt_no'] }} · {{ $running['model_label'] }}</span>
                            <div class="d">{{ $running['technical']['note'] }}</div>
                            @if($running['technical']['state'] === 'running')
                                <div class="va-brief">@include('video-projects.partials.task-state', ['task' => ['kind' => 'clips', 'id' => $running['id'], 'status' => 'running', 'requested_by' => null, 'started_at' => null, 'queued_at' => null, 'retry_at' => null], 'projectId' => $id])</div>
                            @endif
                            @unless($running['local'])
                                <form method="POST" action="{{ route('video-projects.clip-prompt-action', [$id, $shotId, 'poll']) }}" onsubmit="return vpLockForm(this)">
                                    @csrf
                                    <input type="hidden" name="render_id" value="{{ $running['id'] }}">
                                    <button class="vp-btn sm" data-busy="Đang hỏi…">↻ Hỏi provider ngay</button>
                                </form>
                                <div class="d">Máy chạy nền hỏi provider mỗi phút; nút này chỉ đọc tiến độ, không gửi lại.</div>
                            @endunless
                        </div>
                    @endif

                    @if($inUse !== null)
                        <div class="vc-block vc-in-use" data-clip-review>
                            <div class="va-lbl">Clip đang dùng — bàn giao cho Phase 11</div>
                            {!! $tag($inUse['content']) !!} <span class="d">Lượt {{ $inUse['attempt_no'] }} · {{ $inUse['model_label'] }} · {{ $inUse['at'] }}</span>
                            @if($inUse['file_url'] !== null)
                                <video class="vc-player" controls preload="metadata" playsinline src="{{ $inUse['file_url'] }}" data-clip-player></video>
                            @endif
                            @include('video-projects.partials.moto-clip-facts', ['entry' => $inUse])
                            @if(($inUse['validity']['status'] ?? null) !== 'valid' || $inUse['file_problem'] !== null)
                                <div class="d" style="color:var(--vp-red)">Không còn bàn giao được: {{ implode(' · ', [...($inUse['validity']['labels'] ?? []), ...($inUse['file_problem'] !== null ? [\App\Services\Video\MotoClipSelectionService::REASONS[$inUse['file_problem']] ?? $inUse['file_problem']] : [])]) }}</div>
                            @endif
                            @if($inUse['strip_url'] !== null)
                                <details class="vk-edit" data-keep-open="strip-{{ $inUse['id'] }}">
                                    <summary class="vp-btn sm">Dải ảnh duyệt</summary>
                                    <div data-clip-strip="{{ $inUse['strip_url'] }}" data-lazy></div>
                                </details>
                            @endif
                            @if($inUse['pinned'])
                                <details class="vk-edit" data-keep-open="revoke-{{ $inUse['id'] }}">
                                    <summary class="vp-btn sm">Thu hồi</summary>
                                    <form method="POST" action="{{ route('video-projects.clip-prompt-action', [$id, $shotId, 'revoke']) }}" onsubmit="return vpLockForm(this)">
                                        @csrf
                                        <input type="hidden" name="render_id" value="{{ $inUse['id'] }}">
                                        <input type="hidden" name="expected_intent_version" value="{{ $row['intent_version'] }}">
                                        <input type="hidden" name="operation_id" value="{{ \Illuminate\Support\Str::uuid() }}">
                                        <input class="ctl" type="text" name="note" maxlength="{{ \App\Services\Video\MotoClipSelectionService::NOTE_MAX_CHARS }}" placeholder="Lý do (không bắt buộc)" data-keep="revoke-{{ $inUse['id'] }}">
                                        <div class="d">Thu hồi làm shot không còn clip bàn giao cho Phase 11; không tự chọn clip khác, không render lại.</div>
                                        <button class="vp-btn sm" data-busy="Đang thu hồi…">↩ Thu hồi clip đang dùng</button>
                                    </form>
                                </details>
                            @endif
                        </div>
                    @else
                        <div class="d">Shot chưa có clip đang dùng.</div>
                    @endif

                    @foreach($row['candidates'] as $candidate)
                        <div class="vc-block" data-clip-review>
                            <div class="va-lbl">Ứng viên chờ duyệt</div>
                            {!! $tag($candidate['technical']) !!} {!! $tag($candidate['content']) !!} <span class="d">Lượt {{ $candidate['attempt_no'] }} · {{ $candidate['model_label'] }} · {{ $candidate['at'] }}</span>
                            @if($candidate['file_url'] !== null)
                                <video class="vc-player" controls preload="metadata" playsinline src="{{ $candidate['file_url'] }}" data-clip-player></video>
                            @endif
                            @include('video-projects.partials.moto-clip-facts', ['entry' => $candidate])
                            @if(($candidate['validity']['status'] ?? null) !== 'valid')
                                <div class="d" style="color:var(--vp-red)">Không duyệt được: {{ implode(' · ', $candidate['validity']['labels'] ?? ['chưa kiểm được nguồn']) }}</div>
                            @endif
                            @if($candidate['strip_url'] !== null)
                                <div data-clip-strip="{{ $candidate['strip_url'] }}"><div class="d">Đang trích dải ảnh duyệt…</div></div>
                            @endif
                            <div class="va-lbl">Bấm duyệt là xác nhận cả bốn nhóm:</div>
                            <ol class="vc-groups">
                                @foreach($groups as $label)
                                    <li>{{ $label }}</li>
                                @endforeach
                            </ol>
                            <div class="vk-actions">
                                <form method="POST" action="{{ route('video-projects.clip-prompt-action', [$id, $shotId, 'approve']) }}" onsubmit="return vpLockForm(this)">
                                    @csrf
                                    <input type="hidden" name="render_id" value="{{ $candidate['id'] }}">
                                    <input type="hidden" name="expected_intent_version" value="{{ $row['intent_version'] }}">
                                    <input type="hidden" name="operation_id" value="{{ \Illuminate\Support\Str::uuid() }}">
                                    <input type="hidden" name="review_key" value="">
                                    <button class="vp-btn sm ok" data-busy="Đang duyệt…" @disabled(! $candidate['approvable'])>✓ Đã kiểm và duyệt clip</button>
                                </form>
                            </div>
                            @if($candidate['rejectable'])
                                <details class="vk-edit" data-keep-open="reject-{{ $candidate['id'] }}">
                                    <summary class="vp-btn sm">Không đạt</summary>
                                    <form method="POST" action="{{ route('video-projects.clip-prompt-action', [$id, $shotId, 'reject']) }}" onsubmit="return vpLockForm(this)">
                                        @csrf
                                        <input type="hidden" name="render_id" value="{{ $candidate['id'] }}">
                                        <input type="hidden" name="operation_id" value="{{ \Illuminate\Support\Str::uuid() }}">
                                        <input type="hidden" name="review_key" value="">
                                        <div class="va-lbl">Nhóm lỗi</div>
                                        <div class="vk-checks">
                                            @foreach($groups as $key => $label)
                                                <label><input type="checkbox" name="faults[]" value="{{ $key }}" data-keep="reject-{{ $candidate['id'] }}"> {{ $label }}</label>
                                            @endforeach
                                        </div>
                                        <input class="ctl" type="text" name="note" maxlength="{{ \App\Services\Video\MotoClipSelectionService::NOTE_MAX_CHARS }}" placeholder="Ghi chú (không bắt buộc)" data-keep="reject-{{ $candidate['id'] }}">
                                        <div class="vc-marks">
                                            <input class="ctl" type="text" name="marks" maxlength="200" placeholder="Mốc lỗi, ms (không bắt buộc), ví dụ 1300, 2500" data-keep="reject-{{ $candidate['id'] }}">
                                            <button type="button" class="vp-btn sm" data-mark-now>＋ mốc đang xem</button>
                                        </div>
                                        <div class="d">Ghi Không đạt cho lượt này — không render lại, clip đang dùng không đổi.</div>
                                        <button class="vp-btn sm" data-busy="Đang ghi…">Ghi Không đạt</button>
                                    </form>
                                </details>
                            @endif
                        </div>
                    @endforeach

                    @if($row['history'] !== [])
                        <details class="vk-edit" data-keep-open="history-{{ $shotId }}">
                            <summary class="vp-btn sm">Lịch sử {{ count($row['history']) }} lượt render</summary>
                            @foreach($row['history'] as $attempt)
                                <div class="vc-attempt">
                                    {!! $tag($attempt['technical']) !!} @if($attempt['content']['state'] !== 'none'){!! $tag($attempt['content']) !!}@endif
                                    <span class="d">Lượt {{ $attempt['attempt_no'] }} · {{ $attempt['model_label'] }} · {{ $attempt['at'] }}{{ $attempt['current_plan'] ? '' : ' · bản kế hoạch shot cũ, không duyệt được' }}</span>
                                    @include('video-projects.partials.moto-clip-facts', ['entry' => $attempt])
                                    @if($attempt['technical']['note'])
                                        <div class="d" style="color:{{ $attempt['technical']['state'] === 'failed' ? 'var(--vp-red)' : 'var(--vp-dim)' }}">{{ $attempt['technical']['note'] }}</div>
                                    @endif
                                    @if($attempt['decision'] !== null)
                                        <div class="d">Quyết định {{ $attempt['decision']['decision'] }} lúc {{ $attempt['decision']['at'] }}@if($attempt['decision']['faults'] !== []) · lỗi: {{ implode(', ', $attempt['decision']['faults']) }}@endif @if($attempt['decision']['marks_ms'] !== []) · mốc {{ implode(', ', $attempt['decision']['marks_ms']) }} ms @endif @if($attempt['decision']['note']) · “{{ $attempt['decision']['note'] }}”@endif</div>
                                    @endif
                                    @if($attempt['prompt'] !== '')
                                        <details class="d"><summary>Prompt đã gửi</summary>{{ $attempt['prompt'] }}</details>
                                    @endif
                                </div>
                            @endforeach
                        </details>
                    @endif
                </div>
            </div>
        </div>
    @endforeach
@unless($fragment)
</div>
@endunless
