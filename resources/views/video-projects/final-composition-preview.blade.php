@extends('layouts.base', ['title' => 'Final Composition'])
@section('title', 'Final Composition')

@section('css')
<link rel="stylesheet" href="{{ asset('assets/css/final-composition.css') }}?v={{ filemtime(public_path('assets/css/final-composition.css')) }}">
@endsection

@section('content')
@php
    $shots = [
        ['Factory', '00:18', 'https://images.unsplash.com/photo-1565793298595-6a879b1d9492?auto=format&fit=crop&w=180&q=80'],
        ['Frames', '00:15', 'https://images.unsplash.com/photo-1504917595217-d4dc5ebe6122?auto=format&fit=crop&w=180&q=80'],
        ['Hull Assembly', '00:20', 'https://images.unsplash.com/photo-1566847438217-76e82d383f84?auto=format&fit=crop&w=180&q=80'],
        ['Superstructure', '00:18', 'https://images.unsplash.com/photo-1544550285-f813152fb2fd?auto=format&fit=crop&w=180&q=80'],
        ['Launch', '00:15', 'https://images.unsplash.com/photo-1562281302-809108fd533c?auto=format&fit=crop&w=180&q=80'],
        ['Outfitting Dock', '00:22', 'https://images.unsplash.com/photo-1540946485063-a40da27545f8?auto=format&fit=crop&w=180&q=80'],
        ['Sea Trial', '00:28', 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?auto=format&fit=crop&w=180&q=80'],
        ['Operation', '00:18', 'https://images.unsplash.com/photo-1494783367193-149034c05e8f?auto=format&fit=crop&w=180&q=80'],
    ];
@endphp
<div class="container-fluid fc">
    <header class="fc-head">
        <div>
            <div class="fc-crumb"><a href="{{ route('video-projects.index') }}">Video Projects</a><span>/</span><a href="{{ route('video-projects.render-video', $id) }}">Clips</a><span>/</span><b>Final Composition</b></div>
            <h2><i class="fas fa-film"></i> Final Composition</h2>
            <p>Dựng các clip đã duyệt, thêm chuyển cảnh, nhạc và xuất video bằng FFmpeg.</p>
        </div>
        <div class="fc-head-actions">
            <a class="fc-button ghost" href="{{ route('video-projects.render-video', $id) }}"><i class="fas fa-arrow-left"></i> Quay lại Clips</a>
            <button class="fc-button primary" type="button"><i class="far fa-save"></i> Lưu bản nháp</button>
            <button class="fc-button success" type="button"><i class="fas fa-cog"></i> Render Final Video</button>
        </div>
    </header>

    <nav class="fc-steps" aria-label="Video workflow">
        @foreach(['Ảnh neo', 'Environment Library', 'Scenes', 'Clips', 'Final Composition', 'Exports'] as $step)
            <div class="{{ $step === 'Final Composition' ? 'active' : ($step === 'Clips' ? 'complete' : '') }}"><span>{{ $step === 'Clips' ? '✓' : $loop->iteration }}</span>{{ $step }}</div>
        @endforeach
    </nav>

    <main class="fc-grid">
        <aside class="fc-card fc-clips">
            <div class="fc-card-title">Danh sách clip đã duyệt <span>8 clips</span></div>
            @foreach($shots as $shot)
                <div class="fc-clip-item">
                    <i class="fas fa-grip-vertical"></i><img src="{{ $shot[2] }}" alt="{{ $shot[0] }}"><div><b>S{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }} · {{ $shot[0] }}</b><small>{{ $shot[1] }}</small></div><i class="fas fa-check-circle"></i>
                </div>
            @endforeach
            <div class="fc-note"><i class="fas fa-info-circle"></i> Chỉ hiển thị các clip đã duyệt. Kéo thả vào timeline để thêm.</div>
        </aside>

        <section class="fc-center">
            <div class="fc-card fc-preview">
                <div class="fc-card-title">Xem trước Final Video <span class="fc-draft">Draft preview</span></div>
                <div class="fc-player">
                    <img src="https://images.unsplash.com/photo-1566847438217-76e82d383f84?auto=format&fit=crop&w=1400&q=85" alt="Superyacht at sea">
                    <div class="fc-player-bar"><i class="fas fa-play"></i><b>00:28 / 02:15</b><span></span><i class="fas fa-volume-up"></i><em>16:9</em><i class="fas fa-expand"></i><i class="fas fa-download"></i></div>
                </div>
            </div>
            <div class="fc-card fc-timeline-panel">
                <div class="fc-card-title">Timeline</div>
                <div class="fc-tools"><button><i class="fas fa-plus"></i> Thêm clip</button><button><i class="fas fa-music"></i> Thêm nhạc</button><button><i class="fas fa-microphone"></i> Thêm thuyết minh</button><button><i class="fas fa-wave-square"></i> Thêm SFX</button><span></span><label>Zoom <input type="range" value="65"></label><button>Fit</button></div>
                <div class="fc-ruler"><span>00:00</span><span>00:30</span><span>01:00</span><span>01:30</span><span>02:00</span><span>02:15</span></div>
                <div class="fc-tracks">
                    <div class="fc-track-label"><i class="fas fa-film"></i> Video</div><div class="fc-track video-track">@foreach($shots as $shot)<div class="fc-shot" style="background-image:url('{{ $shot[2] }}')"><b>S{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</b><small>{{ $shot[1] }}</small></div>@endforeach)</div>
                    <div class="fc-track-label"><i class="fas fa-music"></i> BGM</div><div class="fc-track bgm">background_music.mp3</div>
                    <div class="fc-track-label"><i class="fas fa-microphone"></i> Voiceover</div><div class="fc-track voice"><span>narration.mp3</span><span>narration.mp3</span></div>
                    <div class="fc-track-label"><i class="fas fa-wave-square"></i> SFX</div><div class="fc-track sfx"><span>waves.mp3</span><span>waves.mp3</span></div>
                </div>
            </div>
            <div class="fc-card fc-history">
                <div class="fc-card-title">Lịch sử render</div><table><thead><tr><th>#</th><th>Thời gian</th><th>Tên file</th><th>Độ phân giải</th><th>Thời lượng</th><th>Trạng thái</th><th></th></tr></thead><tbody><tr><td>1</td><td>2026-09-07 07:31</td><td>superyacht_journey_final.mp4</td><td>1920 × 1080</td><td>02:15</td><td><span class="fc-status">Hoàn thành</span></td><td><button class="fc-download"><i class="fas fa-download"></i> Tải xuống</button></td></tr></tbody></table>
            </div>
        </section>

        <aside class="fc-right">
            <section class="fc-card fc-project"><div class="fc-card-title">Thông tin dự án</div><dl><dt>Project</dt><dd>{{ $id }}</dd><dt>Title</dt><dd>{{ $project->title ?? 'Superyacht Journey' }}</dd><dt>Duration (ước tính)</dt><dd>02:15</dd><dt>Số clip</dt><dd>8</dd><dt>Trạng thái</dt><dd><span class="fc-draft-light">Draft</span></dd></dl></section>
            <section class="fc-card fc-settings"><div class="fc-card-title">Thiết lập xuất video</div>
                @foreach([['Độ phân giải','1920 × 1080 (Full HD)'],['Tỉ lệ khung hình','16:9 (Landscape)'],['Frame rate (FPS)','24 fps'],['Video codec','H.264 (libx264)'],['Audio codec','AAC']] as $field)<label>{{ $field[0] }}<select><option>{{ $field[1] }}</option></select></label>@endforeach
                <label>Chất lượng (CRF)<div class="fc-crf"><input type="range" value="74"><b>18</b></div></label><small>Giá trị thấp hơn = chất lượng cao hơn (khuyến nghị: 16-20)</small>
                <details open><summary>Tùy chọn nâng cao <i class="fas fa-chevron-up"></i></summary><label><input checked type="checkbox"> Tự động cân chỉnh âm lượng</label><label><input checked type="checkbox"> Thêm chuyển cảnh mặc định (Crossfade 0.5s)</label><label><input type="checkbox"> Tối ưu hóa kích thước file</label><label><input type="checkbox"> Lưu file log FFmpeg</label><label><input type="checkbox"> Tạo thumbnail sau khi render</label></details>
                <button class="fc-render" type="button"><i class="fas fa-cog"></i> Render Final Video</button><p>Hệ thống sẽ dựng video bằng FFmpeg trên server. Thời gian xử lý tùy thuộc độ dài và chất lượng.</p>
            </section>
        </aside>
    </main>
</div>
@endsection
