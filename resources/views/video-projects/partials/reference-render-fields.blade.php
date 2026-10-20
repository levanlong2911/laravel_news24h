<div class="va-field">
    <label>Environment</label>
    <select class="ctl" name="environment" form="{{ $form }}" data-role="environment" required @disabled($disabled)>
        @foreach(\App\Video\Reference\ReferenceEnvironment::cases() as $environment)
            <option value="{{ $environment->value }}" @selected($environment->value === $defaultEnvironment)>{{ $environment->label() }}</option>
        @endforeach
    </select>
</div>
<div class="va-field">
    <label>Model</label>
    <select class="ctl" name="model" form="{{ $form }}" data-role="model" required @disabled($disabled)>
        <option value="">Choose model</option>
        @foreach(\App\Enums\ImageModel::cases() as $model)
            <option value="{{ $model->value }}">{{ $model->label() }}</option>
        @endforeach
    </select>
</div>
<div class="va-field">
    <label>Quality</label>
    <select class="ctl" name="quality" form="{{ $form }}" data-role="quality" required @disabled($disabled)>
        <option value="">Choose quality</option>
        @foreach(\App\Enums\ImageQuality::cases() as $quality)
            <option value="{{ $quality->value }}" title="{{ $quality->hint() }}"
                    data-models="{{ collect(\App\Enums\ImageModel::cases())->filter(fn ($m) => $m->supports($quality))->map(fn ($m) => $m->value)->implode(' ') }}">{{ $quality->label() }}</option>
        @endforeach
    </select>
</div>
<div class="va-field">
    <label>Size</label>
    <select class="ctl" name="size" form="{{ $form }}" required @disabled($disabled)>
        <option value="">Choose size</option>
        @foreach(\App\Enums\ImageSize::cases() as $size)
            <option value="{{ $size->value }}" @selected($size->value === $defaultSize)>{{ $size->label() }}</option>
        @endforeach
    </select>
</div>
<div class="va-field">
    <label>Variations</label>
    <select class="ctl" name="variations" form="{{ $form }}" required @disabled($disabled)>
        @foreach(\App\Enums\ImageVariations::cases() as $variation)
            @continue($onlyOne && $variation !== \App\Enums\ImageVariations::ONE)
            <option value="{{ $variation->value }}">{{ $variation->label() }}</option>
        @endforeach
    </select>
</div>
