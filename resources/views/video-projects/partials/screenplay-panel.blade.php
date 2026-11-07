            @php
                $storyFirst = (bool) ($designFirst ?? false);
                $storyStep = $storyFirst ? 'story' : 'foundation';
            @endphp
            <div class="vp-panel" id="screenplay-panel" data-ajax-part>
                <div class="va-head">
                    <span class="n">{{ $storyFirst ? 6 : 3 }}</span>
                    <b>KỊCH BẢN PHIM</b>
                    <em>{{ $storyFirst ? 'câu chuyện quanh bản thiết kế đã duyệt — chưa phân cảnh' : 'nội dung — chưa phân cảnh' }}</em>
                    <span class="grow"></span>
                    @if($screenplayFoundation['foundation'] !== null)<span class="va-tag ok">Đã có nội dung</span>@endif
                    @if(! $brief['analysed'])
                        <button class="vp-btn sm" disabled title="Cần brief Haiku trước">Creat screen play</button>
                    @elseif($storyFirst && ($vesselDesign['anchor_selection'] ?? null) === null)
                        <button class="vp-btn sm" disabled title="Cần duyệt ảnh anchor của bản thiết kế tàu trước">Viết nội dung kịch bản</button>
                    @elseif($storyFirst && ($vesselDesign['reference_selection'] ?? null) === null)
                        <a class="vp-btn sm" href="{{ route('video-projects.reference', $project->id) }}#referenceLockPanel"
                           title="Cần duyệt và chốt bộ ảnh Reference trước khi viết kịch bản">Chốt bộ Reference trước →</a>
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
                                    ? 'Gọi '.$screenplayModel.' viết câu chuyện quanh bản thiết kế tàu rev '.($vesselDesign['anchor_selection']['design_revision'] ?? '—').' đã duyệt ảnh: tiền đề, tóm tắt, diễn biến qua năm giai đoạn và kết thúc. Thiết kế giữ nguyên, nhân vật tàu tự đóng gói từ bản thiết kế. Bước này chưa tạo scene — tác vụ này tính tiền.'
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
                        $lockedNow = $vesselDesign['anchor_selection'] ?? null;
                    @endphp
                    @if($storyFirst && is_array($builtOn) && is_array($lockedNow) && ($builtOn['artifact_id'] ?? null) !== ($lockedNow['artifact_id'] ?? null))
                        <div class="alert alert-warning">
                            Bản nội dung này viết trên một ảnh anchor / bản thiết kế khác nguồn đang chọn (thiết kế rev {{ $lockedNow['design_revision'] ?? '—' }}). Viết lại nội dung kịch bản để theo nguồn mới; các bước sau vẫn giữ nguồn cũ của chúng.
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
            </div>
