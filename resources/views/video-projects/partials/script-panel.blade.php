    @php
        $step = $scriptStep;
        $shown = $step['shown'];
        $doc = $shown['document']['data'] ?? null;
        $check = $shown['check'] ?? null;
        $brief = $step['brief'];
    @endphp

    <div class="vp-panel va-anchor" id="scriptPanel">
        <div class="va-head">
            <span class="n">6</span>
            <b>KỊCH BẢN PHIM</b>
            <em>Phase 4 — câu chuyện + kịch bản hình ảnh từ model sheet đã duyệt</em>
            <span class="grow"></span>
            @if($step['selection'] !== null && $step['selected_error'] === null)<span class="va-tag ok">Đang dùng: bản rev {{ collect($step['history'])->firstWhere('selected', true)['revision'] ?? '—' }}</span>@endif
            @if($step['running'])
                <button class="vp-btn sm" disabled>Đang viết…</button>
            @elseif($step['stuck'])
                <form method="POST" action="{{ route('video-projects.script-reset', $project->id) }}">
                    @csrf
                    <button class="vp-btn sm dg">Reset lượt bị kẹt</button>
                </form>
            @elseif($step['can_run'] || $step['can_rewrite'])
                <form method="POST" action="{{ route('video-projects.script', $project->id) }}"
                      id="scriptWriteForm" data-modal="confirmScriptWrite" onsubmit="return vpLockForm(this)">
                    @csrf
                    <input type="hidden" name="operation_id" value="{{ $step['operation_id'] }}">
                    @if($step['can_rewrite'])<input type="hidden" name="rewrite" value="1">@endif
                </form>
                <button type="button" class="vp-btn sm pri" data-toggle="modal" data-target="#confirmScriptWrite"
                        data-busy="Đang viết…">{{ $step['can_rewrite'] ? 'Viết lại (tính phí)' : 'Viết kịch bản' }}</button>
                @include('modal.confirm_action', [
                    'id' => 'confirmScriptWrite',
                    'form' => 'scriptWriteForm',
                    'content' => $step['can_rewrite']
                        ? 'Gọi '.$step['model'].' viết một bản kịch bản MỚI cho cùng nguồn và prompt. Bản đang dùng và các bản cũ được giữ nguyên — tác vụ này tính tiền.'
                        : 'Gọi '.$step['model'].' viết câu chuyện và kịch bản hình ảnh từ bản thiết kế mà model sheet đã duyệt tham chiếu — tác vụ này tính tiền.',
                    'detail' => 'Tối đa '.number_format((int) $step['max_tokens'], 0, ',', '.').' token đầu ra (tính cả phần suy nghĩ), chờ tối đa '.$step['timeout_minutes'].' phút; trả về dạng stream, tiến độ xem được khi tải lại trang ở tab khác.',
                ])
            @else
                <button class="vp-btn sm" disabled title="{{ $step['run_error'] }}">Viết kịch bản</button>
            @endif
        </div>

        <div class="va-body">
            <div class="va-brief">
                @if($step['run_error'] !== null)
                    <div class="line"><span class="k">Chưa viết được</span><span class="v" style="color:var(--vp-red)">{{ $step['run_error'] }}</span></div>
                @endif
                @if($brief !== null)
                    <div class="line"><span class="k">Brief</span><span class="v">{{ $brief['video_length'] }} s · {{ $brief['aspect_ratio'] }} · {{ $brief['platform'] }}@if(($brief['tone_notes'] ?? '') !== '') · {{ $brief['tone_notes'] }}@endif</span></div>
                @endif
                @if($step['prompt'] !== null)
                    <div class="line"><span class="k">Prompt</span><span class="v">{{ $step['prompt'] }} · {{ $step['model'] }}</span></div>
                    <div class="line"><span class="k">Lịch sử kênh</span><span class="v">Tắt — chưa có nguồn xác định kênh, không so với phim khác</span></div>
                @endif
                @include('video-projects.partials.stream-state', ['stream' => $step['stream']])
                @if($step['error'] !== null)
                    <div class="line"><span class="k">Lượt gần nhất lỗi</span><span class="v" style="color:var(--vp-red)">{{ $step['error'] }}</span></div>
                @endif
                @if($step['selected_error'] !== null)
                    <div class="line"><span class="k">Bản đang chọn</span><span class="v" style="color:var(--vp-red)">{{ $step['selected_error'] }}</span></div>
                @endif
                @if($step['brief_form'] !== null)
                    @php
                        $briefValues = $step['brief_form']['values'];
                    @endphp
                    <details>
                        <summary class="va-lbl">Brief {{ $step['brief_form']['custom'] ? 'theo dự án' : 'mặc định' }} — sửa</summary>
                        <form method="POST" action="{{ route('video-projects.script-brief', $project->id) }}" onsubmit="return vpLockForm(this)">
                            @csrf
                            @if($step['brief_form']['options']['video_length'][0] === $step['brief_form']['options']['video_length'][1])
                                <div class="line"><span class="k">Thời lượng</span><span class="v">{{ $step['brief_form']['options']['video_length'][0] }} s (phim final cố định)</span></div>
                            @else
                                <div class="line"><span class="k">Thời lượng (s)</span><span class="v"><input type="number" name="video_length" min="{{ $step['brief_form']['options']['video_length'][0] }}" max="{{ $step['brief_form']['options']['video_length'][1] }}" value="{{ $briefValues['video_length'] }}"></span></div>
                            @endif
                            <div class="line"><span class="k">Tỷ lệ khung</span><span class="v">
                                <select name="aspect_ratio">
                                    @foreach($step['brief_form']['options']['aspect_ratios'] as $ratio)
                                        <option value="{{ $ratio }}" @selected($ratio === $briefValues['aspect_ratio'])>{{ $ratio }}</option>
                                    @endforeach
                                </select>
                            </span></div>
                            <div class="line"><span class="k">Nền tảng</span><span class="v"><input type="text" name="platform" maxlength="{{ $step['brief_form']['options']['platform_max'] }}" value="{{ $briefValues['platform'] }}"></span></div>
                            <div class="line"><span class="k">Giọng điệu</span><span class="v"><input type="text" name="tone_notes" maxlength="{{ $step['brief_form']['options']['tone_notes_max'] }}" value="{{ $briefValues['tone_notes'] }}"></span></div>
                            <div class="va-foot"><button type="submit" class="vp-btn sm" data-busy="Đang lưu…">Lưu brief</button></div>
                        </form>
                    </details>
                @endif
                @if($step['history'] !== [])
                    <div class="line"><span class="k">Các bản</span><span class="v">
                        @foreach($step['history'] as $row)
                            <a href="{{ route('video-projects.anchor', ['id' => $project->id, 'script' => $row['id']]) }}#scriptPanel">rev {{ $row['revision'] }}</a>
                            ({{ $row['status'] === 'succeeded' ? ($row['verdict'] === 'PASS' ? 'đạt' : 'chưa đạt') : $row['status'] }}@if($row['model_status'] !== null && $row['model_status'] !== 'ok') · {{ $row['model_status'] }}@endif@if($row['selected']) · đang dùng@endif){{ $loop->last ? '' : ' ·' }}
                        @endforeach
                    </span></div>
                @endif
            </div>
        </div>

        @if($shown !== null)
            <div class="va-body" style="border-top:1px solid var(--vp-line)">
                <div class="va-lbl">
                    Bản rev {{ $shown['revision'] }}
                    @if($shown['selected'])<span class="va-tag ok">Đang dùng</span>@endif
                    @if($check !== null)
                        <span class="va-tag {{ $check['verdict'] === 'PASS' ? 'ok' : 'dg' }}">{{ $check['verdict'] === 'PASS' ? 'Đạt RULE' : ($check['verdict'] === 'FAIL' ? 'Chưa đạt RULE' : 'Chưa kiểm được') }}</span>
                        @if($check['status'] !== null && $check['status'] !== 'ok')<span class="va-tag amber">status {{ $check['status'] }}</span>@endif
                        <span class="va-tag mute">JUDGE: chưa chạy</span>
                    @endif
                    @if($shown['cost_usd'] !== null)<em>{{ $shown['tokens']['in'] }}/{{ $shown['tokens']['out'] }} token · ${{ number_format($shown['cost_usd'], 3) }}</em>@endif
                </div>

                @if($shown['integrity_error'] !== null)
                    <div class="va-lbl" style="font-weight:400;color:var(--vp-red)">Không dùng được bản này: {{ $shown['integrity_error'] }}</div>
                @endif

                @if($check !== null)
                    @if($check['error'] !== null)
                        <div class="va-lbl" style="font-weight:400;color:var(--vp-red)">{{ $check['error'] }}</div>
                    @endif
                    @foreach($check['schema'] as $line)
                        <div class="va-lbl" style="font-weight:400;color:var(--vp-red)">Cấu trúc: {{ $line }}</div>
                    @endforeach
                    @foreach($check['issues'] as $line)
                        <div class="va-lbl" style="font-weight:400;color:var(--vp-red)">RULE: {{ $line }}</div>
                    @endforeach
                    @foreach($check['warnings'] as $line)
                        <div class="va-lbl" style="font-weight:400;color:var(--vp-amber-fg)">WARN: {{ $line }}</div>
                    @endforeach
                @endif

                @if(is_array($doc))
                    @php
                        $story = $doc['story'];
                        $script = $doc['script'];
                    @endphp
                    <div class="va-brief">
                        <div class="line"><span class="k">Logline</span><span class="v">{{ $script['logline'] }}</span></div>
                        <div class="line"><span class="k">Trục phim</span><span class="v">{{ $story['spine'] }}</span></div>
                        <div class="line"><span class="k">Loại</span><span class="v">{{ $story['type'] }}</span></div>
                        @if($story['central_question'] !== null)
                            <div class="line"><span class="k">Câu hỏi trung tâm</span><span class="v">{{ $story['central_question'] }} — card “{{ $story['question_card'] }}”</span></div>
                            <div class="line"><span class="k">Cái giá</span><span class="v">{{ $story['stakes'] }}</span></div>
                        @endif
                        @if($story['countdown'] !== null)
                            <div class="line"><span class="k">Đếm ngược</span><span class="v">{{ $story['countdown'] }}</span></div>
                        @endif
                        <div class="line"><span class="k">Motif</span><span class="v">{{ $story['motifs']['visual'] }} · {{ $story['motifs']['sound'] }} · {{ $story['motifs']['first_last_rhyme'] }}</span></div>
                        @foreach($story['obstacles'] as $obstacle)
                            <div class="line"><span class="k">{{ $obstacle['id'] }} · {{ $obstacle['beat'] }}</span><span class="v">{{ $obstacle['problem'] }} → {{ $obstacle['sign'] }} → {{ $obstacle['action'] }} → {{ $obstacle['proof'] }}</span></div>
                        @endforeach
                        <div class="line"><span class="k">Loop</span><span class="v">{{ collect($story['loops'])->map(fn ($l) => $l['id'].': '.$l['plant'].' → '.$l['payoff'])->implode(' · ') }}</span></div>
                        @foreach($story['money_shots'] as $shot)
                            <div class="line"><span class="k">Money shot {{ $shot['beat'] }}</span><span class="v">{{ $shot['text'] }}{{ $shot['from_suggestions'] ? ' (từ gợi ý)' : '' }}</span></div>
                        @endforeach
                        @if(trim($story['notes']) !== '')
                            <div class="line"><span class="k">Ghi chú</span><span class="v">{{ $story['notes'] }}</span></div>
                        @endif
                        @foreach($doc['anchors']['character'] as $name => $anchor)
                            <div class="line"><span class="k">{{ $name }}</span><span class="v">{{ $anchor }}</span></div>
                        @endforeach
                    </div>

                    <div class="vs-script-scroll">
                        <table class="vr-needs">
                            <thead>
                                <tr><th>Beat</th><th>Act</th><th>s</th><th>BS · H · cảm xúc</th><th>Hình</th><th>Âm thanh</th><th>Địa điểm · thời điểm · camera</th><th>Phòng</th><th>Mối nối · điều chỉnh</th></tr>
                            </thead>
                            <tbody>
                                @foreach($script['beats'] as $beat)
                                    <tr>
                                        <td><b>{{ $beat['id'] }}</b>@if($beat['progress'])<br><span class="va-tag mute">PROGRESS</span>@endif</td>
                                        <td>{{ $beat['act'] }}</td>
                                        <td>{{ $beat['target_sec'] }}</td>
                                        <td>{{ $beat['bs'] }} · {{ $beat['reveal'] }} · {{ $beat['emotion'] }}</td>
                                        <td>{{ $beat['visual'] }}<br><em>{{ $beat['purpose'] }} — {{ $beat['action'] }}</em></td>
                                        <td>{{ $beat['sound'] }}</td>
                                        <td>{{ $beat['location'] }} · {{ $beat['time_light'] }} · {{ $beat['camera_family'] }}</td>
                                        <td>{{ collect($beat['rooms'])->map(fn ($r) => $r['id'].' '.$r['set_state'])->implode(', ') }}</td>
                                        <td>{{ $beat['link'] ?? '—' }}@if($beat['adaptation'] !== null)<br><span class="va-tag amber">Điều chỉnh</span> {{ $beat['adaptation'] }}@endif</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @php
                        $placeSeconds = collect($script['tour']['stops'])->groupBy('source_id')->map(fn ($stops) => $stops->sum('sec'));
                    @endphp
                    <div class="va-lbl">TOUR B14 <em>{{ collect($script['tour']['stops'])->sum('sec') }} s · {{ $placeSeconds->count() }} địa điểm</em></div>
                    <div class="vs-script-scroll">
                        <table class="vr-needs">
                            <thead><tr><th>Địa điểm</th><th>s</th><th>Tổng địa điểm</th><th>Hình</th><th>Góc bare</th></tr></thead>
                            <tbody>
                                @foreach($script['tour']['stops'] as $stop)
                                    <tr>
                                        <td><b>{{ $stop['source_id'] }}</b>@if(isset($shown['places'][$stop['source_id']]))<br><em>{{ $shown['places'][$stop['source_id']] }}</em>@endif</td>
                                        <td>{{ $stop['sec'] }}</td>
                                        <td>{{ $placeSeconds[$stop['source_id']] }}</td>
                                        <td>{{ $stop['visual'] }}</td>
                                        <td>{{ $stop['match_bare_beat'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="vs-script-scroll">
                        <table class="vr-needs">
                            <thead><tr><th>Card</th><th>Beat</th><th>Loại</th><th>s</th></tr></thead>
                            <tbody>
                                @foreach($script['text_cards'] as $card)
                                    <tr><td>{{ $card['text'] }}</td><td>{{ $card['beat'] }}</td><td>{{ $card['type'] }}</td><td>{{ $card['sec'] }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="va-brief">
                        <div class="line"><span class="k">Nhạc</span><span class="v">{{ $script['music_brief']['length'] }} s · {{ implode('–', $script['music_brief']['bpm']) }} BPM · {{ implode(' · ', $script['music_brief']['sections']) }}</span></div>
                        @foreach($script['sfx_plan'] as $sfx)
                            <div class="line"><span class="k">SFX {{ $sfx['beat'] }}</span><span class="v">{{ implode(', ', $sfx['sfx']) }}</span></div>
                        @endforeach
                    </div>
                @endif

                @if($shown['approvable'] && ! $shown['selected'])
                    <form method="POST" action="{{ route('video-projects.script-approve', $project->id) }}" onsubmit="return vpLockForm(this)">
                        @csrf
                        <input type="hidden" name="stage_id" value="{{ $shown['id'] }}">
                        <input type="hidden" name="content_hash" value="{{ $shown['content_hash'] }}">
                        <input type="hidden" name="selection_version" value="{{ $step['selection_version'] }}">
                        <input type="hidden" name="operation_id" value="{{ $step['operation_id'] }}">
                        <div class="va-foot">
                            <button type="submit" class="vp-btn sm pri" data-busy="Đang duyệt…">Duyệt và dùng bản này</button>
                        </div>
                    </form>
                @elseif($check !== null && ! $shown['approvable'])
                    <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">Bản này chỉ để xem: chỉ bản status ok và đạt RULE mới duyệt được.</div>
                @endif
            </div>
        @endif
    </div>
