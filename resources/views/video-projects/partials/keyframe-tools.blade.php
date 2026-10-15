@if(! $cell['candidate'])
    @if($kfModelsError !== null || $kfDefault === null)
        <div class="m" style="margin-top:6px;color:var(--vp-red)">{{ __('messages.scene_keyframe_'.($kfModelsError ?? 'media_invalid')) }}</div>
    @else
        <div class="kf-grid">
            <div class="va-field">
                <label>Model</label>
                <select class="ctl" data-row="{{ $s['scene_id'] }}" data-role="kf-model">
                    @foreach($kfModels as $entry)
                        <option value="{{ $entry['id'] }}" @selected($entry['id'] === $kfDefault['id'])>{{ $entry['label'] }}</option>
                    @endforeach
                </select>
            </div>
            @foreach($kfModels as $entry)
                <div data-row="{{ $s['scene_id'] }}" data-kf-group="{{ $entry['id'] }}" @unless($entry['id'] === $kfDefault['id']) hidden @endunless>
                    <div class="va-field">
                        <label>Quality</label>
                        <select class="ctl" data-role="kf-quality" @disabled($entry['id'] !== $kfDefault['id'])>
                            @foreach($entry['controls']['qualities'] as $quality)
                                <option value="{{ $quality }}" data-usd="{{ \App\Enums\ImageQuality::tryFrom($quality)?->estimatedCostUsd() }}"
                                        title="{{ \App\Enums\ImageQuality::tryFrom($quality)?->hint() }}"
                                        @selected($quality === $entry['controls']['default_quality'])>{{ \App\Enums\ImageQuality::tryFrom($quality)?->label() ?? $quality }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="va-field">
                        <label>Size</label>
                        <select class="ctl" data-role="kf-size" @disabled($entry['id'] !== $kfDefault['id'])>
                            @foreach($entry['controls']['sizes'] as $size)
                                <option value="{{ $size }}" @selected($size === $entry['controls']['default_size'])>{{ $size }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            @endforeach
            <button type="button" class="vp-btn pri sm js-kf"
                    data-scene="{{ $s['scene_id'] }}"
                    data-preview="{{ route('video-projects.scene-keyframe-preview', [$id, $s['scene_id']]) }}"
                    data-render="{{ route('video-projects.scene-keyframe-render', [$id, $s['scene_id']]) }}">
                {{ $cell['approved'] ? 'Tạo bản khác' : 'Xem trước → Render' }}
            </button>
            <div class="m kf-wide" data-row="{{ $s['scene_id'] }}" data-role="kf-estimate"></div>
        </div>
    @endif
@endif
