@extends('layouts.base', ['title' => 'Keyframes'])
@section('title', 'Keyframes')

@section('css')
<link rel="stylesheet" href="{{ asset('assets/css/video-producer.css') }}?v={{ filemtime(public_path('assets/css/video-producer.css')) }}">
@endsection

@section('content')
    @include('video-projects.partials.moto-keyframes-panel')
@endsection

@section('script')
<script src="{{ asset('assets/js/video-producer.js') }}?v={{ filemtime(public_path('assets/js/video-producer.js')) }}"></script>
<script>
(function () {
    var kept = { frames: {}, fields: {}, open: {} };

    function frameOf(box) {
        var ranges = {};

        box.querySelectorAll('[data-frame-range]').forEach(function (range) { ranges[range.dataset.frameRange] = range; });

        if (!ranges.k) { return null; }

        var width = Number(box.dataset.width);
        var height = Number(box.dataset.height);
        var k = Number(ranges.k.value);
        var w = Number(box.dataset.unitX) * k;
        var h = Number(box.dataset.unitY) * k;

        return {
            ranges: ranges,
            image: { width: width, height: height },
            rect: { x: Math.floor((width - w) * Number(ranges.x.value) / 1000), y: Math.floor((height - h) * Number(ranges.y.value) / 1000), width: w, height: h }
        };
    }

    function drawFrame(box) {
        var frame = frameOf(box);

        if (frame === null) { return; }

        var rect = frame.rect;
        var image = frame.image;
        var slackX = image.width - rect.width;
        var slackY = image.height - rect.height;
        var win = box.querySelector('[data-frame-window]');
        var preview = box.querySelector('[data-frame-preview]');

        box.querySelectorAll('[data-frame-field]').forEach(function (field) { field.value = rect[field.dataset.frameField]; });
        frame.ranges.x.disabled = slackX === 0;
        frame.ranges.y.disabled = slackY === 0;
        frame.ranges.k.disabled = box.dataset.kMin === box.dataset.kMax;
        win.style.left = (rect.x / image.width * 100) + '%';
        win.style.top = (rect.y / image.height * 100) + '%';
        win.style.width = (rect.width / image.width * 100) + '%';
        win.style.height = (rect.height / image.height * 100) + '%';
        preview.style.backgroundSize = (image.width / rect.width * 100) + '% ' + (image.height / rect.height * 100) + '%';
        preview.style.backgroundPosition = (slackX === 0 ? 0 : rect.x / slackX * 100) + '% ' + (slackY === 0 ? 0 : rect.y / slackY * 100) + '%';
        box.querySelector('[data-frame-readout]').textContent = rect.width + ' × ' + rect.height + ' tại x ' + rect.x + ', y ' + rect.y + ' → ' + box.dataset.delivery;
    }

    function countReferences(group) {
        var limit = Number(group.dataset.editReferences);
        var chosen = group.querySelectorAll('input:checked').length;
        var note = group.nextElementSibling;

        if (note && note.hasAttribute('data-edit-count')) {
            note.textContent = 'Đang chọn ' + chosen + ' / tối đa ' + limit + ' ảnh tham chiếu' + (chosen > limit ? ' — bỏ bớt mới gửi được' : '');
            note.style.color = chosen > limit ? 'var(--vp-red)' : '';
        }
    }

    function init() {
        document.querySelectorAll('#keyframesPage [data-frame]').forEach(function (box) {
            var saved = kept.frames[box.dataset.frame];

            if (saved) {
                box.querySelectorAll('[data-frame-range]').forEach(function (range) {
                    if (saved[range.dataset.frameRange] !== undefined) { range.value = saved[range.dataset.frameRange]; }
                });
            }

            drawFrame(box);
        });
        document.querySelectorAll('#keyframesPage [data-keep]').forEach(function (field) {
            var saved = kept.fields[field.dataset.keep + '|' + field.name + '|' + (field.type === 'checkbox' ? field.value : '')];

            if (saved === undefined) { return; }
            if (field.type === 'checkbox') { field.checked = saved; } else { field.value = saved; }
        });
        document.querySelectorAll('#keyframesPage [data-keep-open]').forEach(function (details) {
            if (kept.open[details.dataset.keepOpen]) { details.open = true; }
        });
        document.querySelectorAll('#keyframesPage [data-edit-references]').forEach(countReferences);
    }

    document.addEventListener('input', function (event) {
        var range = event.target.closest('[data-frame-range]');
        var field = event.target.closest('[data-keep]');

        if (range) {
            var box = range.closest('[data-frame]');

            kept.frames[box.dataset.frame] = kept.frames[box.dataset.frame] || {};
            kept.frames[box.dataset.frame][range.dataset.frameRange] = range.value;
            drawFrame(box);
        }

        if (field) {
            kept.fields[field.dataset.keep + '|' + field.name + '|' + (field.type === 'checkbox' ? field.value : '')] = field.type === 'checkbox' ? field.checked : field.value;

            var group = field.closest('[data-edit-references]');

            if (group) { countReferences(group); }
        }
    });
    document.addEventListener('toggle', function (event) {
        if (event.target.dataset && event.target.dataset.keepOpen) { kept.open[event.target.dataset.keepOpen] = event.target.open; }
    }, true);
    document.addEventListener('pointerdown', function (event) {
        var win = event.target.closest('[data-frame-window]');

        if (!win) { return; }

        var box = win.closest('[data-frame]');
        var stage = box.querySelector('[data-frame-stage]').getBoundingClientRect();
        var frame = frameOf(box);
        var start = { x: event.clientX, y: event.clientY, px: Number(frame.ranges.x.value), py: Number(frame.ranges.y.value) };
        var slackX = stage.width * (1 - frame.rect.width / frame.image.width);
        var slackY = stage.height * (1 - frame.rect.height / frame.image.height);

        function move(moved) {
            if (slackX > 0) { frame.ranges.x.value = Math.max(0, Math.min(1000, start.px + (moved.clientX - start.x) / slackX * 1000)); }
            if (slackY > 0) { frame.ranges.y.value = Math.max(0, Math.min(1000, start.py + (moved.clientY - start.y) / slackY * 1000)); }

            frame.ranges.x.dispatchEvent(new Event('input', { bubbles: true }));
            frame.ranges.y.dispatchEvent(new Event('input', { bubbles: true }));
        }

        function stop() {
            document.removeEventListener('pointermove', move);
            document.removeEventListener('pointerup', stop);
        }

        event.preventDefault();
        document.addEventListener('pointermove', move);
        document.addEventListener('pointerup', stop);
    });
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-copy-prompt]');

        if (!button) { return; }

        var box = document.getElementById(button.dataset.copyPrompt);

        box.select();
        document.execCommand('copy');
        button.textContent = 'Đã sao chép';
    });
    document.addEventListener('change', function (event) {
        var control = event.target.closest('select[form]');

        if (!control) { return; }

        var formId = control.getAttribute('form');
        var models = document.querySelector('select[form="' + formId + '"][data-keyframe-models]');
        var settings = document.querySelector('form[data-settings-of="' + formId + '"]');

        if (!models || !settings) { return; }

        if (control === models) {
            var entry = JSON.parse(models.dataset.keyframeModels).find(function (model) { return model.model === models.value; });

            if (entry) {
                [['quality', entry.qualities, entry.default_quality], ['size', entry.sizes, entry.default_size]].forEach(function (option) {
                    var target = document.querySelector('select[form="' + formId + '"][name="' + option[0] + '"]');
                    var values = option[1];
                    var keep = values.indexOf(target.value) !== -1 ? target.value : (values.indexOf(option[2]) !== -1 ? option[2] : values[0]);

                    target.innerHTML = '';
                    values.forEach(function (value) {
                        target.add(new Option(option[0] === 'size' ? value.replace('x', ' × ') : value, value, false, value === keep));
                    });

                    if (values.length === 0) { target.add(new Option('—', '', false, true)); }
                });
            }
        }

        ['model', 'quality', 'size'].forEach(function (name) {
            settings.querySelector('[name="' + name + '"]').value = document.querySelector('select[form="' + formId + '"][name="' + name + '"]').value;
        });
        vpSubmitSoon(settings);
    });

    function redrawn() {
        init();
        vpTaskWatch();
    }

    document.addEventListener('keydown', function (event) {
        var held = event.target.closest && event.target.closest('.vp-pending [data-toggle="modal"], .vp-saving [data-toggle="modal"], .vp-unsaved [data-toggle="modal"]');

        if (held && (event.key === 'Enter' || event.key === ' ')) { event.preventDefault(); event.stopPropagation(); }
    }, true);

    init();
    vpTaskWatch({ root: 'keyframesPage', refresh: init });
    vpAjaxPage('keyframesPage', redrawn, redrawn);
})();
</script>
@endsection
