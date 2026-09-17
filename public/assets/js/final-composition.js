/* Man Final Composition: phat cac clip noi duoi nhau, khong cham toi server.
 *
 * Hai the <video> luan phien. Doi `src` tren mot the buoc trinh duyet vut bo bo dem
 * va tai lai tu dau — do chinh la khoang den giua hai canh. O day the dang an luon
 * nap san clip ke, nen luc chuyen chi la doi lop hien thi.
 */

(function () {
    'use strict';

    var root = document.querySelector('.fcomp');

    if (root === null) {
        return;
    }

    var payload = root.querySelector('[data-fcomp-playlist]');
    var decks = Array.prototype.slice.call(root.querySelectorAll('[data-fcomp-deck]'));

    if (payload === null || decks.length < 2) {
        return;
    }

    var clips;

    try {
        clips = JSON.parse(payload.textContent);
    } catch (error) {
        return;
    }

    if (!Array.isArray(clips) || clips.length === 0) {
        return;
    }

    var label = root.querySelector('[data-fcomp-now]');
    var playhead = root.querySelector('.fcomp-playhead');
    var headClock = playhead === null ? null : playhead.querySelector('b');
    var stage = root.querySelector('.fcomp-player');
    var toggle = root.querySelector('[data-fcomp-toggle]');
    var timeText = root.querySelector('[data-fcomp-time]');
    var scrub = root.querySelector('[data-fcomp-scrub]');
    var scrubFill = scrub === null ? null : scrub.querySelector('i');
    var fullscreen = root.querySelector('[data-fcomp-full]');

    var offsets = [];
    var total = 0;

    clips.forEach(function (clip) {
        offsets.push(total);
        total += clip.ms;
    });

    var index = 0;
    var active = 0;

    function clock(ms) {
        var seconds = Math.max(0, Math.round(ms / 1000));

        return String(Math.floor(seconds / 60)).padStart(2, '0') + ':'
            + String(seconds % 60).padStart(2, '0');
    }

    function deck(which) {
        return decks[which];
    }

    function idle() {
        return decks[active === 0 ? 1 : 0];
    }

    function mark(value, on) {
        root.querySelectorAll('[data-fcomp-seek="' + value + '"]').forEach(function (node) {
            node.classList.toggle('playing', on);
        });
    }

    /** Nap mot clip vao mot the, bo qua neu the do DANG giu dung clip ay. */
    function mount(node, at) {
        if (node.dataset.clip === String(at)) {
            return;
        }

        node.dataset.clip = String(at);
        node.src = clips[at].src;

        if (clips[at].poster) {
            node.poster = clips[at].poster;
        }

        node.load();
    }

    function queueNext() {
        if (index + 1 < clips.length) {
            mount(idle(), index + 1);
        }
    }

    function show(which) {
        decks.forEach(function (node, i) {
            node.classList.toggle('on', i === which);
        });

        active = which;
    }

    function start(playing) {
        if (!playing) {
            return;
        }

        var started = deck(active).play();

        // Trinh duyet co the tu choi phat khi chua co tuong tac cua nguoi dung; do
        // la loi hop le, khong phai hong.
        if (started && typeof started.catch === 'function') {
            started.catch(function () {});
        }
    }

    function seekWithin(node, seconds) {
        if (node.readyState >= 1) {
            node.currentTime = seconds;

            return;
        }

        node.addEventListener('loadedmetadata', function once() {
            node.removeEventListener('loadedmetadata', once);
            node.currentTime = seconds;
        });
    }

    /** Dua dau phat toi clip `at`, lech `seconds` tinh tu dau clip do. */
    function go(at, seconds, playing) {
        if (at < 0 || at >= clips.length) {
            return;
        }

        mark(index, false);
        index = at;
        mark(index, true);

        if (label !== null) {
            label.textContent = clips[at].label;
        }

        if (deck(active).dataset.clip !== String(at)) {
            // Clip can toi co the DANG nam san o the kia — doi lop hien thi thay vi
            // nap lai, do la ca diem cua hai the.
            var other = idle();

            if (other.dataset.clip === String(at)) {
                deck(active).pause();
                show(active === 0 ? 1 : 0);
            } else {
                mount(deck(active), at);
            }
        }

        idle().pause();
        seekWithin(deck(active), seconds);
        start(playing);
        queueNext();
        paint();
    }

    function elapsedMs() {
        return offsets[index] + (deck(active).currentTime * 1000);
    }

    function paint() {
        var elapsed = Math.min(elapsedMs(), total);
        var ratio = total === 0 ? 0 : Math.min(Math.max(elapsed / total, 0), 1);

        if (playhead !== null) {
            playhead.style.left = (ratio * 100) + '%';
        }

        if (headClock !== null) {
            headClock.textContent = clock(elapsed);
        }

        if (scrubFill !== null) {
            scrubFill.style.width = (ratio * 100) + '%';
        }

        if (timeText !== null) {
            timeText.textContent = clock(elapsed) + ' / ' + clock(total);
        }
    }

    function paintToggle() {
        if (toggle === null) {
            return;
        }

        var playing = !deck(active).paused && !deck(active).ended;

        toggle.innerHTML = '<i class="fas fa-' + (playing ? 'pause' : 'play') + '"></i>';
        toggle.setAttribute('aria-label', playing ? 'Tạm dừng' : 'Phát');
    }

    decks.forEach(function (node) {
        node.addEventListener('timeupdate', function () {
            if (node === deck(active)) {
                paint();
            }
        });

        node.addEventListener('play', paintToggle);
        node.addEventListener('pause', paintToggle);

        node.addEventListener('ended', function () {
            if (node !== deck(active)) {
                return;
            }

            if (index + 1 < clips.length) {
                go(index + 1, 0, true);

                return;
            }

            mark(index, false);
            paint();
            paintToggle();
        });
    });

    if (toggle !== null) {
        toggle.addEventListener('click', function () {
            if (deck(active).paused) {
                start(true);

                return;
            }

            deck(active).pause();
        });
    }

    if (scrub !== null) {
        scrub.addEventListener('click', function (event) {
            var box = scrub.getBoundingClientRect();

            if (box.width === 0) {
                return;
            }

            var target = Math.min(Math.max((event.clientX - box.left) / box.width, 0), 1) * total;
            var at = 0;

            while (at + 1 < clips.length && offsets[at + 1] <= target) {
                at += 1;
            }

            go(at, (target - offsets[at]) / 1000, !deck(active).paused);
        });
    }

    if (fullscreen !== null && stage !== null) {
        fullscreen.addEventListener('click', function () {
            if (document.fullscreenElement !== null) {
                document.exitFullscreen();

                return;
            }

            if (typeof stage.requestFullscreen === 'function') {
                stage.requestFullscreen();
            }
        });
    }

    root.querySelectorAll('[data-fcomp-seek]').forEach(function (node) {
        node.addEventListener('click', function () {
            go(Number(node.dataset.fcompSeek), 0, true);
        });
    });

    mark(0, true);
    queueNext();
    paint();
})();
