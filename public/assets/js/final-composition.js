/* Man Final Composition: phat cac clip noi duoi nhau, khong cham toi server.
 *
 * Hai the <video> luan phien. Doi `src` tren mot the buoc trinh duyet vut bo bo dem
 * va tai lai tu dau — do chinh la khoang den giua hai canh. O day the dang an luon
 * nap san clip ke, nen luc chuyen chi la doi lop hien thi.
 */

(function () {
    'use strict';

    function clockOf(ms) {
        var seconds = Math.max(0, Math.round(ms / 1000));

        return String(Math.floor(seconds / 60)).padStart(2, '0') + ':'
            + String(seconds % 60).padStart(2, '0');
    }

    /**
     * Ve theo nhip MAN HINH, khong theo `timeupdate`.
     *
     * `timeupdate` chi ban khoang bon lan moi giay, nen playhead nhay tung nac ~250ms
     * — do la cai giat nhin thay duoc. `requestAnimationFrame` cho 60 khung/giay va
     * dung han khi video dung, nen khong dot CPU luc dang nghi.
     *
     * Va KHONG dat `transition` len playhead: transition 0,12s chay xong roi dung
     * cho nac sau, hai co che cung dieu khien mot thuoc tinh se danh nhau.
     *
     * @param {Array} videos  moi the co the dang phat
     * @param {Function} paint
     */
    function follow(videos, paint) {
        var frame = null;

        function tick() {
            paint();
            frame = requestAnimationFrame(tick);
        }

        function start() {
            if (frame === null) {
                tick();
            }
        }

        function stop() {
            if (frame !== null) {
                cancelAnimationFrame(frame);
                frame = null;
            }

            paint();
        }

        videos.forEach(function (video) {
            video.addEventListener('play', start);
            video.addEventListener('playing', start);
            video.addEventListener('pause', stop);
            video.addEventListener('ended', stop);
            video.addEventListener('seeked', paint);
            video.addEventListener('loadedmetadata', paint);
        });
    }

    /**
     * Dat playhead bang `transform` chu khong bang `left`.
     *
     * `left` theo phan tram bat trinh duyet tinh lai bo cuc moi khung; `transform`
     * chi cham toi tang hop thanh. Va dong ho chi ghi khi GIAY doi — ghi
     * `textContent` 60 lan mot giay cho mot chuoi khong doi la 59 lan thua.
     */
    function mover(playhead, lanes) {
        var clock = playhead.querySelector('b');
        var shown = null;

        // Do be ngang MOT LAN, do lai khi cua so doi.
        //
        // `clientWidth` bat trinh duyet tinh lai bo cuc de tra loi. Doc no trong moi
        // khung roi ghi `transform` ngay sau la giang co bo cuc 60 lan mot giay —
        // dung cai lam no giat.
        var width = lanes.clientWidth;

        window.addEventListener('resize', function () {
            width = lanes.clientWidth;
        });

        return function (ratio, ms) {
            playhead.style.transform = 'translateX(' + (ratio * width) + 'px)';

            var text = clockOf(ms);

            if (clock !== null && text !== shown) {
                clock.textContent = text;
                shown = text;
            }
        };
    }

    /**
     * Thoi diem phat, NOI SUY giua hai khung video.
     *
     * `video.currentTime` chi nhay theo khung hinh — video 24fps thi no doi 24 lan
     * mot giay, du ta ve 60 lan. Doc tho la playhead di 24 nac.
     *
     * Giua hai lan `currentTime` doi, suy ra vi tri bang dong ho tuong. Chan tren
     * 0,1 giay de neu video khung lai thi dau phat khong chay vuot roi giat nguoc.
     */
    function interpolator(video) {
        var seen = -1;
        var base = 0;
        var wall = 0;

        return function () {
            var now = video.currentTime;

            if (now !== seen) {
                seen = now;
                base = now;
                wall = performance.now();

                return now;
            }

            if (video.paused || video.ended) {
                return now;
            }

            var drift = (performance.now() - wall) / 1000 * (video.playbackRate || 1);

            return Math.min(base + Math.min(drift, 0.1), video.duration || base + drift);
        };
    }

    /**
     * Ban final: mot the <video>, playhead bam theo chinh no.
     *
     * Moc de nhay toi lay tu `data-fcomp-at` — do la `start_ms` cua clip TRONG BAN
     * FINAL, doc tu `video_final_renders`. Khong suy ra tu do dai clip cong don:
     * ban final co chuyen canh mo nen ngan hon tong do dai, va hai con so do lech
     * nhau dung bang tong phan chong lan.
     */
    function single(root) {
        var video = root.querySelector('[data-fcomp-final]');
        var playhead = root.querySelector('.fcomp-playhead');
        var lanes = root.querySelector('.fcomp-lanes');

        if (video === null || playhead === null || lanes === null) {
            return;
        }

        var move = mover(playhead, lanes);
        var at = interpolator(video);

        function paint() {
            var total = video.duration;

            if (!isFinite(total) || total <= 0) {
                return;
            }

            var seconds = at();

            move(Math.min(Math.max(seconds / total, 0), 1), seconds * 1000);
        }

        follow([video], paint);

        root.querySelectorAll('[data-fcomp-at]').forEach(function (node) {
            node.classList.add('seekable');

            node.addEventListener('click', function () {
                var at = Number(node.dataset.fcompAt) / 1000;

                if (isFinite(at)) {
                    video.currentTime = at;

                    var started = video.play();

                    if (started && typeof started.catch === 'function') {
                        started.catch(function () {});
                    }
                }
            });
        });

        paint();
    }

    /**
     * Ti le khung hinh di theo do phan giai.
     *
     * O ti le la thu SUY RA, khong phai lua chon rieng — nhung neu no dung im khi o
     * do phan giai doi thi no dang noi sai, va do con te hon khong hien gi.
     */
    function mirrorRatio(root) {
        var size = root.querySelector('[data-fcomp-size]');
        var ratio = root.querySelector('[data-fcomp-ratio] option');

        if (size === null || ratio === null) {
            return;
        }

        size.addEventListener('change', function () {
            var chosen = size.options[size.selectedIndex];

            if (chosen && chosen.dataset.ratio) {
                ratio.textContent = chosen.dataset.ratio;
            }
        });
    }

    var root = document.querySelector('.fcomp');

    if (root === null) {
        return;
    }

    mirrorRatio(root);

    var payload = root.querySelector('[data-fcomp-playlist]');
    var decks = Array.prototype.slice.call(root.querySelectorAll('[data-fcomp-deck]'));

    if (payload === null || decks.length < 2) {
        // Da co ban final: mot the <video> duy nhat, khong luan phien. Playhead van
        // phai chay — truoc day ca khoi nay thoat o day, va timeline dung im trong
        // khi video dang phat.
        single(root);

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
    var lanes = root.querySelector('.fcomp-lanes');
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

    // Mot bo noi suy cho MOI the: chung khong cung mot dong thoi gian, va dung chung
    // mot bo thi luc doi the se ra mot buoc nhay gia.
    var clocks = decks.map(interpolator);

    function elapsedMs() {
        return offsets[index] + (clocks[active]() * 1000);
    }

    var move = playhead !== null && lanes !== null ? mover(playhead, lanes) : null;
    var shownTime = null;

    function paint() {
        var elapsed = Math.min(elapsedMs(), total);
        var ratio = total === 0 ? 0 : Math.min(Math.max(elapsed / total, 0), 1);

        if (move !== null) {
            move(ratio, elapsed);
        }

        if (scrubFill !== null) {
            // `scaleX` thay vi `width`: cung ly do voi playhead — doi `width` la mot
            // lan tinh lai bo cuc, doi `transform` thi khong.
            scrubFill.style.transform = 'scaleX(' + ratio + ')';
        }

        var text = clockOf(elapsed) + ' / ' + clockOf(total);

        if (timeText !== null && text !== shownTime) {
            timeText.textContent = text;
            shownTime = text;
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

    // Mot vong `requestAnimationFrame` chung cho ca hai the: chi the dang hien moi
    // phat, va `paint()` von doc thang tu the do.
    follow(decks, paint);

    decks.forEach(function (node) {
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
