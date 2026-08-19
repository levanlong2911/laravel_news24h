@php
    $rows = [
        ['REQUIRED', 'Environment', 'Môi trường', 'shipyard_hall_01', 'v1', 4, 4, true],
        ['REQUIRED', 'Subject Identity', 'Chủ thể', 'master_vessel', 'v1', 10, 4, true],
        ['REQUIRED', 'Previous State', 'Trạng thái trước', 'SC05_side_shell_assembly', 'v1', 1, 1, true],
        ['OPTIONAL', 'Construction Master', 'Cấu kiện khối', 'hull_blocks', 'v1', 6, 4, true],
        ['OPTIONAL', 'Equipment', 'Thiết bị', 'crane_slings_clamps', 'v1', 8, 4, false],
        ['OPTIONAL', 'Detail / Style', 'Chi tiết / Phong cách', 'industrial_lighting_style', 'v1', 6, 4, false],
    ];
@endphp

<div class="vp-panel">
    <div class="vp-sh">
        <h2>SCENE 06: SIDE SHELL ASSEMBLY</h2>
        <span class="vp-tag cur">In Progress</span>
        <span class="grow"></span>
        <span class="meta vp-mono">Scene ID: SC06</span>
    </div>

    <div class="vp-tabs">
        <button class="vp-tab on">REFERENCE SET (6 vai trò)</button>
        <button class="vp-tab">Payload Preview</button>
    </div>

    <table class="vp-rt">
        <thead>
            <tr>
                <th>Bắt buộc</th>
                <th>Vai trò (Role)</th>
                <th>Asset (Phiên bản)</th>
                <th>Ảnh đã chọn</th>
                <th>Trạng thái</th>
            </tr>
        </thead>
        <tbody>
        @foreach($rows as [$req, $role, $vn, $asset, $ver, $total, $slots, $chosen])
            <tr>
                <td><span class="vp-req {{ $req === 'REQUIRED' ? 'y' : 'n' }}">{{ $req }}</span></td>
                <td class="vp-role"><b>{{ $role }}</b><em>{{ $vn }}</em></td>
                <td>
                    <span class="vp-mono">{{ $asset }} ({{ $ver }})</span><br>
                    <em style="font-style:normal;font-size:.66rem;color:#94a3b8">{{ $total }} images</em>
                </td>
                <td>
                    <div class="vp-slots">
                        @for($i = 0; $i < $slots; $i++)<span class="vp-slot"></span>@endfor
                    </div>
                </td>
                <td>
                    @if($chosen)
                        <div style="color:#166534;font-size:.72rem;white-space:nowrap">✓ Đủ ảnh</div>
                        <button class="vp-btn sm" style="margin-top:4px">Thay đổi</button>
                    @else
                        <div style="color:#94a3b8;font-size:.72rem;white-space:nowrap">— Chưa chọn</div>
                        <button class="vp-btn sm pri" style="margin-top:4px">Chọn</button>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div style="padding:12px 16px">
        <div class="vp-warn">⚠ <b style="display:inline">Lưu ý:</b> Thiếu Environment sẽ bị chặn Generate Image.</div>
    </div>

    <div class="vp-foot">
        <a class="vp-btn" href="{{ route('video-session.scene', [$session->id, $scene['id'], 'prompt']) }}">← Quay lại</a>
        <button class="vp-btn">Lưu reference set</button>
        @if($step->next())
            <a class="vp-btn pri" href="{{ route('video-session.scene', [$session->id, $scene['id'], $step->next()->value]) }}">Tiếp tục: Preview Payload →</a>
        @endif
    </div>
</div>
