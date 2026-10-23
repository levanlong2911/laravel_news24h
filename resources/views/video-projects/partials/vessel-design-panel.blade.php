            @if(($yacht ?? null) !== null)
            @php
                $yachtModel = (string) config('video.superyacht.design.model').' (effort '.config('video.superyacht.design.effort').')';
                $yachtCheck = $yacht['check'];
            @endphp
            <div class="vp-panel">
                <div class="va-head">
                    <span class="n">4</span>
                    <b>DESIGN</b>
                    <em>Phase 3 — hồ sơ thiết kế (draft)</em>
                    <span class="grow"></span>
                    @if($yacht['analysed'])<span class="va-tag blue">Đã thiết kế</span>@endif
                    @if($yacht['running'])
                        <button class="vp-btn sm" disabled>Đang thiết kế…</button>
                    @elseif($yacht['stuck'])
                        <form method="POST" action="{{ route('video-projects.vessel-design-reset', $project->id) }}">
                            @csrf
                            <button class="vp-btn sm dg">Reset lượt bị kẹt</button>
                        </form>
                    @elseif($yacht['can_run'] || $yacht['can_regenerate'])
                        <form method="POST" action="{{ route('video-projects.vessel-design', $project->id) }}"
                              id="yachtDesignForm" data-modal="confirmYachtDesign" onsubmit="return vpLockForm(this)">
                            @csrf
                            @if($yacht['can_regenerate'])<input type="hidden" name="force" value="1">@endif
                        </form>
                        <button type="button" class="vp-btn sm pri" data-toggle="modal" data-target="#confirmYachtDesign"
                                data-busy="Đang thiết kế…">{{ $yacht['can_regenerate'] ? 'Thiết kế lại (tính phí)' : 'Creat design' }}</button>
                        @include('modal.confirm_action', [
                            'id' => 'confirmYachtDesign',
                            'form' => 'yachtDesignForm',
                            'content' => 'Gọi '.$yachtModel.' viết hồ sơ thiết kế theo prompt Phase 3 từ Design DNA đã đạt — tác vụ này tính tiền.',
                        ])
                    @else
                        <button class="vp-btn sm" disabled title="{{ $yacht['run_error'] ?? 'Đã có bản thiết kế đạt kiểm RULE cho đúng DNA và prompt hiện tại' }}">Creat design</button>
                    @endif
                </div>
                <div class="va-body">
                    <div class="va-brief">
                        @if($yacht['prompt'])
                            <div class="line"><span class="k">Prompt khi chạy lại</span><span class="v">{{ $yacht['prompt'] }}</span></div>
                        @endif
                        @if($yacht['run_error'])
                            <div class="line"><span class="v" style="color:var(--vp-red)">{{ $yacht['run_error'] }}</span></div>
                        @endif
                        @if($yacht['error'])
                            <div class="line"><span class="v" style="color:var(--vp-red)">{{ $yacht['error'] }}</span></div>
                        @elseif(! $yacht['analysed'])
                            <div class="line"><span class="v">Chưa thiết kế</span></div>
                        @else
                            @if(! $yacht['current'])
                                <div class="line"><span class="v" style="color:var(--vp-red)">Bản thiết kế này thuộc DNA hoặc prompt cũ.</span></div>
                            @endif
                            @if($yachtCheck !== null)
                                @php
                                    $yachtWarnings = $yachtCheck['warnings'] ?? [];
                                @endphp
                                <div class="line"><span class="k">Kiểm tự động</span><span class="v src-verdict {{ $yachtCheck['verdict'] === 'PASS' && $yachtWarnings !== [] ? 'unscored' : strtolower($yachtCheck['verdict']) }}">
                                    @switch($yachtCheck['verdict'])
                                        @case('PASS') {{ $yachtWarnings !== [] ? 'Cần xác nhận — '.count($yachtWarnings).' cảnh báo' : 'Đạt kiểm tự động' }} @break
                                        @case('FAIL') Không đạt — {{ count($yachtCheck['issues']) }} lỗi @break
                                        @default Đã nhận kết quả, chưa kiểm được — {{ $yachtCheck['error'] }}
                                    @endswitch
                                </span></div>
                                @foreach($yachtCheck['issues'] as $issue)
                                    <div class="line"><span class="v" style="color:var(--vp-red)">{{ $issue }}</span></div>
                                @endforeach
                                @foreach($yachtWarnings as $warning)
                                    <div class="line"><span class="v" style="color:var(--vp-amber-fg)">{{ $warning }}</span></div>
                                @endforeach
                            @endif
                            @if($yacht['name'])
                                <div class="line"><span class="k">Tên tàu</span><span class="v">{{ $yacht['name'] }}@if($yacht['loa']) · LOA {{ $yacht['loa'] }} m @endif</span></div>
                            @endif
                            @if($yacht['yacht_anchor'])
                                <div class="line"><span class="k">YACHT ANCHOR</span><span class="v">{{ $yacht['yacht_anchor'] }}</span></div>
                            @endif
                            @if($yacht['prose'] !== null)
                                <pre class="raw">{{ $yacht['prose'] }}</pre>
                            @else
                                <div class="line"><span class="v">Phản hồi không phải JSON ({{ $yacht['json_error'] }})</span></div>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
            @else
            @php
                $design = $vesselDesign['design'] ?? null;
                $hasDesign = $design !== null;
                $designAnchor = $vesselDesign['anchor_selection'] ?? null;
                $designModel = (string) config('video.screenplay.design.model').' (effort '.config('video.screenplay.design.effort').')';
                $designMaxTokens = number_format((int) config('video.screenplay.design.max_tokens'), 0, ',', '.');
                $designMinutes = intdiv((int) config('video.screenplay.design.timeout_seconds'), 60);
                $failedDesign = $vesselDesign['failed_design'] ?? null;
                $designText = static function (array $source): string {
                    $lines = [(string) ($source['vessel']['name'] ?? ''), (string) ($source['vessel']['description'] ?? ''), '', 'APPEARANCE: '.($source['vessel']['appearance'] ?? ''), ''];

                    foreach ((array) ($source['design_thesis'] ?? []) as $field => $text) {
                        $lines[] = strtoupper(str_replace('_', ' ', (string) $field)).': '.(is_string($text) ? $text : json_encode($text, JSON_UNESCAPED_UNICODE));
                        $lines[] = '';
                    }

                    $lines[] = 'DIMENSION RATIONALE: '.($source['principal_dimensions']['rationale'] ?? '');
                    $lines[] = '';

                    if (\App\Video\Screenplay\VesselDesign::isV4($source)) {
                        try {
                            $lines = [...$lines, ...\App\Video\Screenplay\ProtagonistProfileText::describedLines($source, multiline: true)];
                        } catch (\Throwable) {
                            $lines[] = json_encode(array_intersect_key($source, array_flip(['figures', 'construction'])), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        }
                    } elseif (\App\Video\Screenplay\VesselDesign::isV3($source)) {
                        try {
                            $lines = [...$lines, ...\App\Video\Screenplay\ProtagonistProfileText::designLines($source, multiline: true)];
                        } catch (\Throwable) {
                            $lines[] = json_encode(array_intersect_key($source, array_flip(['figures', 'construction'])), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        }
                    } elseif (is_array($source['protagonist_profile'] ?? null)) {
                        try {
                            $lines[] = \App\Video\Screenplay\ProtagonistProfileText::render($source['protagonist_profile'], '');
                        } catch (\Throwable) {
                            $lines[] = json_encode($source['protagonist_profile'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        }
                    }

                    return implode("\n", $lines);
                };
            @endphp
            <div class="vp-panel">
                <div class="va-head">
                    <span class="n">4</span>
                    <b>DESIGN</b>
                    <span class="grow"></span>
                    @if($hasDesign)<span class="va-tag ok">Đã có thiết kế rev {{ $vesselDesign['revision'] }}</span>@endif
                    @if(! $brief['can_design'])
                        <button class="vp-btn sm" disabled title="{{ $brief['format'] !== 'phase1' ? 'Cần brief Haiku trước' : ($brief['source_passed'] ? 'Cần Design DNA đạt kiểm RULE, novelty và plausibility' : 'Bài viết chưa đạt đánh giá nguồn') }}">Creat design</button>
                    @elseif($vesselDesign['running'])
                        <button class="vp-btn sm" disabled>Đang thiết kế…</button>
                        <form method="POST" action="{{ route('video-projects.vessel-design-reset', $project->id) }}">
                            @csrf
                            <button class="vp-btn sm dg">Reset</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('video-projects.vessel-design', $project->id) }}"
                              id="vesselDesignForm" data-modal="confirmVesselDesign" onsubmit="return vpLockForm(this)">
                            @csrf
                            @if($hasDesign)<input type="hidden" name="force" value="1">@endif
                        </form>
                        <button type="button" class="vp-btn sm pri" data-toggle="modal" data-target="#confirmVesselDesign"
                                data-busy="Đang thiết kế…">{{ $hasDesign ? 'Thiết kế bản khác (tính phí)' : 'Thiết kế tàu' }}</button>
                        @include('modal.confirm_action', [
                            'id' => 'confirmVesselDesign',
                            'form' => 'vesselDesignForm',
                            'content' => $hasDesign
                                ? 'Gọi '.$designModel.' thiết kế một bản MỚI. Bản mới cần viết prompt, render và duyệt ảnh anchor lại; bản cũ và kịch bản đã có vẫn được giữ — tác vụ này tính tiền.'
                                : 'Gọi '.$designModel.' thiết kế con tàu: ý tưởng, kích thước, hình dáng, boong, không gian và hồ sơ chi tiết. Chưa viết kịch bản — tác vụ này tính tiền.',
                            'detail' => ($hasDesign ? 'Bỏ qua bản đã lưu và gọi model. ' : 'Cùng brief + cùng bộ luật thì dùng lại bản đã lưu, không gọi model. ')
                                .'Tối đa '.$designMaxTokens.' token đầu ra (tính cả phần suy nghĩ), chờ tối đa '.$designMinutes.' phút.'
                                .(($vesselDesign['repair_enabled'] ?? false) ? ' Nếu bản thiết kế chỉ sai trường serves của ô mở và nguồn chỉ ra đúng một phòng, hệ thống có thể gọi thêm MỘT lượt sửa nhỏ (tính phí).' : ''),
                        ])
                    @endif
                </div>
                <div class="va-body">
                    @if($vesselDesign['error'])
                        <div class="va-lbl" style="color:var(--vp-red);font-weight:400">
                            {{ $hasDesign ? 'Lượt thiết kế gần nhất lỗi (bản thành công rev '.$vesselDesign['revision'].' vẫn được giữ bên dưới):' : 'Lượt thiết kế gần nhất lỗi:' }}
                            @foreach(($vesselDesign['errors'] ?? [$vesselDesign['error']]) as $line)<br>{{ $line }}@endforeach
                        </div>
                        @php $failedRepair = $vesselDesign['failed_repair'] ?? null; @endphp
                        @if(is_array($failedRepair))
                            <div class="va-lbl" style="color:var(--vp-red);font-weight:400" id="vesselDesignFailedRepair">
                                Lượt sửa serves: {{ $failedRepair['result'] }}@if($failedRepair['reason'] ?? null) — {{ $failedRepair['reason'] }}@endif
                                @foreach(($failedRepair['changes'] ?? []) as $change)<br>{{ $change['opening_id'] }}: {{ $change['before'] }} → {{ $change['after'] }}@endforeach
                            </div>
                        @endif
                        @if($failedDesign !== null)
                            <div class="va-lbl" style="color:var(--vp-red);font-weight:400">
                                Nội dung AI đã tạo ở lượt lỗi{{ ($vesselDesign['failed_at'] ?? null) ? ' ('.$vesselDesign['failed_at'].')' : '' }}
                                &middot; {{ $failedDesign['principal_dimensions']['length_m'] ?? '—' }} m × {{ $failedDesign['principal_dimensions']['beam_m'] ?? '—' }} m
                                — chỉ để xem, chưa lưu thành bản thiết kế và không dùng được cho anchor:
                            </div>
                            <textarea class="va-ta" readonly id="vesselDesignFailedText">{{ $designText($failedDesign) }}</textarea>
                            @if(is_array($failedDesign['canonical_design'] ?? null))
                                <div class="va-lbl" style="color:var(--vp-red);font-weight:400">Hình học chuẩn (canonical_design) của lượt lỗi:</div>
                                <textarea class="va-ta" readonly id="vesselDesignFailedCanonical">{{ json_encode($failedDesign['canonical_design'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</textarea>
                            @endif
                        @endif
                    @endif
                    @if(! $hasDesign)
                        @if(! $vesselDesign['error'] && $failedDesign === null)
                            <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">Click design.</div>
                        @endif
                    @else
                        <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">
                            rev {{ $vesselDesign['revision'] }}
                            &middot; {{ $design['principal_dimensions']['length_m'] ?? '—' }} m × {{ $design['principal_dimensions']['beam_m'] ?? '—' }} m
                            &middot; {{ $vesselDesign['written_at'] ?? '—' }}
                            @if($designAnchor !== null)
                                &middot; <span style="color:var(--vp-green)">ảnh anchor đã duyệt cho bản thiết kế này</span>
                            @else
                                &middot; chưa duyệt ảnh anchor
                            @endif
                        </div>
                        @php $designRepair = $vesselDesign['repair'] ?? null; @endphp
                        @if(is_array($designRepair) && ($designRepair['result'] ?? null) === 'repaired')
                            <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)" id="vesselDesignRepair">
                                Bản này đã qua MỘT lượt sửa serves (kiểm tra lại đạt):
                                @foreach(($designRepair['changes'] ?? []) as $change)<br>{{ $change['opening_id'] }}: {{ $change['before'] }} → {{ $change['after'] }}@endforeach
                            </div>
                        @endif
                        @php
                            $designGate = $vesselDesign['gate'] ?? null;
                            $gatePassed = is_array($designGate) ? count(array_filter($designGate, static fn (array $found): bool => $found === [])) : 0;
                        @endphp
                        @if($designGate === null)
                            <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">Bản thiết kế này chưa có hình học chuẩn (canonical_design) — anchor dùng đường cũ.</div>
                        @else
                            <div class="va-lbl" style="font-weight:400;color:{{ $gatePassed === count($designGate) ? 'var(--vp-green)' : 'var(--vp-red)' }}">
                                Cổng hoàn chỉnh: {{ $gatePassed }}/{{ count($designGate) }}
                                @foreach($designGate as $check => $found)
                                    @if($found !== [])<br>{{ $check }}: {{ implode('; ', $found) }}@endif
                                @endforeach
                            </div>
                            @if(($vesselDesign['text_warnings'] ?? []) !== [])
                                <div class="va-lbl vd-reach warn" id="vesselDesignTextWarnings">
                                    Chữ cần kiểm (chỉ báo, không chặn bản đã có):
                                    @foreach($vesselDesign['text_warnings'] as $line)<br>{{ $line }}@endforeach
                                </div>
                            @endif
                            @php $v3Findings = $vesselDesign['v3_findings'] ?? null; @endphp
                            @if(is_array($v3Findings))
                                <div class="va-lbl vd-reach {{ $v3Findings['findings'] === [] ? 'ok' : 'warn' }}" id="vesselDesignReach">
                                    Tiếp cận (chỉ báo, không chặn):
                                    @foreach($v3Findings['configurations'] as $line)<br>{{ $line }}@endforeach
                                    @foreach($v3Findings['findings'] as $line)<br>{{ $line }}@endforeach
                                </div>
                            @endif
                            @if(($vesselDesign['p0'] ?? []) !== [])
                                <div class="va-lbl" style="font-weight:400">
                                    P0 phải giữ:
                                    @foreach($vesselDesign['p0'] as $row)<br>{{ $row['id'] }} — {{ $row['statement'] }}@endforeach
                                </div>
                            @endif
                        @endif
                        <textarea class="va-ta" readonly id="vesselDesignText">{{ $designText($design) }}</textarea>
                    @endif
                </div>
            </div>
            @endif
