@extends('layouts.base', ['title' => 'Annotate — ' . $render->fixture->name])
@section('title', 'Annotate: ' . $render->fixture->name)

@section('css')
<style>
/* ── Layout ─────────────────────────────────────────── */
.annotation-layout { display: grid; grid-template-columns: 420px 1fr; gap: 20px; align-items: start; }
@media (max-width: 1100px) { .annotation-layout { grid-template-columns: 1fr; } }

/* ── Video panel ─────────────────────────────────────── */
.video-panel { position: sticky; top: 70px; }
.video-player { width:100%; border-radius:8px; background:#000; }
.meta-badge   { font-size:11px; }

/* ── Instruction beats ──────────────────────────────── */
.beat-section   { margin-bottom: 18px; }
.beat-label     { display:inline-block; padding:3px 10px; border-radius:4px; font-size:11px;
                  font-weight:700; letter-spacing:.5px; text-transform:uppercase; margin-bottom:10px; }
.beat-hook       { background:#fff3cd; color:#856404; }
.beat-escalation { background:#cce5ff; color:#004085; }
.beat-reveal     { background:#d4edda; color:#155724; }
.beat-payoff     { background:#e2d9f3; color:#4a235a; }
.beat-resolution { background:#f8d7da; color:#721c24; }

.instruction-row {
    display: grid;
    grid-template-columns: 32px 1fr auto;
    gap: 8px;
    align-items: start;
    padding: 10px 12px;
    border-radius: 6px;
    margin-bottom: 6px;
    background: #fff;
    border: 1px solid #e9ecef;
    transition: border-color .15s;
}
.instruction-row:hover { border-color: #adb5bd; }
.instruction-row.observed-1 { border-left: 3px solid #28a745; }
.instruction-row.observed-0 { border-left: 3px solid #dc3545; opacity:.8; }

.inst-checkbox  { width:22px; height:22px; cursor:pointer; margin-top:2px; }
.inst-code      { font-size:12px; font-weight:700; color:#495057; font-family:monospace; }
.inst-planner   { font-size:10px; color:#adb5bd; }
.inst-text      { font-size:12px; color:#6c757d; margin-top:4px; max-height:40px; overflow:hidden;
                  cursor:pointer; transition:max-height .2s; line-height:1.4; }
.inst-text.expanded { max-height:200px; }
.conf-select    { font-size:11px; padding:2px 6px; border-radius:4px; border:1px solid #dee2e6;
                  background:#f8f9fa; color:#495057; }

/* ── Score sliders ──────────────────────────────────── */
.score-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 14px;
}
.score-item label  { font-size:11px; font-weight:600; color:#495057; margin-bottom:3px; display:block; }
.score-item input  { width:100%; }
.score-display     { font-size:13px; font-weight:700; color:#007bff; }
.score-item.highlight label { color: #007bff; }
.score-item.highlight input { accent-color: #007bff; }

/* ── Subject consistency ────────────────────────────── */
.consistency-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }

/* ── Nav footer ─────────────────────────────────────── */
.nav-footer { position:sticky; bottom:0; background:#fff; border-top:1px solid #dee2e6;
              padding:12px 20px; z-index:100; display:flex; gap:10px; align-items:center;
              box-shadow:0 -2px 8px rgba(0,0,0,.08); }
.nav-footer .progress-info { margin-left:auto; font-size:12px; color:#6c757d; }

/* ── Prompt text ─────────────────────────────────────── */
.prompt-box { font-family:monospace; font-size:11px; background:#f8f9fa; border:1px solid #dee2e6;
              border-radius:6px; padding:12px; white-space:pre-wrap; max-height:0;
              overflow:hidden; transition:max-height .3s; }
.prompt-box.open { max-height:500px; overflow-y:auto; }
</style>
@endsection

@section('content')
<div class="container-fluid py-3" x-data="annotationApp()">
<form id="annotation-form" method="POST" action="{{ route('benchmark.save', $render->uuid) }}">
@csrf

{{-- ── Top bar ────────────────────────────────────────────────────────── --}}
<div class="d-flex align-items-center mb-3">
    <div>
        <a href="{{ route('benchmark.renders', $render->session->code) }}" class="text-muted small">
            ← {{ $render->session->name }}
        </a>
        <h4 class="mb-0 font-weight-bold">{{ $render->fixture->name }}</h4>
        <div class="mt-1">
            <span class="badge badge-secondary meta-badge">{{ $render->model }}</span>
            <span class="badge badge-light meta-badge">{{ $render->resolution }}</span>
            <span class="badge badge-light meta-badge">{{ $render->duration_seconds }}s</span>
            <span class="badge badge-light meta-badge">seed {{ $render->seed ?? 'none' }}</span>
            <span class="badge badge-info meta-badge">{{ number_format($render->char_count) }} chars</span>
            @if($render->annotated_at)
                <span class="badge badge-success meta-badge">Annotated {{ $render->annotated_at->diffForHumans() }}</span>
            @endif
        </div>
    </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible py-2 mb-3">
    {{ session('success') }}
    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
</div>
@endif

{{-- ── Two-column layout ──────────────────────────────────────────────── --}}
<div class="annotation-layout">

    {{-- ═══ LEFT: Video + metadata ═══════════════════════════════════════ --}}
    <div class="video-panel">
        {{-- Video player --}}
        <div class="card card-default mb-3">
            <div class="card-body p-2">
                @if($videoUrl)
                    <video controls class="video-player" style="max-height:300px">
                        <source src="{{ $videoUrl }}" type="video/mp4">
                    </video>
                @else
                    <div class="d-flex align-items-center justify-content-center"
                         style="height:180px;background:#1a1a2e;border-radius:8px">
                        <div class="text-center text-white">
                            <i class="fas fa-video fa-2x mb-2 opacity-50"></i>
                            <div class="small opacity-75">Video not found</div>
                            <div class="small opacity-50 mt-1">{{ $render->artifact_path }}</div>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- Planner outputs --}}
        <div class="card card-default mb-3">
            <div class="card-header d-flex align-items-center py-2">
                <b class="small">Planner Outputs</b>
                <span class="badge badge-light ml-2 small">{{ $render->plannerOutputs->count() }} rows</span>
            </div>
            <div class="card-body p-0">
                @forelse($render->plannerOutputs->sortBy('beat') as $po)
                <div class="border-bottom px-3 py-2">
                    <div class="d-flex mb-1">
                        <span class="badge beat-{{ $po->beat }} mr-2">{{ $po->beat }}</span>
                        <small class="text-muted">{{ $po->planner->name ?? '?' }}</small>
                    </div>
                    <div style="font-size:11px;color:#495057;line-height:1.5">{{ $po->raw_text }}</div>
                </div>
                @empty
                <p class="text-muted small p-3 mb-0">No planner outputs recorded.</p>
                @endforelse
            </div>
        </div>

        {{-- Prompt text --}}
        <div class="card card-default mb-3">
            <div class="card-header py-2 d-flex align-items-center" style="cursor:pointer"
                 onclick="this.nextElementSibling.classList.toggle('open')">
                <b class="small">Full Prompt Text</b>
                <small class="text-muted ml-auto">{{ number_format($render->char_count) }} chars · click to expand</small>
            </div>
            <div class="prompt-box">{{ $render->artifact_path ? '(prompt stored at artifact_path)' : 'Prompt text not stored.' }}</div>
        </div>

        {{-- Render planners snapshot --}}
        <div class="card card-default">
            <div class="card-header py-2"><b class="small">Planner Fingerprints</b></div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0" style="font-size:11px">
                    <thead class="thead-light">
                        <tr><th>Planner</th><th>Version</th><th>SHA256</th></tr>
                    </thead>
                    <tbody>
                    @foreach($render->renderPlanners as $rp)
                    <tr>
                        <td>{{ $rp->planner->name ?? '?' }}</td>
                        <td><code>{{ $rp->planner_version ?? '—' }}</code></td>
                        <td><code class="text-muted">{{ $rp->fingerprint ? substr($rp->fingerprint, 0, 12) . '…' : '—' }}</code></td>
                    </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ═══ RIGHT: Instructions + Scores ══════════════════════════════════ --}}
    <div>

        {{-- ── Instructions by beat ──────────────────────────────────────── --}}
        <div class="card card-default mb-3">
            <div class="card-header d-flex align-items-center">
                <b>Instructions</b>
                <span class="badge badge-info ml-2">
                    {{ $render->instructionInstances->whereNotNull('observed')->count() }}
                    / {{ $render->instructionInstances->count() }} annotated
                </span>
                <button type="button" class="btn btn-sm btn-outline-secondary ml-auto"
                        onclick="markAll(1)" title="Mark all ✓">All ✓</button>
                <button type="button" class="btn btn-sm btn-outline-secondary ml-1"
                        onclick="markAll(0)" title="Mark all ✗">All ✗</button>
                <button type="button" class="btn btn-sm btn-outline-secondary ml-1"
                        onclick="markAll(null)" title="Clear all">Clear</button>
            </div>
            <div class="card-body">
                @foreach($byBeat as $beat => $instances)
                <div class="beat-section">
                    <div>
                        <span class="beat-label beat-{{ $beat }}">{{ strtoupper($beat) }}</span>
                        <span class="text-muted small ml-1">
                            {{ $instances->whereNotNull('observed')->count() }}/{{ $instances->count() }}
                        </span>
                    </div>

                    @foreach($instances as $inst)
                    @php
                        $obsClass = $inst->observed !== null
                            ? 'observed-' . $inst->observed
                            : '';
                    @endphp
                    <div class="instruction-row {{ $obsClass }}" id="row-{{ $inst->id }}">
                        {{-- Checkbox --}}
                        <div class="pt-1">
                            <input type="hidden"
                                   name="instructions[{{ $inst->id }}][id]"
                                   value="{{ $inst->id }}">
                            <input type="checkbox"
                                   class="inst-checkbox"
                                   data-inst-id="{{ $inst->id }}"
                                   onchange="handleCheck(this)"
                                   {{ $inst->observed == 1 ? 'checked' : '' }}>
                            <input type="hidden"
                                   name="instructions[{{ $inst->id }}][observed]"
                                   id="obs-{{ $inst->id }}"
                                   value="{{ $inst->observed ?? '' }}">
                        </div>

                        {{-- Label + text --}}
                        <div>
                            <div class="d-flex align-items-center">
                                <span class="inst-code">{{ $inst->catalog->code ?? '?' }}</span>
                                <span class="inst-planner ml-2">
                                    {{ $inst->catalog->planner->name ?? '' }}
                                </span>
                                <span class="ml-auto small text-muted">
                                    {{ $inst->char_length }}c / {{ $inst->estimated_token_cost }}t
                                </span>
                            </div>
                            <div class="inst-text"
                                 onclick="this.classList.toggle('expanded')">{{ $inst->variant_text }}</div>
                        </div>

                        {{-- Confidence --}}
                        <div class="pt-1">
                            <select class="conf-select"
                                    name="instructions[{{ $inst->id }}][confidence]">
                                <option value="">conf?</option>
                                <option value="high"   {{ $inst->confidence === 'high'   ? 'selected' : '' }}>High</option>
                                <option value="medium" {{ $inst->confidence === 'medium' ? 'selected' : '' }}>Med</option>
                                <option value="low"    {{ $inst->confidence === 'low'    ? 'selected' : '' }}>Low</option>
                            </select>
                        </div>
                    </div>
                    @endforeach
                </div>
                @endforeach
            </div>
        </div>

        {{-- ── Scores ──────────────────────────────────────────────────────── --}}
        <div class="card card-default mb-5">
            <div class="card-header"><b>Scores</b> <small class="text-muted">0–10</small></div>
            <div class="card-body">

                {{-- Subject Consistency sub-metrics --}}
                <h6 class="text-uppercase text-muted small font-weight-bold mb-2">Subject Consistency</h6>
                <div class="consistency-grid mb-4">
                    @foreach(['identity_consistency' => 'Identity', 'appearance_consistency' => 'Appearance',
                               'geometry_consistency' => 'Geometry', 'temporal_consistency' => 'Temporal'] as $field => $label)
                    <div class="score-item">
                        <label>{{ $label }} <span class="score-display" id="disp-{{ $field }}">{{ $render->score?->$field ?? '—' }}</span></label>
                        <input type="range" min="0" max="10" step="1"
                               name="scores[{{ $field }}]"
                               value="{{ $render->score?->$field ?? '' }}"
                               oninput="document.getElementById('disp-{{ $field }}').textContent = this.value"
                               class="w-100">
                    </div>
                    @endforeach
                </div>

                {{-- Camera --}}
                <h6 class="text-uppercase text-muted small font-weight-bold mb-2">Camera</h6>
                <div class="score-grid mb-4">
                    @foreach(['camera_obey' => 'Obey Instructions', 'camera_continuity' => 'Continuity'] as $field => $label)
                    <div class="score-item">
                        <label>{{ $label }} <span class="score-display" id="disp-{{ $field }}">{{ $render->score?->$field ?? '—' }}</span></label>
                        <input type="range" min="0" max="10" step="1"
                               name="scores[{{ $field }}]"
                               value="{{ $render->score?->$field ?? '' }}"
                               oninput="document.getElementById('disp-{{ $field }}').textContent = this.value"
                               class="w-100">
                    </div>
                    @endforeach
                </div>

                {{-- Scene quality --}}
                <h6 class="text-uppercase text-muted small font-weight-bold mb-2">Scene Quality</h6>
                <div class="score-grid mb-4">
                    @foreach([
                        'reveal_quality' => 'Reveal Quality',
                        'motion_realism' => 'Motion Realism',
                        'physics'        => 'Physics',
                        'emotion'        => 'Emotional Impact',
                        'cinematic_feel' => 'Cinematic Feel',
                        'eye_guidance'   => 'Eye Guidance',
                    ] as $field => $label)
                    <div class="score-item">
                        <label>{{ $label }} <span class="score-display" id="disp-{{ $field }}">{{ $render->score?->$field ?? '—' }}</span></label>
                        <input type="range" min="0" max="10" step="1"
                               name="scores[{{ $field }}]"
                               value="{{ $render->score?->$field ?? '' }}"
                               oninput="document.getElementById('disp-{{ $field }}').textContent = this.value"
                               class="w-100">
                    </div>
                    @endforeach
                </div>

                {{-- Overall --}}
                <div class="score-item highlight p-3 rounded" style="background:#e8f4fd; border:1px solid #b8daff">
                    <label class="font-weight-bold">
                        OVERALL
                        <span class="score-display" style="font-size:20px" id="disp-overall">
                            {{ $render->score?->overall ?? '—' }}
                        </span>
                        / 10
                    </label>
                    <input type="range" min="0" max="10" step="1"
                           name="scores[overall]"
                           value="{{ $render->score?->overall ?? '' }}"
                           oninput="document.getElementById('disp-overall').textContent = this.value"
                           style="height:8px; width:100%">
                </div>

            </div>
        </div>

    </div>{{-- /right column --}}
</div>{{-- /annotation-layout --}}

{{-- ── Sticky save footer ─────────────────────────────────────────────── --}}
<div class="nav-footer">
    @if($prev)
        <a href="{{ route('benchmark.annotate', $prev) }}" class="btn btn-outline-secondary btn-sm">
            ← Prev
        </a>
    @endif

    <button type="submit" class="btn btn-success btn-sm px-4">
        <i class="fas fa-save mr-1"></i> Save Annotation
    </button>

    @if($next)
        <a href="{{ route('benchmark.annotate', $next) }}" class="btn btn-outline-primary btn-sm">
            Next →
        </a>
    @endif

    @php
        $totalInst = $render->instructionInstances->count();
        $doneInst  = $render->instructionInstances->whereNotNull('observed')->count();
    @endphp
    <div class="progress-info">
        {{ $doneInst }}/{{ $totalInst }} instructions annotated
        @if($render->score?->overall !== null)
            · Overall: <b>{{ $render->score->overall }}/10</b>
        @endif
        @if($render->annotated_at)
            · <span class="text-success">✓ Complete</span>
        @endif
    </div>
</div>

</form>
</div>
@endsection

@section('script')
<script>
// ── Checkbox logic ────────────────────────────────────────────────────────
function handleCheck(el) {
    const id      = el.dataset.instId;
    const obsInput = document.getElementById('obs-' + id);
    const row      = document.getElementById('row-' + id);

    // Three-state: unchecked → 0, checked → 1, double-click clears
    if (el.checked) {
        obsInput.value = '1';
        row.className = row.className.replace(/observed-\d/, '') + ' observed-1';
    } else {
        obsInput.value = '0';
        row.className = row.className.replace(/observed-\d/, '') + ' observed-0';
    }
}

// Right-click on checkbox = clear (set to null/unset)
document.querySelectorAll('.inst-checkbox').forEach(function(el) {
    el.addEventListener('contextmenu', function(e) {
        e.preventDefault();
        const id       = el.dataset.instId;
        const obsInput = document.getElementById('obs-' + id);
        const row      = document.getElementById('row-' + id);
        el.checked     = false;
        obsInput.value = '';
        row.className  = row.className.replace(/\s*observed-\d/, '');
    });
});

function markAll(val) {
    document.querySelectorAll('.inst-checkbox').forEach(function(el) {
        const id       = el.dataset.instId;
        const obsInput = document.getElementById('obs-' + id);
        const row      = document.getElementById('row-' + id);
        if (val === null) {
            el.checked     = false;
            obsInput.value = '';
            row.className  = row.className.replace(/\s*observed-\d/, '');
        } else if (val === 1) {
            el.checked     = true;
            obsInput.value = '1';
            row.className  = row.className.replace(/observed-\d/, '') + ' observed-1';
        } else {
            el.checked     = false;
            obsInput.value = '0';
            row.className  = row.className.replace(/observed-\d/, '') + ' observed-0';
        }
    });
}

// ── Slider live display initialization ───────────────────────────────────
document.querySelectorAll('input[type=range]').forEach(function(slider) {
    if (slider.value !== '') {
        const dispId = 'disp-' + slider.name.replace('scores[','').replace(']','');
        const disp   = document.getElementById(dispId);
        if (disp) disp.textContent = slider.value;
    }
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Ctrl+S = save
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        document.getElementById('annotation-form').submit();
    }
});
</script>
@endsection
