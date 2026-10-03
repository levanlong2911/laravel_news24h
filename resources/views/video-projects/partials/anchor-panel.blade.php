    @php
        $promptModel = config('canonical_concept.'.config('canonical_concept.provider').'.model');
    @endphp

    <div class="vp-panel va-anchor">
        <div class="va-head">
            <span class="n">4</span>
            <b>ANCHOR PROMPT</b>
            <em>{{ ($designFirst ?? false)
                ? 'anchor từ bản thiết kế tàu — duyệt ảnh xong mới viết kịch bản'
                : ($anchorRows[0]['character'] === null ? 'anchor từ brief Haiku' : count($anchorRows).' nhân vật chính') }}</em>
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
                            @elseif(($designFirst ?? false) && $character === null)
                                <button class="vp-btn sm" disabled title="Cần bản thiết kế tàu trước">Creat Prompt</button>
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
                                        ? 'Gọi '.$promptModel.' viết prompt ảnh anchor cho nhân vật "'.$name.'" '
                                            .(($designFirst ?? false) ? 'từ bản thiết kế tàu rev '.($character['design_revision'] ?? '—') : 'từ bản phân cảnh').' — tác vụ này tính tiền.'
                                        : 'Gọi '.$promptModel.' viết prompt ảnh từ brief Haiku — tác vụ này tính tiền.',
                                    'detail' => $character !== null && $hasPrompt
                                        ? 'Bỏ qua prompt đã lưu của nhân vật này và gọi model viết bản mới.'
                                        : 'Cùng nội dung + cùng bộ luật thì dùng lại bản đã lưu, không gọi lại model.',
                                ])
                            @endif
                        </div>
                        <div class="va-lbl">PROMPT GỬI ĐI</div>
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
