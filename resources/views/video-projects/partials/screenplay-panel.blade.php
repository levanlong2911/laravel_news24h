            @php
                $storyFirst = (bool) ($designFirst ?? false);
                $storyStep = $storyFirst ? 'story' : 'foundation';
            @endphp
            <div class="vp-panel">
                <div class="va-head">
                    <span class="n">{{ $storyFirst ? 5 : 3 }}</span>
                    <b>KỊCH BẢN PHIM</b>
                    <em>{{ $storyFirst ? 'câu chuyện quanh bản thiết kế đã duyệt — chưa phân cảnh' : 'nội dung — chưa phân cảnh' }}</em>
                    <span class="grow"></span>
                    @if($screenplayFoundation['foundation'] !== null)<span class="va-tag ok">Đã có nội dung</span>@endif
                    @if(! $brief['analysed'])
                        <button class="vp-btn sm" disabled title="Cần brief Haiku trước">Creat screen play</button>
                    @elseif($storyFirst && ($vesselDesign['lock'] ?? null) === null)
                        <button class="vp-btn sm" disabled title="Cần duyệt ảnh anchor của bản thiết kế tàu trước">Viết nội dung kịch bản</button>
                    @elseif($screenplayFoundation['running'])
                        <button class="vp-btn sm" disabled>Đang viết…</button>
                        <form method="POST" action="{{ route('video-projects.screenplay-foundation-reset', $project->id) }}">
                            @csrf
                            <button class="vp-btn sm dg">Reset</button>
                        </form>
                    @else
                        @php
                            $hasFoundation = $screenplayFoundation['foundation'] !== null;
                            $screenplayModel = (string) config('video.screenplay.'.$storyStep.'.model').' (effort '.config('video.screenplay.'.$storyStep.'.effort').')';
                            $foundationMaxTokens = number_format((int) config('video.screenplay.'.$storyStep.'.max_tokens'), 0, ',', '.');
                            $foundationMinutes = intdiv((int) config('video.screenplay.'.$storyStep.'.timeout_seconds'), 60);
                        @endphp
                        <form method="POST" action="{{ route('video-projects.screenplay-foundation', $project->id) }}"
                              id="screenplayFoundationForm" data-modal="confirmScreenplayFoundation" onsubmit="return vpLockForm(this)">
                            @csrf
                            @if($hasFoundation)<input type="hidden" name="force" value="1">@endif
                        </form>
                        <button type="button" class="vp-btn sm pri" data-toggle="modal" data-target="#confirmScreenplayFoundation"
                                data-busy="Đang viết…">{{ $hasFoundation ? 'Tạo bản khác (tính phí)' : 'Viết nội dung kịch bản' }}</button>
                        @include('modal.confirm_action', [
                            'id' => 'confirmScreenplayFoundation',
                            'form' => 'screenplayFoundationForm',
                            'content' => $hasFoundation
                                ? 'Gọi '.$screenplayModel.' viết một bản nội dung MỚI, dù brief không đổi. Bản đang có vẫn được giữ trong lịch sử — tác vụ này tính tiền.'
                                : ($storyFirst
                                    ? 'Gọi '.$screenplayModel.' viết câu chuyện quanh bản thiết kế tàu rev '.($vesselDesign['lock']['design_revision'] ?? '—').' đã duyệt ảnh: tiền đề, tóm tắt, diễn biến qua năm giai đoạn và kết thúc. Thiết kế giữ nguyên, nhân vật tàu tự đóng gói từ bản thiết kế. Bước này chưa tạo scene — tác vụ này tính tiền.'
                                    : 'Gọi '.$screenplayModel.' viết nội dung kịch bản: ý tưởng thiết kế, tiền đề, tóm tắt, diễn biến qua năm giai đoạn và kết thúc. Bước này chưa tạo scene — tác vụ này tính tiền.'),
                            'detail' => ($hasFoundation
                                    ? 'Bỏ qua bản đã lưu và gọi model. '
                                    : 'Cùng brief + cùng bộ luật thì dùng lại bản đã lưu, không gọi model. ')
                                .'Tối đa '.$foundationMaxTokens.' token đầu ra (tính cả phần suy nghĩ), chờ tối đa '.$foundationMinutes.' phút.',
                        ])
                    @endif
                </div>
                <div class="va-body">
                    @if($screenplayFoundation['error'])
                        <div class="va-lbl" style="color:var(--vp-red);font-weight:400">
                            @if($screenplayFoundation['foundation'] !== null)Lượt gần nhất lỗi, đang hiển thị bản thành công rev {{ $screenplayFoundation['revision'] }}: @endif{{ $screenplayFoundation['error'] }}
                        </div>
                    @endif
                    @php
                        $builtOn = $screenplayFoundation['foundation'][\App\Video\Screenplay\VesselDesign::SOURCE_ANCHOR_KEY] ?? null;
                        $lockedNow = $vesselDesign['lock'] ?? null;
                    @endphp
                    @if($storyFirst && is_array($builtOn) && is_array($lockedNow) && ($builtOn['artifact_id'] ?? null) !== ($lockedNow['artifact_id'] ?? null))
                        <div class="alert alert-warning">
                            Bản nội dung này viết trên một ảnh anchor / bản thiết kế khác nguồn đang khoá (thiết kế rev {{ $lockedNow['design_revision'] ?? '—' }}). Viết lại nội dung kịch bản để theo nguồn mới; các bước sau vẫn giữ nguồn cũ của chúng.
                        </div>
                    @endif
                    @if($screenplayFoundation['foundation'] === null)
                        @if(! $screenplayFoundation['error'])
                            <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">Chưa viết nội dung kịch bản.</div>
                        @endif
                    @else
                        <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">
                            <b>{{ $screenplayFoundation['foundation']['schema_version'] ?? '—' }}</b>
                            &middot; {{ count($screenplayFoundation['foundation']['stage_treatments'] ?? []) }} giai đoạn
                            &middot; chưa phân cảnh
                            &middot; {{ $screenplayFoundation['written_at'] ?? '—' }}
                            &middot; {{ $screenplayFoundation['foundation_brief_revision'] !== null ? 'yêu cầu nội dung rev '.$screenplayFoundation['foundation_brief_revision'] : 'không có yêu cầu nội dung' }}
                            @if($screenplayFoundation['profile_brief_revision'] !== $screenplayFoundation['foundation_brief_revision'])
                                <span style="color:var(--vp-red)">&middot; profile hiện ở yêu cầu {{ $screenplayFoundation['profile_brief_revision'] !== null ? 'rev '.$screenplayFoundation['profile_brief_revision'] : 'không có' }}, nội dung này chưa theo bản đó</span>
                            @endif
                        </div>
                        @if($screenplayFoundation['profile_source'] === 'legacy')
                            <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">
                                Bản nội dung này tạo trước khi profile kịch bản được lưu kèm. Phần profile đã gửi cho bước nội dung vẫn khớp profile hiện hành; các quy tắc không được lưu lại (coverage, quy tắc nhân vật, giới hạn địa điểm) sẽ lấy theo profile hiện hành ở các bước sau, và không có yêu cầu nội dung.
                            </div>
                        @elseif(in_array($screenplayFoundation['profile_source'], ['screenplay_profile_changed', 'screenplay_profile_unknown'], true))
                            <div class="va-lbl" style="font-weight:400;color:var(--vp-red)">
                                {{ $screenplayFoundation['profile_source'] === 'screenplay_profile_changed'
                                    ? 'Profile kịch bản đã đổi so với lúc viết bản nội dung này — các bước sau bị chặn, tạo lại nội dung kịch bản.'
                                    : 'Không xác định được profile kịch bản bản nội dung này đã dùng — các bước sau bị chặn, tạo lại nội dung kịch bản.' }}
                            </div>
                        @endif
                        <textarea class="va-ta" readonly>{{ \App\Video\Screenplay\ScreenplayFoundationText::render($screenplayFoundation['foundation']) }}</textarea>
                    @endif
                </div>

                @php
                    $castSteps = [
                        ['part' => 'characters', 'title' => 'NHÂN VẬT', 'noun' => 'nhân vật', 'state' => $screenplayCharacters,
                            'source' => $storyFirst
                                ? 'đóng gói tự động từ bản thiết kế tàu, không gọi model'
                                : 'tạo từ nội dung kịch bản, phân cảnh chỉ dùng lại'],
                        ['part' => 'locations', 'title' => 'ĐỊA ĐIỂM', 'noun' => 'địa điểm', 'state' => $screenplayLocations,
                            'source' => 'tạo từ nội dung kịch bản và nhân vật phía trên, phân cảnh chỉ dùng lại'],
                    ];
                @endphp
                @foreach($castSteps as $step)
                    @php
                        $castState = $step['state'];
                        $castForm = 'screenplay'.ucfirst($step['part']).'Form';
                        $castModal = 'confirmScreenplay'.ucfirst($step['part']);
                        $hasCast = $castState['rows'] !== null;
                        $castMaxTokens = number_format((int) config('video.screenplay.'.$step['part'].'.max_tokens'), 0, ',', '.');
                        $castMinutes = intdiv((int) config('video.screenplay.'.$step['part'].'.timeout_seconds'), 60);
                    @endphp
                    <div class="va-head" style="border-top:1px solid var(--vp-line)">
                        <b>{{ $step['title'] }}</b>
                        <em>{{ $step['source'] }}</em>
                        <span class="grow"></span>
                        @if($hasCast)<span class="va-tag ok">Đã có {{ $step['noun'] }}</span>@endif
                        @if($castState['running'])
                            <button class="vp-btn sm" disabled>Đang tạo {{ $step['noun'] }}…</button>
                            <form method="POST" action="{{ route('video-projects.screenplay-'.$step['part'].'-reset', $project->id) }}">
                                @csrf
                                <button class="vp-btn sm dg">Reset lượt bị kẹt</button>
                            </form>
                        @elseif($storyFirst && $step['part'] === 'characters')
                            <span class="va-tag">Tự động từ bản thiết kế</span>
                        @elseif(! $screenplayFoundation['selectable'])
                            <button class="vp-btn sm" disabled title="Cần nội dung kịch bản bản mới (screenplay_foundation_v2) trước">Tạo {{ $step['noun'] }}</button>
                        @elseif($step['part'] === 'locations' && ! $screenplayCharacters['usable'])
                            <button class="vp-btn sm" disabled title="Cần danh sách nhân vật dùng được (đúng bản nội dung, đúng bộ luật hiện hành) trước">Tạo {{ $step['noun'] }}</button>
                        @else
                            <form method="POST" action="{{ route('video-projects.screenplay-'.$step['part'], $project->id) }}"
                                  id="{{ $castForm }}" data-modal="{{ $castModal }}" onsubmit="return vpLockForm(this)">
                                @csrf
                                <input type="hidden" name="foundation_stage_id" value="{{ $screenplayFoundation['stage_id'] }}">
                                @if($step['part'] === 'locations')
                                    <input type="hidden" name="characters_stage_id" value="{{ $screenplayCharacters['stage_id'] }}">
                                @endif
                                @if($hasCast)<input type="hidden" name="force" value="1">@endif
                            </form>
                            <button type="button" class="vp-btn sm pri" data-toggle="modal" data-target="#{{ $castModal }}"
                                    data-busy="Đang tạo {{ $step['noun'] }}…">{{ $hasCast ? 'Tạo lại '.$step['noun'] : 'Tạo '.$step['noun'] }}</button>
                            @include('modal.confirm_action', [
                                'id' => $castModal,
                                'form' => $castForm,
                                'content' => 'Gọi '.config('video.screenplay.'.$step['part'].'.model').' (effort '.config('video.screenplay.'.$step['part'].'.effort').') tạo danh sách '.$step['noun'].' cho nội dung kịch bản rev '.$screenplayFoundation['revision']
                                    .($step['part'] === 'locations' ? ' và nhân vật rev '.$screenplayCharacters['revision'] : '')
                                    .' phía trên. Bước phân cảnh chỉ dùng lại danh sách này, không tự thêm — tác vụ này tính tiền.',
                                'detail' => ($hasCast ? 'Bỏ qua danh sách đã lưu và gọi model. ' : 'Cùng nội dung + cùng bộ luật thì dùng lại bản đã lưu, không gọi model. ')
                                    .'Tối đa '.$castMaxTokens.' token đầu ra, chờ tối đa '.$castMinutes.' phút.',
                            ])
                        @endif
                    </div>
                    <div class="va-body">
                        @if($castState['error'])
                            <div class="va-lbl" style="color:var(--vp-red);font-weight:400">Lượt tạo {{ $step['noun'] }} gần nhất lỗi: {{ $castState['error'] }}</div>
                        @endif
                        @if(! $hasCast)
                            @if(! $castState['error'])
                                <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">Chưa tạo {{ $step['noun'] }}.</div>
                            @endif
                        @else
                            @if(! $castState['usable'])
                                <div class="alert alert-warning">
                                    @if($castState['problem'] === 'rules')
                                        Danh sách {{ $step['noun'] }} này tạo theo bộ luật cũ, không khớp hồ sơ nhân vật chính hiện hành — tạo lại {{ $step['noun'] }}.
                                    @elseif($castState['problem'] === 'characters')
                                        Danh sách {{ $step['noun'] }} này tạo từ nhân vật rev {{ $castState['characters_revision'] ?? '—' }}, không phải danh sách nhân vật đang dùng phía trên — tạo lại {{ $step['noun'] }}.
                                    @elseif($castState['problem'] === 'profile_changed')
                                        Nội dung kịch bản đang chọn được viết theo một profile kịch bản khác profile hiện hành (hoặc không còn xác định được profile đã dùng) — tạo lại nội dung kịch bản trước, rồi mới tạo {{ $step['noun'] }}.
                                    @elseif($castState['problem'] === 'profile')
                                        Danh sách {{ $step['noun'] }} này tạo theo mẫu cũ (chỉ có tên và mô tả), thiếu bố cục, lối nối, thiết bị cố định và nguồn sáng — tạo lại {{ $step['noun'] }}.
                                    @else
                                        Danh sách {{ $step['noun'] }} này tạo từ nội dung rev {{ $castState['foundation_revision'] }}, không phải bản nội dung đang hiển thị phía trên.
                                    @endif
                                </div>
                            @endif
                            <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">
                                rev {{ $castState['revision'] }}
                                &middot; {{ count($castState['rows']) }} {{ $step['noun'] }}
                                &middot; {{ $castState['written_at'] ?? '—' }}
                            </div>
                            <textarea class="va-ta" readonly>@foreach($castState['rows'] as $row){{ $row['id'] ?? '?' }} · {{ $row['name'] ?? '' }}@if(isset($row['kind'])) · {{ $row['kind'] }} / {{ $row['role'] ?? '' }}@endif

@if($step['part'] === 'locations')
{{ implode("\n", \App\Video\Screenplay\LocationProfile::lines($row, $castState['rows'], '    ')) }}
@else
    {{ $row['description'] ?? '' }}
@endif
@if(filled($row['appearance'] ?? null))
    Ngoại hình: {{ $row['appearance'] }}
@endif
@if(is_array($row['profile'] ?? null))

{{ \App\Video\Screenplay\ProtagonistProfileText::render($row['profile'], '    ') }}
@endif

@endforeach</textarea>
                        @endif
                    </div>
                @endforeach

                <div class="va-head" style="border-top:1px solid var(--vp-line)">
                    <b>PHÂN CẢNH ĐẦY ĐỦ</b>
                    <em>scene, coverage, build state</em>
                    <span class="grow"></span>
                    @if($screenplay['screenplay'] !== null)
                        <span class="va-tag ok">Đã có phân cảnh</span>
                        @if($screenplay['approved'])
                            <span class="va-tag ok">Đã duyệt</span>
                            @if($screenplay['selected'])
                                <span class="va-tag ok">Đang dùng cho production</span>
                            @else
                                <form method="POST" action="{{ route('video-projects.screenplay-select', $project->id) }}">
                                    @csrf
                                    <input type="hidden" name="stage_id" value="{{ $screenplay['stage_id'] }}">
                                    <input type="hidden" name="expected_selection_version" value="{{ $screenplay['selection_version'] }}">
                                    <button class="vp-btn sm pri" type="submit">Chọn cho production</button>
                                </form>
                            @endif
                        @else
                            <form method="POST" action="{{ route('video-projects.screenplay-approve', $project->id) }}">
                                @csrf
                                <input type="hidden" name="stage_id" value="{{ $screenplay['stage_id'] }}">
                                <input type="hidden" name="operation_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                <button class="vp-btn sm" type="submit">Duyệt phân cảnh rev {{ $screenplay['revision'] }}</button>
                            </form>
                        @endif
                    @endif
                    @if($screenplay['running'])
                        <button class="vp-btn sm" disabled>Đang tạo phân cảnh…</button>
                        <form method="POST" action="{{ route('video-projects.screenplay-reset', $project->id) }}">
                            @csrf
                            <button class="vp-btn sm dg">Reset lượt bị kẹt</button>
                        </form>
                    @elseif(! $screenplayFoundation['selectable'])
                        <button class="vp-btn sm" disabled title="Cần nội dung kịch bản bản mới (screenplay_foundation_v2) trước">Tạo phân cảnh</button>
                    @elseif(! $screenplayCharacters['usable'] || ! $screenplayLocations['usable'])
                        <button class="vp-btn sm" disabled title="Cần nhân vật và địa điểm tạo từ đúng bản nội dung phía trên">Tạo phân cảnh</button>
                    @else
                        @php
                            $hasScenes = $screenplay['screenplay'] !== null;
                            $sceneMaxTokens = number_format((int) config('video.screenplay.scenes.max_tokens'), 0, ',', '.');
                            $sceneMinutes = intdiv((int) config('video.screenplay.scenes.timeout_seconds'), 60);
                        @endphp
                        <form method="POST" action="{{ route('video-projects.screenplay', $project->id) }}"
                              id="screenplayForm" data-modal="confirmScreenplay" onsubmit="return vpLockForm(this)">
                            @csrf
                            <input type="hidden" name="foundation_stage_id" value="{{ $screenplayFoundation['stage_id'] }}">
                            <input type="hidden" name="characters_stage_id" value="{{ $screenplayCharacters['stage_id'] }}">
                            <input type="hidden" name="locations_stage_id" value="{{ $screenplayLocations['stage_id'] }}">
                            @if($hasScenes)<input type="hidden" name="force" value="1">@endif
                        </form>
                        <button type="button" class="vp-btn sm pri" data-toggle="modal" data-target="#confirmScreenplay"
                                data-busy="Đang tạo phân cảnh…">{{ $hasScenes ? 'Tạo lại phân cảnh' : 'Tạo phân cảnh' }}</button>
                        @include('modal.confirm_action', [
                            'id' => 'confirmScreenplay',
                            'form' => 'screenplayForm',
                            'content' => 'Gọi '.config('video.screenplay.scenes.model').' (effort '.config('video.screenplay.scenes.effort').') tạo phân cảnh cho nội dung kịch bản rev '.$screenplayFoundation['revision']
                                .' phía trên: chỉ tạo scene, coverage và build state, dùng đúng nhân vật và địa điểm phía trên. Nội dung, nhân vật và địa điểm được giữ nguyên, không viết lại — tác vụ này tính tiền.',
                            'detail' => ($hasScenes ? 'Bỏ qua phân cảnh đã lưu và gọi model. ' : 'Cùng nội dung + cùng bộ luật thì dùng lại bản đã lưu, không gọi model. ')
                                .'Tối đa '.$sceneMaxTokens.' token đầu ra, chờ tối đa '.$sceneMinutes.' phút; chi phí chưa đo, sẽ ghi lại sau lượt đầu.',
                        ])
                    @endif
                </div>
                <div class="va-body">
                    @if($screenplay['error'])
                        <div class="va-lbl" style="color:var(--vp-red);font-weight:400">
                            Lượt tạo phân cảnh gần nhất lỗi: {{ $screenplay['error'] }}
                            @if($screenplay['screenplay'] !== null)
                                <br>Bên dưới là bản thành công gần nhất (rev {{ $screenplay['revision'] }}) — nút Duyệt/Chọn áp vào bản này.
                            @endif
                        </div>
                    @endif
                    @if($screenplay['screenplay'] === null)
                        @if(! $screenplay['error'])
                            <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">Chưa tạo phân cảnh.</div>
                        @endif
                    @else
                        @if($screenplay['selected_stage_id'] !== null && ! $screenplay['selected'])
                            <div class="alert alert-warning">
                                Production vẫn đang dùng một bản phân cảnh cũ đã duyệt. Bản đang hiển thị chưa thay đổi production.
                            </div>
                        @endif
                        @if($screenplay['foundation_stage_id'] !== null && $screenplay['foundation_stage_id'] !== $screenplayFoundation['stage_id'])
                            <div class="alert alert-warning">
                                Phân cảnh này tạo từ nội dung rev {{ $screenplay['foundation_revision'] }}, không phải bản nội dung đang hiển thị phía trên.
                            </div>
                        @endif
                        @if($screenplay['warnings'] !== [])
                            <div class="alert alert-warning">
                                <b>{{ count($screenplay['warnings']) }} cảnh báo biên tập</b>
                                <ul class="mb-0 pl-3">
                                    @foreach($screenplay['warnings'] as $warning)
                                        <li>{{ $warning }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        @php
                            $scenes = $screenplay['screenplay']['scenes'] ?? [];
                            $seconds = round(array_sum(array_column($scenes, 'duration_estimate_ms')) / 1000, 1);
                        @endphp
                        <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">
                            <b>{{ $screenplay['screenplay']['schema_version'] ?? 'bản cũ' }}</b>
                            &middot; rev {{ $screenplay['revision'] }}
                            &middot; {{ count($scenes) }} scene
                            @if($seconds > 0)&middot; {{ $seconds }}s @endif
                            @if($screenplay['foundation_revision'] !== null)&middot; từ nội dung rev {{ $screenplay['foundation_revision'] }} @endif
                            &middot; {{ $screenplay['written_at'] ?? '—' }}
                        </div>
                        <textarea class="va-ta" readonly>{{ \App\Video\Screenplay\ScreenplayText::renderScenes($screenplay['screenplay']) }}</textarea>
                    @endif
                </div>
            </div>
