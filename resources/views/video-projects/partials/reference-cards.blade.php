@php
    $cards = $rowCells
        ->flatMap(fn ($cell) => collect($cell['candidates'])->map(fn ($candidate) => ['cell' => $cell, 'candidate' => $candidate]))
        ->values();
    $pendingCells = $rowCells->where('candidates', []);
@endphp

@if($cards->isEmpty() && $pendingCells->isEmpty())
    <div class="ve-empty">{{ $empty }}</div>
@endif

@if($cards->isNotEmpty())
    <div class="va-cands">
        @foreach($cards as $card)
            @php
                $cell = $card['cell'];
                $candidate = $card['candidate'];
            @endphp
            <div class="va-cand">
                <img src="{{ $candidate['url'] }}" alt="{{ $cell['image_code'] }}"
                     width="{{ $candidate['width'] }}" height="{{ $candidate['height'] }}">
                <div class="cap">
                    <span>{{ $caption($cell) }}
                        &middot; {{ \App\Video\Reference\ReferenceEnvironment::tryFrom((string) $cell['environment'])?->label() ?? '—' }}</span>
                    <span class="act">
                        <b class="{{ $cell['status_tone'] }}">{{ $cell['status_label'] }}</b>

                        @if($cell['can_approve'])
                            @if($cell['selected_artifact_id'] === $candidate['id'])
                                <b class="ok">Đang dùng</b>
                            @else
                                <form method="POST" action="{{ route('video-projects.reference-approve', $id) }}">
                                    @csrf
                                    <input type="hidden" name="artifact_id" value="{{ $candidate['id'] }}">
                                    <button class="vp-btn ok sm" title="Duyệt" aria-label="Duyệt">✓</button>
                                </form>
                            @endif
                        @endif

                        @if($cell['selected_artifact_id'] !== $candidate['id'] && ! $cell['is_live'])
                            <form method="POST" action="{{ route('video-projects.reference-delete', $id) }}"
                                  onsubmit="return confirm('Xoá hẳn ảnh reference này và file của nó? Không khôi phục được.')">
                                @csrf
                                <input type="hidden" name="artifact_id" value="{{ $candidate['id'] }}">
                                <button class="vp-btn dg sm" title="Xoá" aria-label="Xoá">🗑️</button>
                            </form>
                        @endif
                    </span>
                </div>
                <div class="meta">
                    <span>{{ $cell['image_code'] }}</span>
                    <span>{{ $candidate['width'] }}×{{ $candidate['height'] }}</span>
                    <span>{{ $cell['variations'] }} ảnh &middot; {{ $cell['quality'] }}
                        @if($cell['cost_recorded_has_ledger'])
                            &middot; @include('video-projects.partials.cost-recorded', ['cell' => $cell])
                        @endif</span>
                    <span>Created: {{ $candidate['created_at']?->format('Y-m-d H:i:s') ?? '—' }}</span>
                </div>
            </div>
        @endforeach
    </div>
@endif

@foreach($pendingCells as $cell)
    <div class="va-cell" style="padding-left:0;padding-right:0">
        <span class="code">{{ $cell['image_code'] }}</span>
        <span class="va-tag {{ $cell['status_tone'] }}">{{ $cell['status_label'] }}</span>
        <span class="grow"></span>
        <span class="d">{{ $cell['variations'] }} ảnh &middot; {{ $cell['quality'] }} &middot; {{ $cell['size'] }}</span>
    </div>

    @if($cell['render_error'])
        <div class="alert alert-danger">{{ $cell['render_error'] }}</div>
    @endif

    @if($cell['is_live'])
        <div class="ve-locked" style="color:var(--vp-amber-fg)">Đang render — tải lại trang để xem tiến độ.</div>
    @elseif($cell['can_render'])
        <form method="POST" id="renderRef_{{ $cell['id'] }}"
              action="{{ route('video-projects.design-image-enqueue', [$id, $cell['id']]) }}"
              data-modal="confirmRenderRef_{{ $cell['id'] }}" onsubmit="return vpLockForm(this)">
            @csrf
        </form>
        <button type="button" class="vp-btn pri" data-toggle="modal"
                data-target="#confirmRenderRef_{{ $cell['id'] }}" data-busy="Đang render…">
            {{ $cell['has_failed'] ? 'Render lại →' : 'Render →' }}
        </button>
        @include('modal.confirm_action', [
            'id' => 'confirmRenderRef_'.$cell['id'],
            'form' => 'renderRef_'.$cell['id'],
            'content' => 'Gửi ô này cho gpt-image-2 render — TÁC VỤ NÀY TÍNH TIỀN.',
            'detail' => $cell['variations'].' ảnh · '.$cell['quality'].' · '.$cell['size']
                .' — ước lượng $'.number_format($cell['cost_estimate'], 3),
        ])
    @endif
@endforeach
