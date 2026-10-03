            @php
                $design = $vesselDesign['design'] ?? null;
                $hasDesign = $design !== null;
                $designLock = $vesselDesign['lock'] ?? null;
                $designModel = (string) config('video.screenplay.design.model').' (effort '.config('video.screenplay.design.effort').')';
                $designMaxTokens = number_format((int) config('video.screenplay.design.max_tokens'), 0, ',', '.');
                $designMinutes = intdiv((int) config('video.screenplay.design.timeout_seconds'), 60);
            @endphp
            <div class="vp-panel">
                <div class="va-head">
                    <span class="n">3</span>
                    <b>THIẾT KẾ TÀU</b>
                    <em>hình dáng, boong, không gian — trước kịch bản</em>
                    <span class="grow"></span>
                    @if($hasDesign)<span class="va-tag ok">Đã có thiết kế rev {{ $vesselDesign['revision'] }}</span>@endif
                    @if(! $brief['analysed'])
                        <button class="vp-btn sm" disabled title="Cần brief Haiku trước">Thiết kế tàu</button>
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
                                .'Tối đa '.$designMaxTokens.' token đầu ra (tính cả phần suy nghĩ), chờ tối đa '.$designMinutes.' phút.',
                        ])
                    @endif
                </div>
                <div class="va-body">
                    @if($vesselDesign['error'])
                        <div class="va-lbl" style="color:var(--vp-red);font-weight:400">
                            @if($hasDesign)Lượt gần nhất lỗi, đang hiển thị bản thành công rev {{ $vesselDesign['revision'] }}: @endif{{ $vesselDesign['error'] }}
                        </div>
                    @endif
                    @if(! $hasDesign)
                        @if(! $vesselDesign['error'])
                            <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">Chưa thiết kế tàu.</div>
                        @endif
                    @else
                        <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">
                            rev {{ $vesselDesign['revision'] }}
                            &middot; {{ $design['principal_dimensions']['length_m'] ?? '—' }} m × {{ $design['principal_dimensions']['beam_m'] ?? '—' }} m
                            &middot; {{ $vesselDesign['written_at'] ?? '—' }}
                            @if($designLock !== null && ($designLock['design_stage_id'] ?? null) === $vesselDesign['stage_id'])
                                &middot; <span style="color:var(--vp-green)">ảnh anchor đã duyệt và khoá nguồn</span>
                            @elseif($designLock !== null)
                                &middot; <span style="color:var(--vp-red)">ảnh đã khoá thuộc thiết kế rev {{ $designLock['design_revision'] ?? '—' }}, không phải bản này</span>
                            @else
                                &middot; chưa duyệt ảnh anchor
                            @endif
                        </div>
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
                            @if(($vesselDesign['p0'] ?? []) !== [])
                                <div class="va-lbl" style="font-weight:400">
                                    P0 phải giữ:
                                    @foreach($vesselDesign['p0'] as $row)<br>{{ $row['id'] }} — {{ $row['statement'] }}@endforeach
                                </div>
                            @endif
                        @endif
                        <textarea class="va-ta" readonly>{{ $design['vessel']['name'] ?? '' }}
{{ $design['vessel']['description'] ?? '' }}

Ngoại hình: {{ $design['vessel']['appearance'] ?? '' }}

@foreach(($design['design_thesis'] ?? []) as $field => $text){{ strtoupper(str_replace('_', ' ', $field)) }}: {{ $text }}

@endforeach
KÍCH THƯỚC: {{ $design['principal_dimensions']['rationale'] ?? '' }}

@if(is_array($design['protagonist_profile'] ?? null)){{ \App\Video\Screenplay\ProtagonistProfileText::render($design['protagonist_profile'], '') }}@endif</textarea>
                    @endif
                </div>
            </div>
