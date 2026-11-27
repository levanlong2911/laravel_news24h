@extends('layouts.base', ['title' => 'Render video'])
@section('title', 'Render video')

@section('css')
<link rel="stylesheet" href="{{ asset('assets/css/video-producer.css') }}?v={{ filemtime(public_path('assets/css/video-producer.css')) }}">
@endsection

@section('content')
    @include('video-projects.partials.moto-clips-panel')
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
        box.querySelector('[data-frame-readout]').textContent = 'Khung kết thúc ' + rect.width + ' × ' + rect.height + ' tại x ' + rect.x + ', y ' + rect.y + ' → ' + box.dataset.delivery;
    }

    function unsaved(element) {
        var part = element.closest('[data-ajax-part]');

        if (part !== null) { part.classList.add('vp-unsaved'); }
    }

    function loadStrips() {
        document.querySelectorAll('#clipsPage [data-clip-strip]:not([data-loaded])').forEach(function (holder) {
            var details = holder.closest('details');

            if (holder.hasAttribute('data-lazy') && details !== null && !details.open) { return; }

            holder.setAttribute('data-loaded', '1');
            fetch(holder.dataset.clipStrip, { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
                .then(function (response) {
                    if (!response.ok) { throw new Error('HTTP ' + response.status); }

                    return response.text();
                })
                .then(function (html) {
                    var review = holder.closest('[data-clip-review]');
                    var strip;

                    holder.innerHTML = html;
                    strip = holder.querySelector('[data-review-key]');

                    if (strip !== null && review !== null) {
                        review.querySelectorAll('input[name="review_key"]').forEach(function (field) { field.value = strip.dataset.reviewKey; });
                    }
                })
                .catch(function (error) {
                    holder.textContent = 'Không tải được dải ảnh duyệt (' + error.message + ') — mở lại trang để thử lại.';
                });
        });
    }

    function init() {
        document.querySelectorAll('#clipsPage [data-frame]').forEach(function (box) {
            var saved = kept.frames[box.dataset.frame];

            if (saved) {
                var endMark = box.closest('form') ? box.closest('form').querySelector('input[name="crop_at_ms"]') : null;

                box.querySelectorAll('[data-frame-range]').forEach(function (range) {
                    if (saved[range.dataset.frameRange] !== undefined) { range.value = saved[range.dataset.frameRange]; }
                });
                if (endMark !== null && saved.endMark !== undefined) { endMark.value = saved.endMark; }
                unsaved(box);
            }

            drawFrame(box);
        });
        document.querySelectorAll('#clipsPage [data-keep]').forEach(function (field) {
            var saved = kept.fields[field.dataset.keep + '|' + field.name + '|' + (field.type === 'checkbox' ? field.value : '')];

            if (saved === undefined) { return; }
            if (field.type === 'checkbox') { field.checked = saved; } else { field.value = saved; }
        });
        document.querySelectorAll('#clipsPage [data-keep-open]').forEach(function (details) {
            if (kept.open[details.dataset.keepOpen]) { details.open = true; }
        });
        loadStrips();
    }

    document.addEventListener('input', function (event) {
        var range = event.target.closest('#clipsPage [data-frame-range]');
        var field = event.target.closest('#clipsPage [data-keep]');
        var mark = event.target.closest('#clipsPage [data-clip-crop] input[name="crop_at_ms"]');

        if (range) {
            var box = range.closest('[data-frame]');

            kept.frames[box.dataset.frame] = kept.frames[box.dataset.frame] || {};
            kept.frames[box.dataset.frame][range.dataset.frameRange] = range.value;
            drawFrame(box);
            unsaved(box);
        }

        if (mark) {
            var owner = mark.form ? mark.form.querySelector('[data-frame]') : null;

            if (owner !== null) {
                kept.frames[owner.dataset.frame] = kept.frames[owner.dataset.frame] || {};
                kept.frames[owner.dataset.frame].endMark = mark.value;
            }

            unsaved(mark);
        }

        if (field) {
            kept.fields[field.dataset.keep + '|' + field.name + '|' + (field.type === 'checkbox' ? field.value : '')] = field.type === 'checkbox' ? field.checked : field.value;
        }
    });

    document.addEventListener('change', function (event) {
        var control = event.target.closest('#clipsPage [data-clip-settings] select, #clipsPage [data-clip-settings] input[type="checkbox"]');

        if (control) { vpSubmitSoon(control.form); }
    });

    document.addEventListener('toggle', function (event) {
        if (!event.target.dataset) { return; }
        if (event.target.dataset.keepOpen) { kept.open[event.target.dataset.keepOpen] = event.target.open; }
        if (event.target.open) { loadStrips(); }
    }, true);

    document.addEventListener('submit', function (event) {
        var form = event.target;
        var part = form instanceof HTMLFormElement ? form.closest('#clipsPage [data-ajax-part]') : null;

        if (part === null) { return; }

        if (form.id && /^cp(Render|Prompt)/.test(form.id) && part.matches('.vp-pending, .vp-saving, .vp-unsaved')) {
            event.preventDefault();
            event.stopImmediatePropagation();
            if (window.toastr) { window.toastr.error('Thiết lập của shot chưa lưu xong — chờ lưu (hoặc bấm Lưu đường crop) rồi mới render hoặc viết prompt.'); }

            return;
        }

        if (form.hasAttribute('data-clip-crop')) {
            form.vpKept = {};
            form.querySelectorAll('[data-frame]').forEach(function (box) { form.vpKept[box.dataset.frame] = JSON.stringify(kept.frames[box.dataset.frame] || null); });
        }
    }, true);

    document.addEventListener('pointerdown', function (event) {
        var win = event.target.closest('#clipsPage [data-frame-window]');

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
        var still = event.target.closest('#clipsPage [data-seek-ms]');
        var mark = event.target.closest('#clipsPage [data-mark-now]');
        var review = (still || mark) ? (still || mark).closest('[data-clip-review]') : null;
        var player = review ? review.querySelector('[data-clip-player]') : null;

        if (still && player) {
            player.pause();
            player.currentTime = Number(still.dataset.seekMs) / 1000;
            player.scrollIntoView({ block: 'nearest' });

            return;
        }

        if (mark && player) {
            var input = mark.parentNode.querySelector('input[name="marks"]');
            var ms = String(Math.round(player.currentTime * 1000));

            input.value = input.value.trim() === '' ? ms : input.value.trim().replace(/[,\s]+$/, '') + ', ' + ms;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
    });

    document.addEventListener('keydown', function (event) {
        var held = event.target.closest && event.target.closest('.vp-pending [data-toggle="modal"], .vp-saving [data-toggle="modal"], .vp-unsaved [data-toggle="modal"]');

        if (held && (event.key === 'Enter' || event.key === ' ')) { event.preventDefault(); event.stopPropagation(); }
    }, true);

    function redrawn(parts, form, done) {
        if (done && form && form.vpKept) {
            Object.keys(form.vpKept).forEach(function (frame) {
                if (JSON.stringify(kept.frames[frame] || null) === form.vpKept[frame]) { delete kept.frames[frame]; }
            });
        }

        init();
        vpTaskWatch();
    }

    init();
    vpTaskWatch({ root: 'clipsPage', refresh: init });
    vpAjaxPage('clipsPage', redrawn, redrawn);
})();
</script>
@endsection
